<?php
declare(strict_types=1);

include '../config/config.php';

header('Content-Type: application/json; charset=utf-8');

// 🔐 Zugriff nur vom Webserver
$allowedIps = ['10.0.0.12']; // IP des Webservers

if (!in_array($_SERVER['REMOTE_ADDR'], $allowedIps, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// 1️⃣ Basisprüfung
if (empty($_POST['anmeldung_id']) || empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$anmeldungId = (int) $_POST['anmeldung_id'];
$fieldname   = $_POST['fieldname'] ?? 'file';
$file        = $_FILES['file'];

// 2️⃣ Upload-Checks
if ($file['error'] !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Upload error');
}

if ($file['size'] > 10_000_000) {
    throw new RuntimeException('File too large');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);

$allowed = [
    'application/pdf',
    'image/jpeg',
    'image/png'
];

if (!in_array($mime, $allowed, true)) {
    throw new RuntimeException('Invalid file type');
}

// 3️⃣ Zielpfad
$baseDir = __DIR__ . '/uploads/anmeldungen/' . date('Y/m/') . $anmeldungId;

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0750, true);
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$stored = bin2hex(random_bytes(16)) . '.' . $ext;
$target = $baseDir . '/' . $stored;

move_uploaded_file($file['tmp_name'], $target);

// 4️⃣ DB speichern
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli(DBHOST, DBUSER, DBPASSWORD, DBUPLOAD_NAME);
$mysqli->set_charset('utf8mb4');

$stmt = $mysqli->prepare("
    INSERT INTO anmeldungen_uploads
    (anmeldung_id, fieldname, original_name, stored_name, mime_type, size)
    VALUES (?, ?, ?, ?, ?, ?)
");

$stmt->bind_param(
    'issssi',
    $anmeldungId,
    $fieldname,
    $file['name'],
    $stored,
    $mime,
    $file['size']
);

$stmt->execute();

// 5️⃣ Erfolg
echo json_encode(['success' => true]);
