<?php

namespace App\Http\Controllers;

use App\Models\Sheet;
use App\Models\SheetAuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class SheetAuditLogController extends Controller
{
    private function authorizeAdmin(): void
    {
        $user = Auth::user();
        if (!$user || !Sheet::userIsAdmin($user)) {
            throw new AuthorizationException('Admin only.');
        }
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin();

        // Валидируем фильтры: дата может прийти кривая (datetime-local без секунд,
        // сторонний клиент с мусором). Без validate сырой $request->from идёт в
        // where('>=', ...) и на PostgreSQL/SQLite даёт 500.
        $validated = $request->validate([
            'user_id'  => 'nullable|integer',
            'sheet_id' => 'nullable|integer',
            'action'   => 'nullable|string|max:64',
            'from'     => 'nullable|date',
            'to'       => 'nullable|date',
        ]);

        $filters = array_filter($validated, fn ($v) => $v !== null && $v !== '');

        // Вторичная сортировка по id обязательна: если несколько записей упали
        // в одну и ту же миллисекунду (а под нагрузкой это бывает — особенно
        // при паст'е/филле), пагинация без tie-breaker'а пропускает или
        // дублирует записи между страницами.
        $query = SheetAuditLog::with(['user:id,name,email', 'sheet:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (!empty($filters['user_id']))  $query->where('user_id', (int) $filters['user_id']);
        if (!empty($filters['sheet_id'])) $query->where('sheet_id', (int) $filters['sheet_id']);
        if (!empty($filters['action']))   $query->where('action', $filters['action']);
        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', \Carbon\Carbon::parse($filters['from'])->toDateTimeString());
        }
        if (!empty($filters['to'])) {
            // datetime-local не отдаёт секунды → нормализуем до конца минуты,
            // иначе записи внутри последней секунды фильтра выпадают.
            $query->where('created_at', '<=', \Carbon\Carbon::parse($filters['to'])->endOfMinute()->toDateTimeString());
        }

        $logs = $query->paginate(50)->withQueryString();

        return Inertia::render('AuditLog/Index', [
            'logs'    => $logs,
            'filters' => $filters,
            'users'   => User::select('id', 'name')->orderBy('name')->get(),
            'sheets'  => Sheet::select('id', 'name')->orderBy('name')->get(),
            'actions' => [
                'cell_edit'           => 'Изменение ячеек',
                'sheet_created'       => 'Создание листа',
                'sheet_renamed'       => 'Переименование листа',
                'sheet_deleted'       => 'Удаление листа',
                'sheet_imported'      => 'Импорт листа',
                'row_inserted'        => 'Вставка строки',
                'row_deleted'         => 'Удаление строки',
                'audit_cleared'       => 'Очистка журнала',
                'sheet_emailed'       => 'Отправка листа по почте',
                'gmail_connected'     => 'Подключение Gmail',
                'gmail_disconnected'  => 'Отключение Gmail',
            ],
        ]);
    }

    /**
     * Полная очистка журнала. Только админ. После очистки сразу пишем
     * запись «audit_cleared» с количеством удалённых записей и автором —
     * чтобы следующий админ видел, что журнал не пуст «случайно».
     */
    public function clear()
    {
        $this->authorizeAdmin();

        $count = SheetAuditLog::count();
        SheetAuditLog::truncate();

        // Лог пишем СРАЗУ после truncate — это первая запись в чистом журнале.
        SheetAuditLog::create([
            'user_id' => Auth::id(),
            'sheet_id' => null,
            'action' => 'audit_cleared',
            'details' => ['removed_entries' => $count],
            'ip' => request()->ip(),
        ]);

        return back();
    }
}
