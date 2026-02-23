-- Migration: Add pdf_config column to anmeldungen table
-- Version: 2026-02-23
-- Description: Stores PDF configuration from frontend submission in DB,
--              so the backend download endpoint does not need a local forms-config.php.

ALTER TABLE anmeldungen
    ADD COLUMN pdf_config LONGTEXT NULL COMMENT 'JSON: PDF-Konfiguration aus Frontend'
    AFTER data;
