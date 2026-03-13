---
phase: 1
slug: db-schema-and-foundation
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-13
---

# Phase 1 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 10.5 |
| **Config file** | `backend/phpunit.xml` |
| **Quick run command** | `cd backend && ./vendor/bin/phpunit --filter TenantContextTest` |
| **Full suite command** | `cd backend && composer test` |
| **Estimated runtime** | ~5 seconds (unit suite) |

---

## Sampling Rate

- **After every task commit:** Run `cd backend && ./vendor/bin/phpunit --filter TenantContextTest`
- **After every plan wave:** Run `cd backend && composer test`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** ~5 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 1-01-01 | 01 | 0 | SCHEMA-04 | unit | `cd backend && ./vendor/bin/phpunit --filter TenantContextTest` | ❌ W0 | ⬜ pending |
| 1-01-02 | 01 | 1 | SCHEMA-04 | unit | `cd backend && ./vendor/bin/phpunit --filter TenantContextTest` | ❌ W0 | ⬜ pending |
| 1-02-01 | 02 | 1 | SCHEMA-01/02/03 | manual | `php backend/migrate.php && php backend/migrate.php` | N/A | ⬜ pending |
| 1-02-02 | 02 | 1 | SCHEMA-05 | manual | Load backend/public/index.php after migration | N/A | ⬜ pending |
| 1-03-01 | 03 | 2 | SCHEMA-05 | unit | `cd backend && composer test` | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `backend/src/Config/TenantContext.php` — class must exist before tests can compile
- [ ] `backend/tests/Unit/Config/TenantContextTest.php` — stubs for SCHEMA-04 (4 test cases minimum)

*Existing infrastructure (PHPUnit 10.5, phpunit.xml, tests/bootstrap.php) is fully in place. Only the new class and its test file are missing.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Migration creates tables and adds tenant_id column | SCHEMA-01, SCHEMA-02 | Requires live v2.6 database | `php backend/migrate.php` — check output for OK on each step |
| Running migration twice produces no errors | SCHEMA-01/02/03/05 | Requires database state | `php backend/migrate.php && php backend/migrate.php` — second run shows SKIPPED for all steps |
| All existing anmeldungen rows have tenant_id=1 | SCHEMA-02 | Requires database query | `mysql -e "SELECT COUNT(*) FROM anmeldungen WHERE tenant_id != 1"` — expect 0 |
| Default tenant seeded with API_SECRET_KEY | SCHEMA-03 | Requires database query | `mysql -e "SELECT api_secret FROM tenants WHERE id=1"` — expect matches .env API_SECRET_KEY |
| v2.6 backend loads normally after migration | SCHEMA-05 | End-to-end smoke test | Load backend/public/index.php with MULTI_TENANT_ENABLED=false — expect no errors |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 10s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
