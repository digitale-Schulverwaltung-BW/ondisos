<?php
declare(strict_types=1);

require_once 'config/config.php';
require_once 'email.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

// 🔐 0. Grundschutz
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method');
        exit;
    }
    if (!isset($_POST["csrf_token"]) || $_POST["csrf_token"] !== $_SESSION["csrf_token"]) {
        throw new RuntimeException('CSRF validation failed');
        exit;
    }

    // 1️⃣ Formular bestimmen
    $form = $_REQUEST['form'] ?? '';
    if (!isset($configs[$form])|| empty($form)) {
        throw new RuntimeException('Unbekanntes/fehlendes Formular');
        exit;    
    }
    $form = trim($form);
    $formConfig = $configs[$form];

    // 2️⃣ Survey-Daten lesen
    if (empty($_POST['survey_data'])) {
        throw new RuntimeException('Keine Formulardaten empfangen');
    }

    $rawJson = $_POST['survey_data'];
    $data = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);

    // 3️⃣ optionale Felder extrahieren
    $name  = $data['Name']   ?? null;
    $email = $data['email1'] ?? $data['Email'] ?? null;
    $meta = json_decode($_POST['meta'] ?? '{}', true);
    $formular     = $meta['formular'] ?? 'unknown';
    $form_version = $meta['version']  ?? 'unknown';

    if (isset($formConfig['db']) && $formConfig['db'] !== false)
    {
        // 4️⃣ DB-Verbindung
        $mysqli = new mysqli(
            DBHOST,
            DBUSER,
            DBPASSWORD,             // ToDo env vars
            DBNAME
        );
        $mysqli->set_charset('utf8mb4');

        // 5️⃣ INSERT vorbereiten
        $sql = "
            INSERT INTO " . DBTABLE . " (
                formular,
                formular_version,
                name,
                email,
                status,
                data,
                created_at
            ) VALUES (
                ?, ?, ?, ?, 'neu', ?, NOW()
            )
        ";

        $stmt = $mysqli->prepare($sql);

        // 6️⃣ Binden
        $stmt->bind_param(
            'sssss',
            $form,
            $form_version,
            $name,
            $email,
            $rawJson
        );

        // 7️⃣ Ausführen
        $stmt->execute();

        // 8️⃣ Uploads wegsichern

        $anmeldungId = $stmt->insert_id;

        // Uploads weiterleiten
        foreach ($_FILES as $field => $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                continue;
            }
            $ch = curl_init(BACKEND_UPLOAD_URL);

            $post = [
                'anmeldung_id' => $anmeldungId,
                'fieldname'    => $field,
                'file'         => new CURLFile(
                    $file['tmp_name'],
                    $file['type'],
                    $file['name']
                )
            ];

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $post,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20
            ]);

            $response = curl_exec($ch);
            $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            if ($status !== 200) {
                // optional: loggen
            }
        }        


    } else {
        // Kein DB-Speicher gewünscht
        $stmt = new class {
            public $insert_id = 0;
        };
    }
    // 8️⃣ Erfolg
    echo json_encode([
        'success' => true,
        'id'      => $stmt->insert_id
    ]);
    // 9️⃣ Benachrichtigungs-E-Mail senden
    send_notification_email($data);

} catch (Throwable $e) {

    // ❌ Sauberer Fehler – IMMER JSON
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
