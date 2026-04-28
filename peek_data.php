<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$inputFileName = 'Открытые вакансии.xlsx';
$spreadsheet = IOFactory::load($inputFileName);

foreach ($spreadsheet->getSheetNames() as $index => $name) {
    $sheet = $spreadsheet->getSheetByName($name);
    $data = $sheet->rangeToArray('A1:E2', NULL, TRUE, TRUE, TRUE);
    echo "Sheet: $name\n";
    print_r($data);
    echo "-------------------\n";
    if ($index > 2) break; // Just check first 4 sheets
}
