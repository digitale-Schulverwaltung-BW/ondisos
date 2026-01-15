<?php
require '../../vendor/autoload.php';
require 'inc/db.php';
//require 'header.php'; // nur wenn Auth hier drin ist

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;


function colLetter(int $col): string {
    $letter = '';
    while ($col > 0) {
        $col--;
        $letter = chr(65 + ($col % 26)) . $letter;
        $col = intdiv($col, 26);
    }
    return $letter;
}

$formKey = $_GET['form'] ?? null;

$sql = "
    SELECT id, formular, data, created_at
    FROM anmeldungen
    WHERE deleted = 0
";

$params = [];
$types  = '';

if ($formKey) {
    $sql   .= " AND form_key = ?";
    $params[] = $formKey;
    $types   .= 's';
}

$sql .= " ORDER BY created_at DESC";
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit("Prepare failed");
}

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);
$columns = [];

foreach ($rows as $row) {
    $data = json_decode($row['data'], true);
    if (!is_array($data)) continue;

    foreach ($data as $key => $_) {
        $columns[$key] = true;
    }
}

$columns = array_keys($columns);
sort($columns);
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$rowNum = 1;
$colNum = 1;

$sheet->setCellValue(colLetter($colNum++) . $rowNum, 'ID');
$sheet->setCellValue(colLetter($colNum++) . $rowNum, 'Formular');
$sheet->setCellValue(colLetter($colNum++) . $rowNum, 'Eingang');

foreach ($columns as $col) {
    $sheet->setCellValue(colLetter($colNum++) . $rowNum, $col);
}

$sheet->getStyle("1:$rowNum")->getFont()->setBold(true);



foreach ($rows as $row) {
    $rowNum++;
    $colNum = 1;

    $sheet->setCellValue(colLetter($colNum++) . $rowNum, $row['id']);
    $sheet->setCellValue(colLetter($colNum++) . $rowNum, $row['form_key']);
    $sheet->setCellValue(colLetter($colNum++) . $rowNum, $row['created_at']);

    $data = json_decode($row['data'], true) ?? [];

    foreach ($columns as $key) {
        $value = $data[$key] ?? '';
        if (is_array($value)) {
            $value = implode(', ', $value);
        }
        $sheet->setCellValue(colLetter($colNum++) . $rowNum, $value);
    }
}


foreach (range('A', $sheet->getHighestColumn()) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$sheet->setAutoFilter($sheet->calculateWorksheetDimension());


$filename = 'anmeldungen_' . date('Y-m-d_H-i') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
