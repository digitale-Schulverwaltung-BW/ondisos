#!/usr/bin/env php
<?php
/**
 * Password Hash Generator
 *
 * Usage:
 *   php scripts/generate-password-hash.php
 *   php scripts/generate-password-hash.php "my-password"
 */

declare(strict_types=1);

$password = $argv[1] ?? null;

if ($password === null) {
    echo "=== Password Hash Generator ===\n\n";
    echo "Enter password: ";
    $password = trim(fgets(STDIN));
}

if (empty($password)) {
    echo "Error: Password cannot be empty\n";
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

echo "\n";
echo "Password Hash:\n";
echo "--------------------------------------------------------------------------------\n";
echo $hash . "\n";
echo "--------------------------------------------------------------------------------\n";
echo "\n";
echo "Add this to your .env file:\n";
echo "ADMIN_PASSWORD_HASH=" . $hash . "\n";
echo "\n";
