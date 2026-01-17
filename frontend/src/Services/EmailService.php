<?php
// frontend/src/Services/EmailService.php

declare(strict_types=1);

namespace Frontend\Services;

class EmailService
{
    private string $fromEmail;
    private string $mailHead;
    private string $mailFoot;

    public function __construct(
        ?string $fromEmail = null,
        ?string $mailHead = null,
        ?string $mailFoot = null
    ) {
        $this->fromEmail = $fromEmail ?? getenv('FROM_EMAIL') ?: 'noreply@example.com';
        $this->mailHead = $mailHead ?? getenv('MAIL_HEAD') ?: 'Eine neue Anmeldung ist eingegangen.';
        $this->mailFoot = $mailFoot ?? getenv('MAIL_FOOT') ?: '';
    }

    /**
     * Send notification email
     */
    public function sendNotification(
        string $to,
        string $formKey,
        array $formData
    ): bool {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email address: ' . $to);
        }

        $parts = $this->buildEmail($formKey, $formData);
        
        return $this->sendMultipartEmail(
            to: $to,
            subject: $parts['subject'],
            plainBody: $parts['plain'],
            htmlBody: $parts['html'],
            replyTo: $this->extractReplyTo($formData)
        );
    }

    /**
     * Build email content
     */
    private function buildEmail(string $formKey, array $formData): array
    {
        $subject = "Neue Anmeldung: " . $formKey;
        
        if (!empty($formData['name']) || !empty($formData['Name'])) {
            $name = $formData['name'] ?? $formData['Name'];
            $subject .= " von " . $this->sanitizeHeaderValue($name);
        }

        // Plain text version
        $plain = "Es wurde eine neue Anmeldung übermittelt:\n\n";
        $plain .= $this->mailHead . "\n\n";
        $plain .= "Formular: $formKey\n\n";

        // HTML version
        $html = "<!doctype html><html><head><meta charset='utf-8'>";
        $html .= "<style>";
        $html .= "body { font-family: Arial, sans-serif; }";
        $html .= "table { border-collapse: collapse; width: 100%; }";
        $html .= "th, td { padding: 12px; text-align: left; border: 1px solid #ddd; }";
        $html .= "th { background-color: #4472C4; color: white; font-weight: bold; }";
        $html .= "tr:nth-child(even) { background-color: #f2f2f2; }";
        $html .= "</style></head><body>";
        $html .= "<h2>Neue Anmeldung: " . htmlspecialchars($formKey) . "</h2>";
        $html .= "<p>" . nl2br(htmlspecialchars($this->mailHead)) . "</p>";
        $html .= "<table>";
        $html .= "<thead><tr><th>Feld</th><th>Wert</th></tr></thead>";
        $html .= "<tbody>";

        foreach ($formData as $key => $value) {
            // Skip internal metadata fields (e.g., _fieldTypes)
            if (str_starts_with($key, '_')) {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $label = $this->humanizeFieldName($key);
            $valuePlain = $this->formatValue($value);

            $plain .= "$label: $valuePlain\n";
            
            $html .= "<tr>";
            $html .= "<td><strong>" . htmlspecialchars($label) . "</strong></td>";
            $html .= "<td>" . nl2br(htmlspecialchars($valuePlain)) . "</td>";
            $html .= "</tr>";
        }

        $html .= "</tbody></table>";
        $html .= "<p style='margin-top: 20px; color: #666;'>";
        $html .= nl2br(htmlspecialchars($this->mailFoot));
        $html .= "</p>";
        $html .= "<p style='color: #999; font-size: 12px;'>";
        $html .= "Datum: " . date('d.m.Y H:i:s');
        $html .= "</p>";
        $html .= "</body></html>";

        $plain .= "\n" . $this->mailFoot . "\n";
        $plain .= "\nDatum: " . date('d.m.Y H:i:s') . "\n";

        return [
            'subject' => $this->sanitizeHeaderValue($subject),
            'plain' => $plain,
            'html' => $html
        ];
    }

    /**
     * Send multipart email (plain + HTML)
     */
    private function sendMultipartEmail(
        string $to,
        string $subject,
        string $plainBody,
        string $htmlBody,
        ?string $replyTo = null
    ): bool {
        $headers = [];
        $headers[] = 'From: ' . $this->sanitizeHeaderValue($this->fromEmail);
        $headers[] = 'Return-Path: <' . $this->sanitizeHeaderValue($this->fromEmail) . '>';
        
        if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $this->sanitizeHeaderValue($replyTo);
        }
        
        $headers[] = 'MIME-Version: 1.0';

        // Boundary for multipart
        $boundary = '==Multipart_Boundary_' . md5(uniqid((string)microtime(true), true));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        // Build message body
        $message = "";
        
        // Plain text part
        $message .= "--" . $boundary . "\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $plainBody . "\r\n\r\n";

        // HTML part
        $message .= "--" . $boundary . "\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($htmlBody)) . "\r\n\r\n";
        
        $message .= "--" . $boundary . "--\r\n";

        // Send
        $headersStr = implode("\r\n", $headers);
        $additionalParams = '-f ' . escapeshellarg($this->fromEmail);

        return @mail($to, $subject, $message, $headersStr, $additionalParams);
    }

    /**
     * Extract reply-to email from form data
     */
    private function extractReplyTo(array $formData): ?string
    {
        $emailFields = ['email', 'Email', 'email1', 'e-mail'];
        
        foreach ($emailFields as $field) {
            if (!empty($formData[$field]) && filter_var($formData[$field], FILTER_VALIDATE_EMAIL)) {
                return $formData[$field];
            }
        }

        return null;
    }

    /**
     * Sanitize header value (prevent injection)
     */
    private function sanitizeHeaderValue(string $value): string
    {
        return trim(preg_replace("/[\r\n]+/", ' ', $value));
    }

    /**
     * Humanize field name for display
     */
    private function humanizeFieldName(string $name): string
    {
        $label = str_replace(['_', '-'], ' ', $name);
        return ucwords($label);
    }

    /**
     * Format value for display
     */
    private function formatValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        if (is_bool($value)) {
            return $value ? 'Ja' : 'Nein';
        }

        return (string)$value;
    }
}