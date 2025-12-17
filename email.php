<?php

require_once 'config.php';

// Helper: remove dangerous header characters and tags
function sanitize_header_value(string $v): string {
    //$v = strip_tags($v);
    $v = preg_replace("/[\r\n]+/", ' ', $v);
    return trim($v);
}

// Build plain text and HTML email parts from form data array
function build_notification_email(array $formData): array {
    $subject = "Neue Anmeldung";
    if (!empty($formData['name'])) {
        $subject .= " von " . sanitize_header_value($formData['name']);
    }

    $plain = "Es wurde eine neue Anmeldung übermittelt:\n\n";
    $plain .= MAIL_HEAD . "\n\n";

    $html  = "<!doctype html><html><head><style>tr:nth-child(even) { background-color: #eee; } tr:nth-child(odd) { background-color: #fff; }</style></head><body>";
    $html .= "<h2>Neue Anmeldung</h2>";
    $html .= '<p>' . MAIL_HEAD . '</p>';
    $html .= "<table cellpadding='4' cellspacing='0' border='1' style='border-collapse:collapse;'>";

    foreach ($formData as $key => $value) {
        // Skip empty values if you prefer
        if ($value === null || $value === '') continue;

        $label = ucfirst(str_replace(['_', '-'], ' ', $key));
        if (is_array($value)) {
            $valuePlain = implode(', ', array_map('strval', $value));
        } else {
            $valuePlain = (string)$value;
        }
        $plain .= "$label: $valuePlain\n";
        $html  .= "<tr><td style='font-weight:bold;'>".htmlspecialchars($label)."</td><td>".nl2br(htmlspecialchars($valuePlain))."</td></tr>";
    }

    $html .= "</table>";
    $html .= '<p>' . MAIL_FOOT . '</p>';
    $html .= "<p>Datum: " . date('Y-m-d H:i:s') . "</p>";
    $html .= "</body></html>";

    $plain .= "\nDatum: " . date('Y-m-d H:i:s') . "\n\n";
    $plain .= MAIL_FOOT . "\n";

    return [
        'subject' => sanitize_header_value($subject),
        'plain'   => $plain,
        'html'    => $html
    ];
}

// Send notification to NOTIFY_EMAIL (defined in config)
function send_notification_email(array $formData): String | bool {
    $to = defined('NOTIFY_EMAIL') ? NOTIFY_EMAIL : '';
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $parts = build_notification_email($formData);
    $subject = $parts['subject'];


    $from = FROM_EMAIL;

    $headers = [];
    $headers[] = 'From: ' . sanitize_header_value($from);
    $headers[] = 'Return-Path: <' . sanitize_header_value($from) . '>';
    
    if (!empty($formData['email']) && filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . sanitize_header_value($formData['email']);
    }
    $headers[] = 'MIME-Version: 1.0';

    // Multipart alternative
    $boundary = '==Multipart_Boundary_x' . md5(uniqid((string)microtime(true), true)) . 'x';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    // Build message body
    $message = "";
    $message .= "--" . $boundary . "\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $parts['plain'] . "\r\n\r\n";

    $message .= "--" . $boundary . "\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= "Content-Disposition: inline\r\n\r\n";
    $message .= chunk_split(base64_encode(
        preg_replace("/\r?\n/", "\r\n", $parts['html'])
    )) . "\r\n\r\n";
    
    $message .= "--" . $boundary . "--\r\n";

    // Final headers string
    $headers_str = implode("\r\n", $headers)."\r\n";

    // file_put_contents('debug-email.html', $headers_str . $message);

    $success = @mail($to, $subject, $message, $headers_str, '-f '. escapeshellarg($from));
    if (!$success) {
      return  error_get_last()['message'];
    }
    //return $message;
    return true;
}

