<?php
// frontend/public/ical.php

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';

use Frontend\Config\FormConfig;

// Validate form key
$formKey = $_GET['form'] ?? '';

if (empty($formKey) || !FormConfig::exists($formKey)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Formular nicht gefunden.');
}

$icalConfig = FormConfig::get($formKey)['ical'] ?? null;

if (!$icalConfig || !($icalConfig['enabled'] ?? false)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('iCal-Download für dieses Formular nicht aktiviert.');
}

// Build DTSTART / DTEND from date + time strings
$date      = $icalConfig['event_date']       ?? '';
$timeStart = $icalConfig['event_time_start'] ?? '00:00';
$timeEnd   = $icalConfig['event_time_end']   ?? '00:00';

$dtStart = str_replace('-', '', $date) . 'T' . str_replace(':', '', $timeStart) . '00';
$dtEnd   = str_replace('-', '', $date) . 'T' . str_replace(':', '', $timeEnd)   . '00';
$dtStamp = gmdate('Ymd\THis\Z');

// Deterministic UID — same event always yields the same UID
$uid = md5($formKey . $date . $timeStart) . '@ondisos';

/**
 * Escape a value for use in an iCal text property (RFC 5545 §3.3.11).
 * Backslash must be escaped first to avoid double-escaping.
 */
function icalEscape(string $text): string
{
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace(["\r\n", "\r", "\n"], '\\n', $text);
    $text = str_replace([',', ';'], ['\\,', '\\;'], $text);
    return $text;
}

$summary     = icalEscape($icalConfig['event_title']       ?? $formKey);
$location    = icalEscape($icalConfig['event_location']    ?? '');
$description = icalEscape($icalConfig['event_description'] ?? '');

$ics  = "BEGIN:VCALENDAR\r\n";
$ics .= "VERSION:2.0\r\n";
$ics .= "PRODID:-//ondisos//Schulanmeldung//DE\r\n";
$ics .= "CALSCALE:GREGORIAN\r\n";
$ics .= "METHOD:PUBLISH\r\n";
$ics .= "BEGIN:VEVENT\r\n";
$ics .= "UID:{$uid}\r\n";
$ics .= "DTSTAMP:{$dtStamp}\r\n";
$ics .= "DTSTART:{$dtStart}\r\n";
$ics .= "DTEND:{$dtEnd}\r\n";
$ics .= "SUMMARY:{$summary}\r\n";
if ($location !== '')    { $ics .= "LOCATION:{$location}\r\n"; }
if ($description !== '') { $ics .= "DESCRIPTION:{$description}\r\n"; }
$ics .= "END:VEVENT\r\n";
$ics .= "END:VCALENDAR\r\n";

$filename = 'termin-' . preg_replace('/[^a-z0-9-]/', '-', strtolower($formKey)) . '.ics';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Content-Length: ' . strlen($ics));

echo $ics;
