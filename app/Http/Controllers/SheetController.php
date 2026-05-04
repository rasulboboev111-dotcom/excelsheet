<?php

namespace App\Http\Controllers;

use App\Models\Sheet;
use App\Models\SheetAuditLog;
use App\Models\SheetData;
use App\Services\GmailMailerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class SheetController extends Controller
{
    private const MAX_IMPORT_ROWS = 100000;
    private const MAX_IMPORT_COLS = 1000;
    private const MAX_UPDATE_ROWS = 50000;
    private const MAX_ROW_INDEX = 1048576;
    // Ограничение на количество diff-записей, которые держим в памяти ПЕРЕД срезом для лога.
    // Реальный счётчик cells_changed считается отдельно и не зависит от лимита.
    private const AUDIT_SAMPLE_LIMIT = 1000;
    // Смещение для двухшагового сдвига row_index в insert/deleteRow. Должно быть строго
    // БОЛЬШЕ MAX_ROW_INDEX, чтобы временные значения гарантированно не пересекались
    // с реальными. UNIQUE-проверка проходит, потому что промежуточные строки находятся
    // в недостижимом диапазоне.
    private const SHIFT_OFFSET = 100000000; // ~95× больше MAX_ROW_INDEX

    /**
     * Уникальный 64-битный ключ для pg_advisory_xact_lock — сериализует выдачу
     * нового sheets.order между параллельными импортами/созданиями. Лок снимается
     * автоматически в конце транзакции (commit/rollback).
     *
     * Ключ должен быть стабильным и единым во всём приложении. Берём отрезок md5
     * имени домена ('sheets_order_advisory_lock') и приводим к bigint.
     */
    // 32-bit safe ключ (помещается в PHP_INT_MAX на 32-bit PHP). Сериализует
    // одновременные импорты/создания листов, чтобы они не получили один order.
    // Любое стабильное 32-bit положительное число подойдёт — здесь crc32-производное.
    private const SHEETS_ORDER_LOCK_KEY = 645908789; // crc32('sheets_order_lock') — fits in int32

    private static function lockSheetsOrder(): void
    {
        DB::statement('SELECT pg_advisory_xact_lock(?)', [self::SHEETS_ORDER_LOCK_KEY]);
    }

    /**
     * Приводит значение ячейки к каноничному виду для сравнения «было/стало».
     * Решает кейс: БД отдала число `5`, фронт прислал строку `"5"` — раньше это
     * было «изменением», сейчас оба нормализуются к 5.0 и diff не сработает.
     * Правила:
     *   null / '' / "   " → null (всё это «пусто»)
     *   "5", "5.0", " 5 " → 5.0 (число)
     *   "true"/"false"    → bool
     *   формула '=...'    → строка как есть
     *   прочее            → исходное значение
     */
    private function normalizeCellValue($v)
    {
        if ($v === null) return null;
        if (is_bool($v)) return $v;
        if (is_int($v) || is_float($v)) return (float) $v;
        if (is_string($v)) {
            $t = trim($v);
            if ($t === '') return null;
            if ($t[0] === '=') return $t; // формула
            // Целое или десятичное число (с опциональным знаком, без exp).
            if (preg_match('/^-?\d+(?:[.,]\d+)?$/', $t)) {
                return (float) str_replace(',', '.', $t);
            }
            return $t;
        }
        return $v;
    }

    /**
     * Записывает событие в журнал аудита. Используется во всех мутациях:
     * cell_edit / sheet_created / sheet_renamed / sheet_deleted / sheet_imported /
     * row_inserted / row_deleted. Сбои логирования НЕ должны валить основную операцию.
     */
    private function logAudit(string $action, ?int $sheetId, ?array $details = null): void
    {
        try {
            SheetAuditLog::create([
                'user_id' => Auth::id(),
                'sheet_id' => $sheetId,
                'action' => $action,
                'details' => $details,
                'ip' => request()->ip(),
            ]);
        } catch (\Throwable $e) {
            // Логируем в основной лог приложения, но не прерываем запрос.
            \Log::warning('Audit log write failed: ' . $e->getMessage(), ['action' => $action]);
        }
    }

    private function authorizeView(Sheet $sheet): void
    {
        if (!$sheet->canView(Auth::id())) {
            throw new AuthorizationException('No access to this sheet.');
        }
    }

    private function authorizeEdit(Sheet $sheet): void
    {
        if (!$sheet->canEdit(Auth::id())) {
            throw new AuthorizationException('No edit access to this sheet.');
        }
    }

    private function authorizeOwner(Sheet $sheet): void
    {
        if (!$sheet->isOwnedBy(Auth::id())) {
            throw new AuthorizationException('Only the sheet owner can perform this action.');
        }
    }

    private function authorizeAdmin(): void
    {
        $user = Auth::user();
        if (!$user || !Sheet::userIsAdmin($user)) {
            throw new AuthorizationException('Admin only.');
        }
    }

    public function index(Request $request)
    {
        $userId = Auth::id();
        $authUser = Auth::user();
        $isAdmin = $authUser ? Sheet::userIsAdmin($authUser) : false;

        // Админ видит все листы; обычный юзер — свои (owner) + те, где ему дали роль.
        // Per-sheet роли хранятся в model_has_roles с team_id = sheet_id.
        if ($isAdmin) {
            $sheets = Sheet::with('owner:id,name,email')->orderBy('order')->get();
        } else {
            $tableNames  = config('permission.table_names');
            $columnNames = config('permission.column_names');
            $teamFk      = $columnNames['team_foreign_key'] ?? 'team_id';
            $modelKey    = $columnNames['model_morph_key']  ?? 'model_id';

            $assignedSheetIds = \DB::table($tableNames['model_has_roles'])
                ->where($modelKey, $userId)
                ->where('model_type', \App\Models\User::class)
                ->whereNotNull($teamFk)
                ->pluck($teamFk);

            $sheets = Sheet::with('owner:id,name,email')
                ->where(function ($q) use ($userId, $assignedSheetIds) {
                    $q->where('user_id', $userId)
                      ->orWhereIn('id', $assignedSheetIds);
                })
                ->orderBy('order')->get();
        }

        $activeSheetId = $request->input('sheet_id', $sheets->first()?->id);

        $activeSheet = null;
        $sheetData = [];
        $canEditActive = false;
        $canViewActive = false;

        if ($activeSheetId) {
            $activeSheet = Sheet::with('owner:id,name,email')->find($activeSheetId);
            if ($activeSheet && $activeSheet->canView($userId)) {
                $canViewActive = true;
                $canEditActive = $activeSheet->canEdit($userId);
                $sheetData = SheetData::where('sheet_id', $activeSheetId)
                    ->orderBy('row_index')
                    ->get()
                    ->map(function ($row) {
                        return array_merge(['id' => $row->id], $row->row_data);
                    });
            } else {
                // У юзера нет доступа к запрошенному листу — обнуляем activeSheet,
                // фронт покажет первый доступный из списка (или пусто).
                $activeSheet = null;
            }
        }

        return Inertia::render('Dashboard', [
            'sheets'      => $sheets,
            'activeSheet' => $activeSheet,
            'initialData' => $sheetData,
            'canEdit'     => $canEditActive,
            'canView'     => $canViewActive,
            'isAdmin'     => $isAdmin,
        ]);
    }

    /**
     * GET /sheets/{sheet}/data — отдаёт rowData листа в JSON.
     * Нужен для экспорта в .xlsx нескольких листов: фронт держит в памяти только активный,
     * остальные дозагружает по этому endpoint'у.
     */
    public function fetchData(Sheet $sheet)
    {
        $userId = Auth::id();
        if (!$sheet->canView($userId)) {
            throw new AuthorizationException('No view access to this sheet.');
        }

        $rows = SheetData::where('sheet_id', $sheet->id)
            ->orderBy('row_index')
            ->get()
            ->map(fn ($r) => array_merge(['id' => $r->id], $r->row_data));

        return response()->json([
            'sheet_id' => $sheet->id,
            'name'     => $sheet->name,
            'columns'  => $sheet->columns ?? [],
            'rows'     => $rows,
        ]);
    }

    public function updateData(Request $request, Sheet $sheet)
    {
        $this->authorizeEdit($sheet);

        $payload = $request->validate([
            'rows'              => 'required|array|max:' . self::MAX_UPDATE_ROWS,
            'rows.*.row_index'  => 'required|integer|min:0|max:' . self::MAX_ROW_INDEX,
            'rows.*.data'       => 'required|array|max:' . self::MAX_IMPORT_COLS,
            // Комментарий-обоснование для не-админа при ИЗМЕНЕНИИ существующих
            // ячеек (см. валидацию ниже после расчёта diff). Для пустых→значение
            // (добавление) и для админа — необязателен.
            'comment'           => 'nullable|string|max:2000',
        ]);

        // Карта field → headerName (имя колонки, которое видит пользователь)
        $columnsMap = collect($sheet->columns ?? [])
            ->mapWithKeys(fn ($c) => [($c['field'] ?? '') => ($c['headerName'] ?? ($c['field'] ?? ''))])
            ->all();

        // Чтобы записать «было → стало», читаем старое содержимое затронутых строк
        // ОДНИМ запросом, потом сравниваем поле за полем.
        $rowIndexes = array_map(fn ($r) => (int) $r['row_index'], $payload['rows']);
        $existingByIdx = SheetData::where('sheet_id', $sheet->id)
            ->whereIn('row_index', $rowIndexes)
            ->get()
            ->keyBy('row_index');

        // Diff-аккумуляторы: total — реальный счётчик (всё, что изменилось),
        // sample — capped массив для лога (чтобы при 50k правок не сожрать всю память).
        $totalChanges = 0;
        // Отдельно считаем «модификации» — правки НЕ-пустых старых значений.
        // Заполнение пустой ячейки = «добавление», комментарий не требуется.
        // Изменение/очистка непустой ячейки = «модификация», комментарий обязателен
        // для не-админа (валидируется после цикла).
        $modificationCount = 0;
        $changes = []; // [{ row, col, col_name, old, new }, …]
        $sampleLimit = self::AUDIT_SAMPLE_LIMIT;

        // Соберём пачку для upsert — один SQL вместо N updateOrCreate.
        $upsertRows = [];
        $now = now();

        foreach ($payload['rows'] as $row) {
            $rowIndex = (int) $row['row_index'];

            // Sanitize row_data: ensure all values are scalar or null (no nested objects/arrays
            // that could carry unexpected payloads). _style entries are arrays — allow one level.
            $clean = [];
            foreach (($row['data'] ?? []) as $key => $value) {
                if (!is_string($key) || strlen($key) > 64) continue;
                if (is_scalar($value) || $value === null) {
                    $clean[$key] = $value;
                } elseif (is_array($value)) {
                    $sub = [];
                    foreach ($value as $sk => $sv) {
                        if (is_string($sk) && (is_scalar($sv) || $sv === null)) {
                            $sub[$sk] = $sv;
                        }
                    }
                    $clean[$key] = $sub;
                }
            }

            // Diff: только value-поля (не *_style), которые поменялись.
            $oldData = $existingByIdx->get($rowIndex)?->row_data ?? [];
            // Объединяем ключи old+new чтобы поймать и удалённые поля.
            $allKeys = array_unique(array_merge(array_keys($oldData), array_keys($clean)));
            foreach ($allKeys as $field) {
                if (str_ends_with($field, '_style')) continue; // стили — не «значения», в журнал не пишем
                $oldVal = $oldData[$field] ?? null;
                $newVal = $clean[$field] ?? null;
                // Нормализуем перед сравнением: "5" === 5, "" === null и т.п.
                $normOld = $this->normalizeCellValue($oldVal);
                $normNew = $this->normalizeCellValue($newVal);
                if ($normOld === $normNew) continue;
                $totalChanges++;
                // Старое значение НЕ пустое → это модификация (правка/удаление существующих
                // данных), а не чистое добавление. Триггерит требование комментария.
                if ($normOld !== null) $modificationCount++;
                if (count($changes) < $sampleLimit) {
                    $changes[] = [
                        'row'      => $rowIndex + 1, // человеко-читаемая нумерация с 1
                        'col'      => $field,
                        'col_name' => $columnsMap[$field] ?? $field,
                        'old'      => $oldVal,
                        'new'      => $newVal,
                    ];
                }
            }

            // upsert работает в обход Eloquent: cast 'array' на row_data НЕ применяется,
            // нужен явный json_encode. Также upsert не ставит created_at/updated_at сам.
            $upsertRows[] = [
                'sheet_id'   => $sheet->id,
                'row_index'  => $rowIndex,
                'row_data'   => json_encode($clean, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Не-админ обязан указать причину при ИЗМЕНЕНИИ существующих данных.
        // Чистые добавления (пустая ячейка → значение) комментария не требуют.
        // Этот guard срабатывает ДО upsert: правка не уходит в БД, фронт получит 422.
        $comment = trim((string) ($payload['comment'] ?? ''));
        if ($modificationCount > 0 && $comment === '' && !Sheet::userIsAdmin(Auth::user())) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'comment' => 'Укажите причину изменения существующих данных.',
            ]);
        }

        if (!empty($upsertRows)) {
            // Дедуп по (sheet_id, row_index). PG ON CONFLICT DO UPDATE не умеет
            // обработать два INSERT-а с одним конфликт-ключом в одном statement —
            // падает с cardinality violation (21000). Если фронт прислал дубли
            // (например, при реактивных заменах ссылок в очереди sync'а), оставляем
            // последнюю запись — она самая свежая по порядку поступления.
            $deduped = [];
            foreach ($upsertRows as $r) {
                $deduped[$r['sheet_id'] . ':' . $r['row_index']] = $r;
            }
            $upsertRows = array_values($deduped);

            // Один SQL вместо N. Conflict-key совпадает с UNIQUE(sheet_id, row_index).
            // На вставке заполняются created_at/updated_at; на конфликте — обновляются row_data
            // и updated_at (created_at не трогаем).
            SheetData::upsert(
                $upsertRows,
                ['sheet_id', 'row_index'],
                ['row_data', 'updated_at']
            );
        }

        // Лог пишем ТОЛЬКО если реально менялось хоть одно значение.
        // Стилевые правки (жирный/цвет) в журнал не попадают — это шум.
        if ($totalChanges > 0) {
            $details = [
                'cells_changed' => $totalChanges,                  // реальное число (не из обрезанного sample)
                'sample'        => array_slice($changes, 0, 20),    // первые 20 для UI журнала
            ];
            if ($comment !== '') $details['comment'] = $comment;
            $this->logAudit('cell_edit', $sheet->id, $details);
        }

        return back();
    }

    public function store(Request $request)
    {
        // Создавать листы может только админ.
        $this->authorizeAdmin();

        // Транзакция + advisory-lock — иначе при одновременном создании
        // двумя пользователями оба получат одинаковый order.
        // PostgreSQL не поддерживает FOR UPDATE с агрегатами (MAX),
        // поэтому используем pg_advisory_xact_lock — снимается на коммите/rollback.
        $sheet = DB::transaction(function () {
            self::lockSheetsOrder();
            $maxOrder = (int) Sheet::max('order');
            return Sheet::create([
                'name' => 'Новый лист',
                'user_id' => Auth::id(),
                'order' => $maxOrder + 1,
                'columns' => [
                    ['field' => 'A', 'headerName' => 'A'],
                    ['field' => 'B', 'headerName' => 'B'],
                    ['field' => 'C', 'headerName' => 'C'],
                ]
            ]);
        });
        $this->logAudit('sheet_created', $sheet->id, ['name' => $sheet->name]);
        return redirect()->route('dashboard', ['sheet_id' => $sheet->id]);
    }

    /**
     * JSON-эндпоинт для импорта одного листа из XLSX.
     * Принимает {name, columns, rows[{row_index, data}]} и возвращает JSON
     * с id/name/columns созданного листа — позволяет фронту атомарно создать
     * лист с данными и сразу записать его мету в localStorage.
     */
    public function importSheet(Request $request)
    {
        // Импорт открыт всем залогиненным юзерам. Импортёр становится owner
        // (user_id = Auth::id()) и автоматически получает редактирование на свой
        // лист через Sheet::canEdit() (проверка userRole === 'owner').
        // Делать его видимым/редактируемым для других юзеров — задача админа.

        $payload = $request->validate([
            'name'             => 'required|string|max:255',
            'columns'          => 'sometimes|array|max:' . self::MAX_IMPORT_COLS,
            'columns.*.field'  => 'required_with:columns|string|max:32',
            'rows'             => 'sometimes|array|max:' . self::MAX_IMPORT_ROWS,
            'rows.*.row_index' => 'required_with:rows|integer|min:0|max:' . self::MAX_ROW_INDEX,
            'rows.*.data'      => 'sometimes|array|max:' . self::MAX_IMPORT_COLS,
        ]);

        $columns = $payload['columns'] ?? [];
        if (empty($columns)) {
            $columns = [
                ['field' => 'A', 'headerName' => 'A'],
                ['field' => 'B', 'headerName' => 'B'],
                ['field' => 'C', 'headerName' => 'C'],
            ];
        }

        $sheet = DB::transaction(function () use ($payload, $columns) {
            self::lockSheetsOrder();
            $maxOrder = (int) Sheet::max('order');
            $newSheet = Sheet::create([
                'name'    => $payload['name'],
                'user_id' => Auth::id(),
                'order'   => $maxOrder + 1,
                'columns' => $columns,
            ]);
            foreach ($payload['rows'] ?? [] as $row) {
                SheetData::create([
                    'sheet_id'  => $newSheet->id,
                    'row_index' => (int) $row['row_index'],
                    'row_data'  => $row['data'] ?? [],
                ]);
            }
            return $newSheet;
        });

        $this->logAudit('sheet_imported', $sheet->id, [
            'name' => $sheet->name,
            'rows_count' => count($payload['rows'] ?? []),
            'columns_count' => count($columns),
        ]);

        return response()->json([
            'id'      => $sheet->id,
            'name'    => $sheet->name,
            'columns' => $sheet->columns,
        ]);
    }

    public function update(Request $request, Sheet $sheet)
    {
        $this->authorizeEdit($sheet);

        $oldName = $sheet->name;
        $sheet->update($request->validate([
            'name' => 'required|string|max:255',
        ]));

        if ($oldName !== $sheet->name) {
            $this->logAudit('sheet_renamed', $sheet->id, [
                'old_name' => $oldName,
                'new_name' => $sheet->name,
            ]);
        }

        return back();
    }

    public function destroy(Sheet $sheet)
    {
        // Удаление листа = admin only (строже, чем edit).
        $this->authorizeAdmin();

        $sheetName = $sheet->name;
        $sheetId = $sheet->id;

        // Логируем ДО delete:
        // 1) если delete бросит — запись об удалении всё равно есть (и можно расследовать).
        // 2) sheet_id передаём настоящий — FK на sheet_audit_logs.sheet_id настроен
        //    nullOnDelete, так что запись переживёт каскад: при удалении листа эта
        //    строка журнала просто получит sheet_id = NULL (имя сохранено в details).
        $this->logAudit('sheet_deleted', $sheetId, [
            'sheet_id' => $sheetId,
            'name' => $sheetName,
        ]);

        // team_id в model_has_roles — это просто колонка (не FK с cascade),
        // поэтому per-sheet ролевые назначения чистим вручную перед delete.
        $sheet->detachAllAssignments();
        $sheet->delete();
        return redirect()->route('dashboard');
    }

    public function insertRow(Request $request, Sheet $sheet)
    {
        $this->authorizeEdit($sheet);

        $rowIndex = (int) $request->validate([
            'row_index' => 'required|integer|min:0|max:' . self::MAX_ROW_INDEX,
        ])['row_index'];

        // Concurrent insert/delete на одном листе раньше могли получить дубль row_index
        // или пропуски. Транзакция + явный SELECT FOR UPDATE по затрагиваемому диапазону
        // сериализует параллельные операции. ВАЖНО: lockForUpdate() влияет ТОЛЬКО на
        // SELECT-запрос, поэтому нужен явный ->get() ПЕРЕД increment'ом — иначе
        // ->lockForUpdate()->increment() это no-op (UPDATE-команда без FOR UPDATE).
        $affected = DB::transaction(function () use ($sheet, $rowIndex) {
            // 1. Берём блокировку на строки, которые сейчас сдвинем (SELECT FOR UPDATE,
            // явный get() — без него lockForUpdate не сработает на UPDATE-командах).
            SheetData::where('sheet_id', $sheet->id)
                ->where('row_index', '>=', $rowIndex)
                ->lockForUpdate()
                ->get(['id']);

            // 2. PG проверяет UNIQUE построчно. Прямой increment'нул бы
            // промежуточное состояние с дублями. Поэтому сдвигаем в два шага:
            // 2.1 Уносим row_index в недостижимый диапазон (+SHIFT_OFFSET).
            $shifted = SheetData::where('sheet_id', $sheet->id)
                ->where('row_index', '>=', $rowIndex)
                ->increment('row_index', self::SHIFT_OFFSET + 1);
            // 2.2 Возвращаем со сдвигом на 1 (т.е. конечное смещение = +1).
            SheetData::where('sheet_id', $sheet->id)
                ->where('row_index', '>=', self::SHIFT_OFFSET + 1)
                ->decrement('row_index', self::SHIFT_OFFSET);

            // 3. Вставляем новую пустую строку — слот rowIndex теперь свободен.
            SheetData::create([
                'sheet_id'  => $sheet->id,
                'row_index' => $rowIndex,
                'row_data'  => [],
            ]);

            return $shifted;
        });

        $this->logAudit('row_inserted', $sheet->id, [
            'row_index'    => $rowIndex,
            'shifted_rows' => $affected, // сколько строк сдвинулось вниз
        ]);

        return back();
    }

    public function deleteRow(Request $request, Sheet $sheet)
    {
        $this->authorizeEdit($sheet);

        $rowIndex = (int) $request->validate([
            'row_index' => 'required|integer|min:0|max:' . self::MAX_ROW_INDEX,
        ])['row_index'];

        $result = DB::transaction(function () use ($sheet, $rowIndex) {
            // Лочим целевую + всё, что ниже (их сдвинем). SELECT FOR UPDATE.
            SheetData::where('sheet_id', $sheet->id)
                ->where('row_index', '>=', $rowIndex)
                ->lockForUpdate()
                ->get(['id']);

            // Удаляем строку — слот освобождается.
            $deleted = SheetData::where('sheet_id', $sheet->id)
                ->where('row_index', $rowIndex)
                ->delete();

            // Сдвиг наверх через тот же offset-трюк (см. insertRow).
            $shifted = SheetData::where('sheet_id', $sheet->id)
                ->where('row_index', '>', $rowIndex)
                ->increment('row_index', self::SHIFT_OFFSET - 1);
            SheetData::where('sheet_id', $sheet->id)
                ->where('row_index', '>=', self::SHIFT_OFFSET)
                ->decrement('row_index', self::SHIFT_OFFSET);

            return ['deleted' => $deleted, 'shifted' => $shifted];
        });

        $this->logAudit('row_deleted', $sheet->id, [
            'row_index'    => $rowIndex,
            'deleted'      => $result['deleted'], // 0 или 1 — была ли там запись
            'shifted_rows' => $result['shifted'], // сколько строк подняли наверх
        ]);

        return back();
    }

    /**
     * Отправить лист по почте через подключенный Gmail юзера.
     * POST /sheets/{sheet}/email
     *   to:      email получателя
     *   subject: тема (опц.)
     *   message: текст сообщения (опц.)
     *   attach_xlsx: bool — прикрепить .xlsx (по умолчанию true)
     */
    public function email(Request $request, Sheet $sheet, GmailMailerService $mailer)
    {
        // Юзер должен иметь хотя бы view-доступ к листу.
        $this->authorizeView($sheet);

        $payload = $request->validate([
            'to'           => 'required|email|max:255',
            'subject'      => 'nullable|string|max:255',
            'message'      => 'nullable|string|max:5000',
            'attach_xlsx'  => 'sometimes|boolean',
        ]);

        $user = Auth::user();
        // Право на использование почтовой отправки (выдаётся админом в /users).
        if (!Sheet::userCanSendMail($user)) {
            abort(403, 'У вас нет прав на отправку почты.');
        }
        if (!$user->hasGoogleConnected()) {
            // JSON 422 — иначе axios на back()->withErrors() получает 302 и думает что успех.
            return response()->json(['errors' => ['gmail' => ['Сначала подключите Gmail в профиле.']]], 422);
        }

        $subject = trim($payload['subject'] ?? '') !== ''
            ? $payload['subject']
            : 'Таблица: ' . $sheet->name;

        // Тело: имя+email отправителя + (опц.) сообщение + ссылка на лист.
        $bodyLines = [];
        $bodyLines[] = "{$user->name} ({$user->google_email}) отправил вам таблицу с сайта Excel Tojiktelecom.";
        if (!empty($payload['message'])) {
            $bodyLines[] = "";
            $bodyLines[] = $payload['message'];
        }
        $body = implode("\r\n", $bodyLines);

        // Генерим .xlsx если нужно.
        $attachment = null;
        if (($payload['attach_xlsx'] ?? true)) {
            $attachment = $this->buildXlsxAttachment($sheet);
        }

        try {
            $mailer->send($user, $payload['to'], $subject, $body, $attachment, isHtml: false);
        } catch (\RuntimeException $e) {
            // Логируем причину чтобы в laravel.log было видно что именно упало.
            \Log::warning('Gmail send failed', [
                'user_id'   => $user->id,
                'to'        => $payload['to'],
                'sheet_id'  => $sheet->id,
                'error'     => $e->getMessage(),
            ]);
            // JSON 422 чтобы axios зашёл в catch (back()->withErrors даёт 302 → axios думает успех).
            return response()->json(['errors' => ['gmail' => [$e->getMessage()]]], 422);
        }

        $this->logAudit('sheet_emailed', $sheet->id, [
            'to'           => $payload['to'],
            'subject'      => $subject,
            'has_attachment' => $attachment !== null,
            'attachment_kb' => $attachment ? (int) round(strlen($attachment['data']) / 1024) : 0,
            'sender_gmail' => $user->google_email,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Генерит .xlsx для отправки. Возвращает массив для GmailMailerService.
     * Использует PhpSpreadsheet (уже в composer.json).
     */
    private function buildXlsxAttachment(Sheet $sheet): array
    {
        $columns = $sheet->columns ?? [];
        $rows = SheetData::where('sheet_id', $sheet->id)->orderBy('row_index')->get();

        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        // Имя листа: PhpSpreadsheet ругается на >31 символ и спецсимволы — обрежем + почистим.
        $safeName = preg_replace('/[^\p{L}\p{N}\s_\-]/u', '', $sheet->name) ?: 'Sheet1';
        $ws->setTitle(mb_substr($safeName, 0, 31));

        // PhpSpreadsheet v4 убрал setCellValueByColumnAndRow — нужно строить адрес
        // ячейки строкой ('A1', 'B1', ...). Coordinate::stringFromColumnIndex
        // переводит 1→A, 2→B, ..., 27→AA.
        $colAddr = fn (int $i) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);

        // Заголовки.
        $col = 1;
        foreach ($columns as $c) {
            $ws->setCellValue($colAddr($col) . '1', $c['headerName'] ?? $c['field'] ?? ('Column ' . $col));
            $col++;
        }
        // Жирный шрифт для заголовков.
        if (!empty($columns)) {
            $lastCol = $colAddr(count($columns));
            $ws->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        }

        // Данные.
        $rowNum = 2;
        foreach ($rows as $r) {
            $col = 1;
            foreach ($columns as $c) {
                $field = $c['field'] ?? null;
                if (!$field) { $col++; continue; }
                $val = $r->row_data[$field] ?? null;
                if ($val !== null && !is_array($val)) {
                    $ws->setCellValue($colAddr($col) . $rowNum, $val);
                }
                $col++;
            }
            $rowNum++;
        }

        // Записываем в память.
        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $xlsxBytes = ob_get_clean();

        $filename = preg_replace('/[^\p{L}\p{N}\s_\-]/u', '_', $sheet->name) ?: 'sheet';
        return [
            'name' => $filename . '.xlsx',
            'data' => $xlsxBytes,
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }
}
