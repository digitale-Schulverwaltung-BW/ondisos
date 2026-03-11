<?php
// TEMPORARY DEBUG SCRIPT — remove after debugging
// Access: https://your-backend/debug_nominatim.php?id=123
//
// Shows: which Anmeldungen have Teilort='autofill', what Nominatim returns for them.

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

use App\Config\EnvLoader;
use App\Repositories\AnmeldungRepository;
use App\Services\NominatimService;

header('Content-Type: text/plain; charset=UTF-8');

// 1. Config check
$contact = (string)EnvLoader::get('NOMINATIM_CONTACT', '');
echo "=== Config ===\n";
echo "NOMINATIM_CONTACT: " . ($contact !== '' ? $contact : '(empty — feature disabled!)') . "\n\n";

if ($contact === '') {
    die("NOMINATIM_CONTACT is not set in .env. Set it to any URL or email and retry.\n");
}

// 2. Outbound connectivity check
echo "=== Connectivity ===\n";
$testUrl = 'https://nominatim.openstreetmap.org/status.php?format=json';
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $testUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT => 'ondisos-debug (' . $contact . ')',
    CURLOPT_TIMEOUT => 10,
]);
$resp = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp === false || $err !== '') {
    echo "curl ERROR: $err\n";
    echo "→ Outbound HTTPS to nominatim.openstreetmap.org is BLOCKED.\n\n";
} else {
    echo "HTTP $code — " . ($code === 200 ? "OK, Nominatim reachable" : "Unexpected code") . "\n";
    echo "Response: $resp\n\n";
}

// 3. Look for anmeldungen with Teilort='autofill'
echo "=== Anmeldungen with Teilort='autofill' ===\n";
$repo = new AnmeldungRepository();

// If ?id=X is given, inspect just that one
$specificId = isset($_GET['id']) ? (int)$_GET['id'] : null;
if ($specificId) {
    $all = $repo->findByIds([$specificId]);
} else {
    $all = $repo->findForExport(null);
}

$autofillCandidates = [];
$fieldVariants = [];

foreach ($all as $a) {
    $data = $a->data ?? [];
    if (!is_array($data)) continue;

    // Check all keys that look like "teilort" (case-insensitive)
    foreach ($data as $k => $v) {
        if (stripos($k, 'teilort') !== false) {
            $fieldVariants[$k][] = $a->id;
        }
    }

    if (($data['Teilort'] ?? null) === 'autofill') {
        $autofillCandidates[] = $a;
    }
}

echo "Field name variants found (case-insensitive 'teilort' search):\n";
foreach ($fieldVariants as $key => $ids) {
    echo "  '$key' → in IDs: " . implode(', ', array_slice($ids, 0, 10)) . "\n";
}
echo "\nEntries with Teilort='autofill': " . count($autofillCandidates) . "\n\n";

if (empty($autofillCandidates)) {
    if ($specificId) {
        echo "ID $specificId: Teilort value = " . var_export($all[0]->data['Teilort'] ?? '(field missing)', true) . "\n";
    } else {
        echo "Nothing to enrich. Either no entries have Teilort='autofill', or the field is named differently.\n";
    }
    die();
}

// 4. Test Nominatim for first candidate (max 3)
echo "=== Nominatim lookup (first 3 candidates) ===\n";
$service = new NominatimService();
$tested = 0;

foreach ($autofillCandidates as $a) {
    if ($tested >= 3) break;
    $d = $a->data;
    $hausnr  = (string)($d['HausNr']  ?? '');
    $strasse = (string)($d['Strasse'] ?? '');
    $plz     = (string)($d['PLZ']     ?? '');
    $ort     = (string)($d['Ort']     ?? '');

    echo "ID {$a->id}: HausNr='$hausnr' Strasse='$strasse' PLZ='$plz' Ort='$ort'\n";

    // Raw curl to see full Nominatim response
    $params = http_build_query([
        'street' => trim("$hausnr $strasse"),
        'city'   => $ort,
        'postalcode' => $plz,
        'addressdetails' => '1',
        'format' => 'json',
        'limit'  => '1',
    ]);
    $rawUrl = 'https://nominatim.openstreetmap.org/search?' . $params;
    echo "URL: $rawUrl\n";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $rawUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'ondisos-debug (' . $contact . ')',
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);

    if ($raw === false) {
        echo "curl FAILED\n";
    } else {
        $json = json_decode($raw, true);
        if (empty($json)) {
            echo "Nominatim: NO RESULTS for this address\n";
            echo "Raw: $raw\n";
        } else {
            $suburb  = $json[0]['address']['suburb']  ?? null;
            $quarter = $json[0]['address']['quarter'] ?? null;
            echo "suburb: " . var_export($suburb, true) . "\n";
            echo "quarter: " . var_export($quarter, true) . "\n";
            echo "Result via service->getSuburb(): '" . $service->getSuburb($hausnr, $strasse, $plz, $ort) . "'\n";
        }
    }
    echo "\n";
    $tested++;
    sleep(1); // Nominatim rate limit
}
