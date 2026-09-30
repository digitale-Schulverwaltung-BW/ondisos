---
phase: 02-auth-data-isolation-and-api-security
plan: "05"
subsystem: file-isolation-and-audit-logging
tags:
  - tenant-isolation
  - upload
  - download
  - audit-log
  - DSGVO
  - ISOL-02
  - ISOL-03
dependency_graph:
  requires:
    - 02-04
  provides:
    - Tenant-scoped upload paths (ISOL-02)
    - Tenant-scoped download path validation (ISOL-02)
    - AuditLogger tenant_id auto-injection (ISOL-03)
  affects:
    - backend/public/api/upload.php
    - backend/src/Controllers/DownloadController.php
    - backend/src/Services/AuditLogger.php
tech_stack:
  added: []
  patterns:
    - "TenantContext::getTenantId() in upload.php and DownloadController to scope file paths"
    - "try/catch around TenantContext in AuditLogger for pre-auth resilience"
    - "Public testable methods (getAllowedUploadDir, isWithinAllowedDir) on DownloadController"
key_files:
  created: []
  modified:
    - backend/public/api/upload.php
    - backend/src/Controllers/DownloadController.php
    - backend/src/Services/AuditLogger.php
    - backend/tests/Unit/Upload/UploadPathIsolationTest.php
    - backend/tests/Unit/Services/AuditLoggerTenantIdTest.php
decisions:
  - "DownloadController exposes getAllowedUploadDir() and isWithinAllowedDir() as public methods — unit tests need to call them without HTTP context"
  - "AuditLogger wraps TenantContext in try/catch so pre-auth events (login) never throw; logs tenant_id=null for uninitialized and all-tenants contexts"
metrics:
  duration: "4 minutes"
  completed_date: "2026-03-13"
  tasks_completed: 2
  files_modified: 5
---

# Phase 02 Plan 05: File Upload/Download Isolation and Audit Tenant ID Summary

Tenant-scoped file paths for upload and download, plus tenant_id auto-injection into every audit log entry, fulfilling DSGVO file isolation requirements ISOL-02 and ISOL-03.

## Tasks Completed

### Task 1: Tenant-scoped upload directory + DownloadController tenant path validation

**Commit:** a68c2ed (implementation), 53a60d5 (tests)

**Changes:**

- `upload.php`: replaced `__DIR__ . '/../../uploads'` with `realpath(.../uploads) . '/tenant-' . $tenantId`, directory created on first use.
- `DownloadController.php`: replaced static `UPLOAD_DIR` constant approach with `getAllowedUploadDir()` instance method that calls `TenantContext::getTenantId()`. Added `isWithinAllowedDir(string|false $realPath, string $allowedDir): bool` as a public testable method. `getFilePath()` and `download()` use the tenant directory.
- `UploadPathIsolationTest.php`: `testUploadDirectoryIncludesTenantId` verifies `getAllowedUploadDir()` ends with `tenant-3`. `testCrosstenantPathAccessIsRejected` creates real temp directories for tenant-1 and tenant-2, then verifies `isWithinAllowedDir()` rejects cross-tenant paths and accepts same-tenant paths.

**Result:** 2/2 tests green.

### Task 2: AuditLogger tenant_id auto-injection

**Commit:** 8dd7792 (implementation), b090c02 (tests)

**Changes:**

- `AuditLogger.php`: inside `log()`, added try/catch block that calls `TenantContext::isAllTenants()` then `getTenantId()`. Assigns `$tenantId = null` when context is uninitialized or in all-tenants mode. Appends `'tenant_id' => $tenantId` to the JSON entry between `'ip'` and `'details'`.
- `AuditLoggerTenantIdTest.php`: three tests using the same real-log-override technique from `AuditLoggerTest`. `captureLogEntry()` helper redirects real log file temporarily, captures the written entry. Tests verify integer tenant_id when initialized (5), null when uninitialized, null in all-tenants mode.

**Result:** 3/3 tests green. Existing AuditLoggerTest (rotate tests) unaffected: 9/9 tests green.

## Deviations from Plan

None — plan executed exactly as written.

## Verification

Full set of related tests passing:

```
Tests\Unit\Upload\UploadPathIsolationTest      2 tests   6 assertions   OK
Tests\Unit\Services\AuditLoggerTenantIdTest    3 tests   9 assertions   OK
Tests\Unit\Services\AuditLoggerTest            9 tests  18 assertions   OK
```

Pre-existing failures in the unit suite (HmacValidationTest stubs from Plan 06, RequestExpungeServiceTest DB requirement) are unrelated to this plan.

## Self-Check: PASSED

Files created/modified:
- FOUND: backend/public/api/upload.php
- FOUND: backend/src/Controllers/DownloadController.php
- FOUND: backend/src/Services/AuditLogger.php
- FOUND: backend/tests/Unit/Upload/UploadPathIsolationTest.php
- FOUND: backend/tests/Unit/Services/AuditLoggerTenantIdTest.php

Commits:
- 53a60d5: test(02-05): add failing tests for upload path isolation and cross-tenant validation
- a68c2ed: feat(02-05): tenant-scoped upload directory and download path validation
- b090c02: test(02-05): add failing tests for AuditLogger tenant_id auto-injection
- 8dd7792: feat(02-05): inject tenant_id into every AuditLogger log entry
