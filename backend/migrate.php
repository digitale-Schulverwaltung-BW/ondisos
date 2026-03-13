<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;
use App\Config\EnvLoader;

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "Error: .env file not found at {$envFile}\n");
    exit(1);
}

EnvLoader::load($envFile);

$apiSecret = EnvLoader::get('API_SECRET_KEY');
if (empty($apiSecret)) {
    fwrite(STDERR, "Error: API_SECRET_KEY not set in .env\n");
    exit(1);
}

try {
    $db = Database::getConnection();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "Error: Cannot connect to database — {$e->getMessage()}\n");
    exit(1);
}

try {
    // Step 1: Create tenants table
    echo "Step 1: Create tenants table... ";
    $db->query("CREATE TABLE IF NOT EXISTS tenants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        api_secret VARCHAR(255) NOT NULL,
        active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "OK\n";

    // Step 2: Create tenant_admins table
    echo "Step 2: Create tenant_admins table... ";
    $db->query("CREATE TABLE IF NOT EXISTS tenant_admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        username VARCHAR(100) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        is_platform_admin TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_tenant_username (tenant_id, username),
        FOREIGN KEY fk_tenant_admin_tenant (tenant_id)
            REFERENCES tenants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "OK\n";

    // Step 3: Create form_configs table
    echo "Step 3: Create form_configs table... ";
    $db->query("CREATE TABLE IF NOT EXISTS form_configs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        form_key VARCHAR(100) NOT NULL,
        config_json LONGTEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_tenant_form (tenant_id, form_key),
        FOREIGN KEY fk_form_config_tenant (tenant_id)
            REFERENCES tenants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "OK\n";

    // Step 4: Seed default tenant
    echo "Step 4: Seed default tenant... ";
    $stmt = $db->prepare(
        "INSERT IGNORE INTO tenants (id, name, api_secret, active, created_at) VALUES (1, 'Default', ?, 1, NOW())"
    );
    $stmt->bind_param('s', $apiSecret);
    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        echo "OK\n";
    } else {
        echo "SKIPPED (already exists)\n";
    }
    $stmt->close();

    // Step 5: Add tenant_id column to anmeldungen
    echo "Step 5: Add tenant_id column to anmeldungen... ";
    $dbName = EnvLoader::require('DB_NAME');
    $stmt = $db->prepare(
        "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'anmeldungen' AND COLUMN_NAME = 'tenant_id'"
    );
    $stmt->bind_param('s', $dbName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)$row['cnt'] === 0) {
        $db->query("ALTER TABLE anmeldungen ADD COLUMN tenant_id INT NOT NULL DEFAULT 1");
        echo "OK\n";
    } else {
        echo "SKIPPED (already exists)\n";
    }

    // Step 6: Add indexes on anmeldungen.tenant_id
    $indexes = [
        'idx_tenant'          => '(tenant_id)',
        'idx_tenant_formular' => '(tenant_id, formular)',
        'idx_tenant_status'   => '(tenant_id, status)',
    ];
    foreach ($indexes as $indexName => $columns) {
        echo "Step 6 [{$indexName}]: Add index on anmeldungen... ";
        $stmt = $db->prepare(
            "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'anmeldungen' AND INDEX_NAME = ?"
        );
        $stmt->bind_param('ss', $dbName, $indexName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int)$row['cnt'] === 0) {
            $db->query("ALTER TABLE anmeldungen ADD INDEX {$indexName} {$columns}");
            echo "OK\n";
        } else {
            echo "SKIPPED (already exists)\n";
        }
    }

    // Step 7: Add foreign key anmeldungen → tenants
    echo "Step 7: Add foreign key fk_anmeldung_tenant... ";
    $constraintName = 'fk_anmeldung_tenant';
    $stmt = $db->prepare(
        "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = 'anmeldungen' AND CONSTRAINT_NAME = ?"
    );
    $stmt->bind_param('ss', $dbName, $constraintName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)$row['cnt'] === 0) {
        $db->query(
            "ALTER TABLE anmeldungen
             ADD CONSTRAINT fk_anmeldung_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)"
        );
        echo "OK\n";
    } else {
        echo "SKIPPED (already exists)\n";
    }

    echo "Migration complete.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    exit(1);
}
