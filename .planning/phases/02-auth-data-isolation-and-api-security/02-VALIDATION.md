---
phase: 2
slug: auth-data-isolation-and-api-security
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-13
---

# Phase 2 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 10.5 |
| **Config file** | `backend/phpunit.xml` |
| **Quick run command** | `cd backend && composer test -- --testsuite=Unit` |
| **Full suite command** | `cd backend && composer test` |
| **Estimated runtime** | ~30 seconds (unit), ~60 seconds (full with integration) |

---

## Sampling Rate

- **After every task commit:** Run `cd backend && composer test -- --testsuite=Unit`
- **After every plan wave:** Run `cd backend && composer test`
- **Before `/gsd:verify-work`:** Full suite must be green (including Integration tests)
- **Max feedback latency:** ~30 seconds (unit suite)

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| AUTH-01 | 01 | 0 | AUTH-01 | unit | `cd backend && composer test:filter LoginPlatformAdminTest` | ❌ W0 | ⬜ pending |
| AUTH-02 | 01 | 0 | AUTH-02 | unit | `cd backend && composer test:filter LoginTenantAdminTest` | ❌ W0 | ⬜ pending |
| AUTH-03 | 01 | 0 | AUTH-03 | unit | `cd backend && composer test:filter LoginTenantAdminTest` | ❌ W0 | ⬜ pending |
| AUTH-04 | 01 | 0 | AUTH-04 | unit | `cd backend && composer test:filter SessionStructureTest` | ❌ W0 | ⬜ pending |
| ISOL-01 | 02 | 0 | ISOL-01 | integration | `cd backend && composer test -- --testsuite=Integration` | ❌ W0 | ⬜ pending |
| ISOL-02 | 02 | 1 | ISOL-02 | unit | `cd backend && composer test:filter UploadIsolationTest` | ❌ W0 | ⬜ pending |
| ISOL-03 | 02 | 1 | ISOL-03 | unit | `cd backend && composer test:filter AuditLoggerTenantTest` | ❌ W0 | ⬜ pending |
| ISOL-05 | 02 | 0 | ISOL-05 | integration | `cd backend && composer test -- --testsuite=Integration --filter IsolationTest` | ❌ W0 | ⬜ pending |
| MGMT-03 | 03 | 1 | MGMT-03 | unit | `cd backend && composer test:filter HmacValidationTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Files created by **Plan 02** (Wave 0 test scaffolds):
- [ ] `tests/Unit/Auth/LoginTest.php` — stubs for AUTH-01, AUTH-02, AUTH-03, AUTH-04
- [ ] `tests/Integration/Repositories/AnmeldungRepositoryIsolationTest.php` — stubs for ISOL-01, ISOL-05 (DSGVO-critical)
- [ ] `tests/Unit/Services/HmacValidationTest.php` — stubs for MGMT-03 / FORM-04
- [ ] `tests/Unit/Services/AuditLoggerTenantIdTest.php` — extends ISOL-03 (tenant_id in log entries)
- [ ] `tests/Unit/Upload/UploadPathIsolationTest.php` — stubs for ISOL-02
- [ ] Integration test DB setup: `backend/.env.test` with `DB_NAME=anmeldung_test`

File created by **Plan 01** Task 1 (TDD, not Wave 0 scaffold):
- [ ] `tests/Unit/Config/TenantContextAllTenantsTest.php` — covers `isAllTenants()` and write-guard behavior
  - Note: This file is in Plan 01's `files_modified`, not Plan 02's. It is a TDD test created alongside
    the production code it tests (TenantContext). It is NOT a Wave 0 scaffold created before implementation.

*Note: `tests/Integration/` directory exists but is empty — all integration tests are new.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Platform admin sees tenant switcher dropdown showing all tenants | AUTH-01 | UI rendering requires browser | Log in as platform admin; verify nav shows dropdown with all tenants listed |
| Tenant admin sees only their own tenant's submissions (no cross-tenant data visible) | AUTH-02 | E2E session verification | Log in as tenant admin; verify only own submissions visible in all views |
| Download of another tenant's file is rejected | ISOL-02 | HTTP path traversal test | Attempt to access `uploads/tenant-2/` path while authenticated as tenant 1 admin |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
