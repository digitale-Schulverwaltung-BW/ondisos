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

    // $dbName is needed for INFORMATION_SCHEMA queries throughout the migration
    $dbName = EnvLoader::require('DB_NAME');

    // Step 4b: Add slug and origin columns to tenants (Phase 2)
    echo "Step 4b: Add slug column to tenants... ";
    $stmt = $db->prepare(
        "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'slug'"
    );
    $stmt->bind_param('s', $dbName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)$row['cnt'] === 0) {
        $db->query("ALTER TABLE tenants ADD COLUMN slug VARCHAR(100) NULL");
        echo "ADDED\n";
    } else {
        echo "SKIPPED (already exists)\n";
    }

    // Populate slug from name for rows that have no slug yet
    echo "Step 4b: Populate missing slugs... ";
    $db->query("UPDATE tenants SET slug = LOWER(REPLACE(name, ' ', '-')) WHERE slug IS NULL OR slug = ''");
    $affectedRows = $db->affected_rows;
    echo ($affectedRows > 0 ? "OK ({$affectedRows} rows)" : "SKIPPED (none needed)") . "\n";

    // Guard: verify no NULLs remain before making the column NOT NULL
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM tenants WHERE slug IS NULL");
    $stmt->execute();
    $nullCount = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();

    if ($nullCount === 0) {
        echo "Step 4b: Make slug NOT NULL... ";
        $stmt = $db->prepare(
            "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'slug' AND IS_NULLABLE = 'NO'"
        );
        $stmt->bind_param('s', $dbName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int)$row['cnt'] === 0) {
            $db->query("ALTER TABLE tenants MODIFY COLUMN slug VARCHAR(100) NOT NULL");
            echo "OK\n";
        } else {
            echo "SKIPPED (already NOT NULL)\n";
        }
    } else {
        echo "Step 4b: WARNING — {$nullCount} tenant(s) have NULL slug after UPDATE. Skipping NOT NULL constraint.\n";
    }

    // Add UNIQUE constraint on slug (idempotent)
    echo "Step 4b: Add UNIQUE constraint on slug... ";
    $stmt = $db->prepare(
        "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = 'tenants' AND CONSTRAINT_NAME = 'uq_tenants_slug'"
    );
    $stmt->bind_param('s', $dbName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)$row['cnt'] === 0) {
        $db->query("ALTER TABLE tenants ADD CONSTRAINT uq_tenants_slug UNIQUE (slug)");
        echo "OK\n";
    } else {
        echo "SKIPPED (already exists)\n";
    }

    echo "Step 4c: Add origin column to tenants... ";
    $stmt = $db->prepare(
        "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'origin'"
    );
    $stmt->bind_param('s', $dbName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)$row['cnt'] === 0) {
        $db->query("ALTER TABLE tenants ADD COLUMN origin VARCHAR(255) NULL");
        echo "OK\n";
    } else {
        echo "SKIPPED (already exists)\n";
    }

    // Step 4d: Move existing flat uploads to uploads/tenant-1/ (Phase 2 file isolation)
    echo "Step 4d: Move existing uploads to uploads/tenant-1/... ";
    $uploadsDir  = __DIR__ . '/uploads';
    $tenant1Dir  = $uploadsDir . '/tenant-1';

    if (is_dir($uploadsDir)) {
        if (!is_dir($tenant1Dir)) {
            mkdir($tenant1Dir, 0755, true);
        }

        $moved   = 0;
        $skipped = 0;
        foreach (new DirectoryIterator($uploadsDir) as $item) {
            // Only move direct-child files — skip subdirectories and dot entries
            if (!$item->isFile()) {
                continue;
            }
            $src  = $item->getPathname();
            $dest = $tenant1Dir . '/' . $item->getFilename();

            if (file_exists($dest)) {
                // Idempotent: already moved in a previous run — skip
                $skipped++;
                continue;
            }

            if (rename($src, $dest)) {
                $moved++;
                echo "\n  Moved: " . $item->getFilename();
            } else {
                echo "\n  WARNING: Could not move " . $item->getFilename();
            }
        }
        echo "\nFile migration: {$moved} moved, {$skipped} already in place.\n";
    } else {
        echo "SKIPPED (uploads/ directory does not exist)\n";
    }

    // Step 5: Add tenant_id column to anmeldungen
    echo "Step 5: Add tenant_id column to anmeldungen... ";
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
