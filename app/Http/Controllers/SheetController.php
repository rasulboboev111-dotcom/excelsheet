<?php

namespace App\Http\Controllers;

use App\Models\Sheet;
use App\Models\SheetAuditLog;
use App\Models\SheetData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;
use Inertia\Inertia;

class SheetController extends Controller
{
    private const MAX_IMPORT_ROWS = 100000;
    private const MAX_IMPORT_COLS = 1000;
    private const MAX_UPDATE_ROWS = 50000;
    private const MAX_ROW_INDEX = 1048576;

    /**
     * Уникальный 64-битный ключ для pg_advisory_xact_lock — сериализует выдачу
     * нового sheets.order между параллельными импортами/созданиями. Лок снимается
     * автоматически в конце транзакции (commit/rollback).
     *
     * Ключ должен быть стабильным и единым во всём приложении. Берём отрезок md5
     * имени домена ('sheets_order_advisory_lock') и приводим к bigint.
     */
    private const SHEETS_ORDER_LOCK_KEY = 638295124133566588; // hexdec(substr(md5('sheets_order'),0,15))

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

        $changes = []; // [{ row, col, col_name, old, new }, …]

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
                if ($this->normalizeCellValue($oldVal) === $this->normalizeCellValue($newVal)) continue;
                $changes[] = [
                    'row'      => $rowIndex + 1, // человеко-читаемая нумерация с 1
                    'col'      => $field,
                    'col_name' => $columnsMap[$field] ?? $field,
                    'old'      => $oldVal,
                    'new'      => $newVal,
                ];
            }

            SheetData::updateOrCreate(
                ['sheet_id' => $sheet->id, 'row_index' => $rowIndex],
                ['row_data' => $clean]
            );
        }

        // Лог пишем ТОЛЬКО если реально менялось хоть одно значение.
        // Стилевые правки (жирный/цвет) в журнал не попадают — это шум.
        if (!empty($changes)) {
            $this->logAudit('cell_edit', $sheet->id, [
                'cells_changed' => count($changes),
                'sample'        => array_slice($changes, 0, 20),
            ]);
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

        // Сдвигаем все строки ниже на одну позицию вниз
        SheetData::where('sheet_id', $sheet->id)
            ->where('row_index', '>=', $rowIndex)
            ->increment('row_index');

        // Создаем новую пустую строку на освободившемся месте
        SheetData::create([
            'sheet_id' => $sheet->id,
            'row_index' => $rowIndex,
            'row_data' => []
        ]);

        $this->logAudit('row_inserted', $sheet->id, ['row_index' => $rowIndex]);

        return back();
    }

    public function deleteRow(Request $request, Sheet $sheet)
    {
        $this->authorizeEdit($sheet);

        $rowIndex = (int) $request->validate([
            'row_index' => 'required|integer|min:0|max:' . self::MAX_ROW_INDEX,
        ])['row_index'];

        // Удаляем целевую строку
        SheetData::where('sheet_id', $sheet->id)
            ->where('row_index', $rowIndex)
            ->delete();

        // Сдвигаем все строки ниже на одну позицию вверх
        SheetData::where('sheet_id', $sheet->id)
            ->where('row_index', '>', $rowIndex)
            ->decrement('row_index');

        $this->logAudit('row_deleted', $sheet->id, ['row_index' => $rowIndex]);

        return back();
    }
}
