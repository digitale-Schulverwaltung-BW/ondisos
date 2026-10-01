-- Migration: tables for the form editor (3.1)
-- Idempotent (IF NOT EXISTS); backend/migrate.php applies the same statements.

-- Published surveys and themes per tenant (3.1: forms are maintained in the backend)
CREATE TABLE IF NOT EXISTS form_resources (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id  INT          NOT NULL,
    kind       ENUM('survey','theme') NOT NULL,
    name       VARCHAR(100) NOT NULL,
    content    LONGTEXT     NOT NULL,
    sha256     CHAR(64)     NOT NULL,
    created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by VARCHAR(100) NULL,
    UNIQUE KEY uq_tenant_kind_name (tenant_id, kind, name),
    FOREIGN KEY fk_form_resource_tenant (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One survey draft per form
CREATE TABLE IF NOT EXISTS form_drafts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id    INT          NOT NULL,
    form_key     VARCHAR(100) NOT NULL,
    survey_json  LONGTEXT     NOT NULL,
    based_on_sha CHAR(64)     NULL,
    updated_at   DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by   VARCHAR(100) NULL,
    UNIQUE KEY uq_tenant_form_draft (tenant_id, form_key),
    FOREIGN KEY fk_form_draft_tenant (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- History of config/survey/theme changes (append-only)
CREATE TABLE IF NOT EXISTS form_revisions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id  INT          NOT NULL,
    form_key   VARCHAR(100) NOT NULL,
    kind       ENUM('config','survey','theme') NOT NULL,
    name       VARCHAR(100) NULL,
    content    LONGTEXT     NOT NULL,
    sha256     CHAR(64)     NOT NULL,
    note       VARCHAR(255) NULL,
    created_by VARCHAR(100) NULL,
    created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_form (tenant_id, form_key, created_at),
    FOREIGN KEY fk_form_revision_tenant (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
