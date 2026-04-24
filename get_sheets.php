<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$inputFileType = 'Xlsx';
$inputFileName = 'Открытые вакансии.xlsx';

$reader = IOFactory::createReader($inputFileType);
$spreadsheet = $reader->load($inputFileName);
$sheetNames = $spreadsheet->getSheetNames();

echo json_encode($sheetNames);
