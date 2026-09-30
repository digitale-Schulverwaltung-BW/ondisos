---
phase: 3
slug: form-config-frontend-and-tenant-management-ui
status: approved
nyquist_compliant: true
wave_0_complete: false
created: 2026-03-15
---

# Phase 3 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 10.5 |
| **Config file** | `backend/phpunit.xml` |
| **Quick run command** | `cd backend && composer test -- --testsuite=Unit` |
| **Full suite command** | `cd backend && composer test` |
| **Estimated runtime** | ~15 seconds |

---

## Sampling Rate

- **After every task commit:** Run `cd backend && composer test -- --testsuite=Unit`
- **After every plan wave:** Run `cd backend && composer test`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 15 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 3-01-01 | 01 | 0 | MGMT-01 | unit | `composer test -- --filter TenantRepositoryWriteTest` | ❌ W0 | ⬜ pending |
| 3-01-02 | 01 | 0 | MGMT-02 | unit | `composer test -- --filter TenantAdminRepositoryTest` | ❌ W0 | ⬜ pending |
| 3-01-03 | 01 | 0 | ISOL-04 | unit | `composer test -- --filter ExpungeServiceTenantScopingTest` | ❌ W0 | ⬜ pending |
| 3-01-04 | 01 | 0 | FORM-01 | unit | `composer test -- --filter FormConfigDbTest` | ❌ W0 | ⬜ pending |
| 3-01-05 | 01 | 0 | FORM-03 | unit | `composer test -- --filter BackendApiClientTest` | ❌ W0 | ⬜ pending |
| 3-02-01 | 02 | 1 | MGMT-01 | unit | `composer test -- --filter TenantRepositoryWriteTest` | ❌ W0 | ⬜ pending |
| 3-02-02 | 02 | 1 | MGMT-02 | unit | `composer test -- --filter TenantAdminRepositoryTest` | ❌ W0 | ⬜ pending |
| 3-03-01 | 03 | 2 | FORM-01 | unit | `composer test -- --filter FormConfigDbTest` | ❌ W0 | ⬜ pending |
| 3-03-02 | 03 | 2 | FORM-03 | unit | `composer test -- --filter BackendApiClientTest` | ❌ W0 | ⬜ pending |
| 3-04-01 | 04 | 3 | FORM-02 | manual | `php backend/seed-forms.php` + verify DB | N/A | ⬜ pending |
| 3-04-02 | 04 | 3 | FORM-05 | manual | Load frontend with backend stopped; verify error page | N/A | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `backend/tests/Unit/Repositories/TenantRepositoryWriteTest.php` — stubs for MGMT-01 (create, update, findAllForAdmin)
- [ ] `backend/tests/Unit/Repositories/TenantAdminRepositoryTest.php` — stubs for MGMT-02 (create, findByTenantId, resetPassword, toggle active)
- [ ] `backend/tests/Unit/Services/ExpungeServiceTenantScopingTest.php` — stubs for ISOL-04 (verifies mock repo receives correct tenant context)
- [ ] `backend/tests/Unit/Config/FormConfigDbTest.php` — stubs for FORM-01 (DB-backed FormConfig::get() with mocked mysqli)
- [ ] `backend/tests/Unit/Services/BackendApiClientTest.php` — stubs for FORM-03 (fetchFormConfig URL construction, error handling)
- [ ] `backend/src/Repositories/TenantAdminRepository.php` — new implementation class (required before Wave 0 tests can run)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| seed-forms.php migrates forms-config.php entries to DB | FORM-02 | CLI script with file I/O; no test harness for file-to-DB migration | Run `php backend/seed-forms.php`; verify rows in form_configs with `SELECT * FROM form_configs WHERE tenant_id=1`; confirm forms-config.php can be deleted |
| Frontend renders error page when backend API unreachable | FORM-05 | Requires stopping the backend service; not mockable in unit tests | Stop backend; load `frontend/public/index.php?form=bs&tenant=default`; verify "Formular vorübergehend nicht verfügbar" page renders |
| Complete onboarding flow end-to-end | Success Criterion 5 | Multi-service integration across frontend + backend + DB | Platform admin creates tenant → sets API secret → frontend submits registration with tenant slug → tenant admin logs in and sees only their registration |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 15s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved (2026-03-15 — post-checker revision; RESEARCH.md Validation Architecture confirmed sampling continuity. Manual-only tasks are isolated to CLI migration and multi-service E2E flows that cannot be unit tested. All automated tasks have grep or composer test verify commands.)
