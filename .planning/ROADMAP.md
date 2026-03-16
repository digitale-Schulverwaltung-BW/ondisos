# Roadmap: Ondisos v3.0.0 Multi-Tenant

## Milestones

- ✅ **v2.0–v2.6** - Single-tenant foundation (shipped February 2026)
- 🚧 **v3.0.0 Multi-Tenant** - Phases 1-3 (in progress)

## Overview

v3.0.0 converts ondisos from a single-tenant system to a multi-tenant capable platform while maintaining zero-overhead backward compatibility for existing deployments. The build order is dictated by hard dependencies: database schema first (nothing else compiles without it), then tenant-aware auth and data isolation (DSGVO-critical, must be verified before any UI exposes data), then form config migration and platform management UI (additive once isolation is proven correct).

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: DB Schema and Foundation** - Migration runner, new tables, TenantContext singleton, backward compatibility (completed 2026-03-13)
- [ ] **Phase 2: Auth, Data Isolation, and API Security** - Multi-role auth, all repository methods tenant-scoped, per-tenant HMAC, file isolation, audit trail
- [ ] **Phase 3: Form Config, Frontend, and Tenant Management UI** - DB-driven form config, frontend tenant parameter, platform admin CRUD, ExpungeService fix

## Phase Details

### Phase 1: DB Schema and Foundation
**Goal**: The database has tenant infrastructure and every request knows which tenant it belongs to
**Depends on**: Nothing (first phase)
**Requirements**: SCHEMA-01, SCHEMA-02, SCHEMA-03, SCHEMA-04, SCHEMA-05
**Success Criteria** (what must be TRUE):
  1. Running `migrate.php` on a v2.6 database creates `tenants`, `tenant_admins`, `form_configs` tables and adds `tenant_id` to `anmeldungen` with no data loss
  2. All existing `anmeldungen` rows are assigned `tenant_id = 1` and the default tenant is seeded with the existing `API_SECRET_KEY`
  3. A unit test proves `TenantContext::getTenantId()` throws when called before initialization
  4. The v2.6 admin backend loads and operates normally after migration (single-tenant mode, `MULTI_TENANT_ENABLED=false`)
  5. Running the migration twice is idempotent — no errors, no duplicate data
**Plans**: 3 plans

Plans:
- [ ] 01-01-PLAN.md — TenantContext singleton class + PHPUnit unit tests (TDD)
- [ ] 01-02-PLAN.md — migrate.php CLI migration script (tables, tenant_id column, seed)
- [ ] 01-03-PLAN.md — bootstrap.php integration (TenantContext initialization in single-tenant mode)

### Phase 2: Auth, Data Isolation, and API Security
**Goal**: Tenant data is strictly isolated at every layer and only authorized users can access each tenant's records
**Depends on**: Phase 1
**Requirements**: AUTH-01, AUTH-02, AUTH-03, AUTH-04, ISOL-01, ISOL-02, ISOL-03, ISOL-05, MGMT-03, FORM-04
**Success Criteria** (what must be TRUE):
  1. A platform admin can log in with `.env` credentials and sees all tenants; a tenant admin logs in with username + password (no tenant selector) and sees only their own tenant's submissions
  2. An integration test proves every `AnmeldungRepository` method returns zero results for Tenant B when called in Tenant A's context — including `findById`, `findDeleted`, `getStatistics`, `getAllFormNames`, and bulk methods
  3. Calling `findById` with a valid ID belonging to another tenant returns `null` (IDOR prevention verified by test)
  4. File uploads land in `uploads/tenant-{id}/` directories; a tenant admin cannot download a file from another tenant's directory
  5. Every audit log entry written after Phase 2 ships contains a `tenant_id` field
  6. Submitting a form with the wrong per-tenant HMAC secret is rejected with HTTP 401; the correct secret is accepted
**Plans**: 7 plans

Plans:
- [ ] 02-01-PLAN.md — TenantContext isAllTenants() + TenantRepository + migrate.php slug/origin columns
- [ ] 02-02-PLAN.md — Wave 0 test scaffolds (failing stubs for all Phase 2 requirements)
- [ ] 02-03-PLAN.md — Dual-path login + bootstrap TenantContext resolution + auth.php multi-tenant extension
- [ ] 02-04-PLAN.md — AnmeldungRepository tenant_id isolation (all 15 methods) + integration tests (DSGVO proof)
- [ ] 02-05-PLAN.md — File upload path isolation + AuditLogger tenant_id injection
- [ ] 02-06-PLAN.md — HMAC validation in submit.php and upload.php + per-tenant CORS
- [ ] 02-07-PLAN.md — Tenant switcher UI in navbar + human verification checkpoint

### Phase 3: Form Config, Frontend, and Tenant Management UI
**Goal**: Platform admins can manage tenants and form configurations without code changes, and tenant frontends authenticate with per-tenant credentials
**Depends on**: Phase 2
**Requirements**: MGMT-01, MGMT-02, ISOL-04, FORM-01, FORM-02, FORM-03, FORM-05
**Success Criteria** (what must be TRUE):
  1. A platform admin can create a tenant, create a tenant admin account for it, and enable/disable the tenant — all via the backend UI without touching config files or the database directly
  2. Existing forms configured in `forms-config.php` are retrievable from the `form_configs` DB table after running the seed script, and `forms-config.php` is deleted — the system still serves forms correctly
  3. A frontend passing `?form=bs&tenant=5` receives the correct form configuration for tenant 5 from the backend API
  4. Auto-expunge only deletes records belonging to the current tenant context — a tenant with `AUTO_EXPUNGE_DAYS=90` does not trigger deletion of records in other tenants
  5. The complete onboarding flow works end-to-end: platform admin creates tenant, sets API secret, tenant frontend submits a registration, tenant admin sees only that registration in the backend
**Plans**: 7 plans

Plans:
- [ ] 03-01-PLAN.md — Wave 0: all test stubs (RED) + TenantAdminRepository skeleton
- [ ] 03-02-PLAN.md — TenantRepository write methods + TenantAdminRepository full impl + migrate.php active column
- [ ] 03-03-PLAN.md — ISOL-04: ExpungeServiceTenantScopingTest (GREEN against existing code)
- [ ] 03-04-PLAN.md — tenants.php UI (list, create, edit, admin management) + header nav link
- [ ] 03-05-PLAN.md — backend FormConfig DB-backed rewrite + form-config.php API endpoint
- [ ] 03-06-PLAN.md — frontend: BackendApiClient.fetchFormConfig() + index.php tenant wiring + FormConfig array-injection
- [ ] 03-07-PLAN.md — seed-forms.php migration script + human verification checkpoint

## Progress

**Execution Order:**
Phases execute in strict sequential order: 1 → 2 → 3

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. DB Schema and Foundation | 3/3 | Complete   | 2026-03-13 |
| 2. Auth, Data Isolation, and API Security | 6/7 | In Progress|  |
| 3. Form Config, Frontend, and Tenant Management UI | 5/7 | In Progress|  |
