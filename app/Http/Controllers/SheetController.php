<?php

namespace App\Http\Controllers;

use App\Models\Sheet;
use App\Models\SheetData;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SheetController extends Controller
{
    public function index(Request $request)
    {
        $sheets = Sheet::orderBy('order')->get();
        $activeSheetId = $request->input('sheet_id', $sheets->first()?->id);
        
        $activeSheet = null;
        $sheetData = [];

        if ($activeSheetId) {
            $activeSheet = Sheet::with('users')->find($activeSheetId);
            $sheetData = SheetData::where('sheet_id', $activeSheetId)
                ->orderBy('row_index')
                ->get()
                ->map(function ($row) {
                    return array_merge(['id' => $row->id], $row->row_data);
                });
        }

        return Inertia::render('Dashboard', [
            'sheets' => $sheets,
            'activeSheet' => $activeSheet,
            'initialData' => $sheetData,
        ]);
    }

    public function updateData(Request $request, Sheet $sheet)
    {
        $rows = $request->input('rows', []);
        
        foreach ($rows as $row) {
            SheetData::updateOrCreate(
                ['sheet_id' => $sheet->id, 'row_index' => $row['row_index']],
                ['row_data' => $row['data']]
            );
        }

        return back();
    }

    public function store(Request $request)
    {
        $sheet = Sheet::create([
            'name' => 'Новый лист',
            'user_id' => auth()->id(),
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
        $payload = $request->validate([
            'name'            => 'required|string|max:255',
            'columns'         => 'sometimes|array',
            'columns.*.field' => 'required_with:columns|string|max:32',
            'rows'            => 'sometimes|array',
            'rows.*.row_index'=> 'required_with:rows|integer|min:0',
            'rows.*.data'     => 'sometimes|array',
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
            'user_id' => auth()->id(),
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
        $sheet->update($request->validate([
            'name' => 'required|string|max:255',
        ]));

        return back();
    }

    public function destroy(Sheet $sheet)
    {
        $sheet->delete();
        return redirect()->route('dashboard');
    }

    public function insertRow(Request $request, Sheet $sheet)
    {
        $rowIndex = $request->input('row_index');
        
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
        $rowIndex = $request->input('row_index');
        
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
