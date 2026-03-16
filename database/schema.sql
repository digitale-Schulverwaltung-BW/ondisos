-- Ondisos Database Schema
-- School Registration System
-- Keep this file in sync with migrate.php (full current state, no ALTER TABLE)

-- Create database (optional, can be done manually)
-- CREATE DATABASE IF NOT EXISTS anmeldung CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE anmeldung;

-- Tenants
CREATE TABLE IF NOT EXISTS tenants (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(255)  NOT NULL,
    slug          VARCHAR(100)  NULL,
    origin        VARCHAR(255)  NULL,
    api_secret    VARCHAR(255)  NOT NULL,
    active        TINYINT(1)    DEFAULT 1,
    created_at    DATETIME      DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tenant admins
CREATE TABLE IF NOT EXISTS tenant_admins (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT          NOT NULL,
    username          VARCHAR(100) NOT NULL,
    password_hash     VARCHAR(255) NOT NULL,
    is_platform_admin TINYINT(1)   DEFAULT 0,
    active            TINYINT(1)   NOT NULL DEFAULT 1,
    created_at        DATETIME     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_username (tenant_id, username),
    FOREIGN KEY fk_tenant_admin_tenant (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-tenant form configs
CREATE TABLE IF NOT EXISTS form_configs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT          NOT NULL,
    form_key    VARCHAR(100) NOT NULL,
    config_json LONGTEXT     NOT NULL,
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_form (tenant_id, form_key),
    FOREIGN KEY fk_form_config_tenant (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registrations (main data table)
CREATE TABLE IF NOT EXISTS anmeldungen (
    id               INT(11) AUTO_INCREMENT PRIMARY KEY,
    tenant_id        INT          NOT NULL DEFAULT 1,
    formular         VARCHAR(100) NOT NULL,
    formular_version VARCHAR(50)  NULL,
    name             VARCHAR(255) NULL,
    email            VARCHAR(255) NULL,
    status           VARCHAR(30)  DEFAULT 'neu',
    data             LONGTEXT     NOT NULL,
    pdf_config       LONGTEXT     NULL COMMENT 'JSON: PDF-Konfiguration aus Frontend',
    created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted          TINYINT(1)   DEFAULT 0,
    deleted_at       DATETIME     NULL,
    INDEX idx_formular          (formular),
    INDEX idx_email             (email),
    INDEX idx_created           (created_at),
    INDEX idx_tenant            (tenant_id),
    INDEX idx_tenant_formular   (tenant_id, formular),
    INDEX idx_tenant_status     (tenant_id, status),
    FOREIGN KEY fk_anmeldung_tenant (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: default tenant (id=1) so existing data and FK constraint are satisfied
INSERT IGNORE INTO tenants (id, name, slug, api_secret, active, created_at)
VALUES (1, 'Default', 'default', 'CHANGE_ME_IN_PRODUCTION', 1, NOW());
