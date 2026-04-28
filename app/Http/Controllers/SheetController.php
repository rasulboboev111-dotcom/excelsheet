<?php

namespace App\Http\Controllers;

use App\Models\Sheet;
use App\Models\SheetData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\Access\AuthorizationException;
use Inertia\Inertia;

class SheetController extends Controller
{
    private const MAX_IMPORT_ROWS = 100000;
    private const MAX_IMPORT_COLS = 1000;
    private const MAX_UPDATE_ROWS = 50000;
    private const MAX_ROW_INDEX = 1048576;

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
        if (!$user || !$user->hasRole('admin')) {
            throw new AuthorizationException('Admin only.');
        }
    }

    public function index(Request $request)
    {
        $userId = Auth::id();
        $isAdmin = Auth::user()?->hasRole('admin') ?? false;

        // Админ видит все листы; обычный юзер — свои (owner) + те, где ему дали роль.
        if ($isAdmin) {
            $sheets = Sheet::with('owner:id,name,email')->orderBy('order')->get();
        } else {
            $sheets = Sheet::with('owner:id,name,email')
                ->where(function ($q) use ($userId) {
                    $q->where('user_id', $userId)
                      ->orWhereHas('users', fn ($u) => $u->where('users.id', $userId));
                })
                ->orderBy('order')->get();
        }

        $activeSheetId = $request->input('sheet_id', $sheets->first()?->id);

        $activeSheet = null;
        $sheetData = [];
        $canEditActive = false;
        $canViewActive = false;

        if ($activeSheetId) {
            $activeSheet = Sheet::with(['users', 'owner:id,name,email'])->find($activeSheetId);
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

    public function updateData(Request $request, Sheet $sheet)
    {
        $this->authorizeEdit($sheet);

        $payload = $request->validate([
            'rows'              => 'required|array|max:' . self::MAX_UPDATE_ROWS,
            'rows.*.row_index'  => 'required|integer|min:0|max:' . self::MAX_ROW_INDEX,
            'rows.*.data'       => 'required|array|max:' . self::MAX_IMPORT_COLS,
        ]);

        foreach ($payload['rows'] as $row) {
            // Sanitize row_data: ensure all values are scalar or null (no nested objects/arrays
            // that could carry unexpected payloads). _style entries are arrays — allow one level.
            $clean = [];
            foreach (($row['data'] ?? []) as $key => $value) {
                if (!is_string($key) || strlen($key) > 64) continue;
                if (is_scalar($value) || $value === null) {
                    $clean[$key] = $value;
                } elseif (is_array($value)) {
                    // _style sub-array — keep only scalar leaves
                    $sub = [];
                    foreach ($value as $sk => $sv) {
                        if (is_string($sk) && (is_scalar($sv) || $sv === null)) {
                            $sub[$sk] = $sv;
                        }
                    }
                    $clean[$key] = $sub;
                }
            }

            SheetData::updateOrCreate(
                ['sheet_id' => $sheet->id, 'row_index' => (int) $row['row_index']],
                ['row_data' => $clean]
            );
        }

        return back();
    }

    public function store(Request $request)
    {
        // Создавать листы может только админ.
        $this->authorizeAdmin();

        $sheet = Sheet::create([
            'name' => 'Новый лист',
            'user_id' => Auth::id(),
            'order' => Sheet::max('order') + 1,
            'columns' => [
                ['field' => 'A', 'headerName' => 'A'],
                ['field' => 'B', 'headerName' => 'B'],
                ['field' => 'C', 'headerName' => 'C'],
            ]
        ]);
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

        $sheet = Sheet::create([
            'name'    => $payload['name'],
            'user_id' => Auth::id(),
            'order'   => (int) Sheet::max('order') + 1,
            'columns' => $columns,
        ]);

        foreach ($payload['rows'] ?? [] as $row) {
            SheetData::create([
                'sheet_id'  => $sheet->id,
                'row_index' => (int) $row['row_index'],
                'row_data'  => $row['data'] ?? [],
            ]);
        }

        return response()->json([
            'id'      => $sheet->id,
            'name'    => $sheet->name,
            'columns' => $sheet->columns,
        ]);
    }

    public function update(Request $request, Sheet $sheet)
    {
        $this->authorizeEdit($sheet);

        $sheet->update($request->validate([
            'name' => 'required|string|max:255',
        ]));

        return back();
    }

    public function destroy(Sheet $sheet)
    {
        // Удаление листа = admin only (строже, чем edit).
        $this->authorizeAdmin();

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

        return back();
    }
}
