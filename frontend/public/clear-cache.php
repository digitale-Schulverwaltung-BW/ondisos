<?php
// Clear OPcache script
// Delete this file after use for security!

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

echo "=== PHP OPcache Reset ===\n\n";

// Check if OPcache is enabled
if (!function_exists('opcache_reset')) {
    echo "❌ OPcache is not enabled or not available\n";
    exit(1);
}

// Get OPcache status before reset
$statusBefore = opcache_get_status();
echo "OPcache status before reset:\n";
echo "- Enabled: " . ($statusBefore ? 'Yes' : 'No') . "\n";
if ($statusBefore) {
    echo "- Cached scripts: " . ($statusBefore['num_cached_scripts'] ?? 'N/A') . "\n";
    echo "- Memory used: " . round(($statusBefore['memory_usage']['used_memory'] ?? 0) / 1024 / 1024, 2) . " MB\n";
}
echo "\n";

// Reset OPcache
$result = opcache_reset();

if ($result) {
    echo "✅ OPcache reset successful!\n\n";

    // Wait a moment and check status again
    sleep(1);
    opcache_reset(); // Reset again to be sure

    $statusAfter = opcache_get_status();
    echo "OPcache status after reset:\n";
    echo "- Enabled: " . ($statusAfter ? 'Yes' : 'No') . "\n";
    if ($statusAfter) {
        echo "- Cached scripts: " . ($statusAfter['num_cached_scripts'] ?? 'N/A') . "\n";
        echo "- Memory used: " . round(($statusAfter['memory_usage']['used_memory'] ?? 0) / 1024 / 1024, 2) . " MB\n";
    }

    echo "\n✅ All clear! PHP will now reload all files from disk.\n";
    echo "\n⚠️  IMPORTANT: Delete this file for security!\n";
    echo "    rm " . __FILE__ . "\n";
} else {
    echo "❌ OPcache reset failed!\n";
    echo "This can happen if:\n";
    echo "- OPcache is disabled\n";
    echo "- opcache.restrict_api is configured\n";
    echo "- Insufficient permissions\n";
    exit(1);
}
