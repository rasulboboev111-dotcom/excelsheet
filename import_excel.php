<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\Sheet;
use App\Models\SheetData;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$inputFileName = 'Открытые вакансии.xlsx';
$spreadsheet = IOFactory::load($inputFileName);

foreach ($spreadsheet->getSheetNames() as $sheetName) {
    $cleanSheetName = trim($sheetName);
    echo "Importing sheet: $cleanSheetName\n";
    $dbSheet = Sheet::where('name', $cleanSheetName)->first();
    if (!$dbSheet) {
        echo "Sheet not found in DB: $sheetName\n";
        continue;
    }

    $worksheet = $spreadsheet->getSheetByName($sheetName);
    $data = $worksheet->toArray(NULL, TRUE, TRUE, TRUE);

    // First row is headers
    $headers = array_shift($data);
    $columns = [];
    foreach ($headers as $key => $value) {
        if ($value) {
            $columns[] = ['field' => $key, 'headerName' => $value];
        }
    }
    
    $dbSheet->update(['columns' => $columns]);

    // Clear old data
    SheetData::where('sheet_id', $dbSheet->id)->delete();

    foreach ($data as $rowIndex => $row) {
        // Filter out empty rows
        if (!array_filter($row)) continue;

        SheetData::create([
            'sheet_id' => $dbSheet->id,
            'row_data' => $row,
            'row_index' => $rowIndex
        ]);
    }
}
echo "Import complete!\n";
