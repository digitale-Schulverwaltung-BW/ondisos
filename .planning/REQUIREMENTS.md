# Requirements: Ondisos

**Defined:** 2026-03-13
**Core Value:** Schools can collect, manage, and process student registrations through a secure, DSGVO-compliant system that requires minimal technical administration.

## v3.0.0 Requirements

Requirements for multi-tenant capability. Each maps to roadmap phases.

### Schema & Foundation

- [x] **SCHEMA-01**: Database migration creates `tenants`, `tenant_admins`, `form_configs` tables
- [x] **SCHEMA-02**: Existing `anmeldungen` table gains `tenant_id` column with default value 1
- [x] **SCHEMA-03**: Default tenant (id=1) is seeded automatically, inheriting existing `API_SECRET_KEY`
- [x] **SCHEMA-04**: `TenantContext` request-scoped singleton resolves tenant from session or API request
- [x] **SCHEMA-05**: Single-tenant mode (`MULTI_TENANT_ENABLED=false`) operates transparently with tenant_id=1

### Authentication & Authorization

- [x] **AUTH-01**: Platform admin authenticates via `.env` credentials and can manage all tenants
- [x] **AUTH-02**: Tenant admins authenticate via DB-stored credentials and see only their tenant's data
- [ ] **AUTH-03**: Login form uses username + password only — tenant admin usernames are globally unique and the system resolves the tenant automatically (no tenant selector needed)
- [x] **AUTH-04**: Session stores `is_platform_admin` and `tenant_id` for role-based access

### Data Isolation

- [x] **ISOL-01**: All `AnmeldungRepository` methods (~15+) filter by `tenant_id`
- [ ] **ISOL-02**: File uploads stored in tenant-scoped directories (`uploads/tenant-{id}/`)
- [ ] **ISOL-03**: Audit trail entries include `tenant_id` field
- [ ] **ISOL-04**: `ExpungeService` scopes auto-expunge to tenant context (not global)
- [x] **ISOL-05**: `findById` validates tenant ownership (prevents IDOR across tenants)

### Tenant Management

- [ ] **MGMT-01**: Platform admin can create, edit, enable/disable tenants via backend UI
- [ ] **MGMT-02**: Platform admin can create and manage tenant admin accounts via backend UI
- [ ] **MGMT-03**: Per-tenant `api_secret` generated on tenant creation for HMAC authentication

### Form Config & Frontend

- [ ] **FORM-01**: Form configurations stored in `form_configs` DB table per tenant
- [ ] **FORM-02**: Seed scripts migrate existing `forms-config.php` entries to DB for default tenant
- [ ] **FORM-03**: Frontend passes `tenant` parameter in API calls (`?form=bs&tenant=5`)
- [ ] **FORM-04**: Backend API validates per-tenant HMAC signature on `submit.php` and `upload.php`
- [ ] **FORM-05**: Frontend fetches form config from backend API instead of local file

## Future Requirements

Deferred to future releases. Tracked but not in current roadmap.

### v3.0.5

- **MTFE-01**: Managed multi-tenant frontend (Scenario B) with tenant selection/routing UI
- **MTFE-02**: Shared secret model between multi-tenant frontend and backend

### v3.1.0

- **FMUI-01**: Full CRUD admin UI for form configurations in backend
- **FMUI-02**: Survey JSON file upload mechanism from backend to frontend
- **FMUI-03**: Frontend becomes zero-maintenance zone for tenant admins

## Out of Scope

Explicitly excluded. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Separate databases per tenant | Operational overhead not justified at school scale; shared schema is DSGVO-compliant |
| Per-tenant rate limiting | Per-IP approach sufficient; per-tenant adds DB lookup on every request |
| Per-tenant message customization | MessageService is tenant-agnostic; school branding goes in SurveyJS form JSON |
| Self-service tenant signup | Schools onboarded by municipality IT admin, not self-service |
| Real-time chat/notifications | Not related to multi-tenancy; not requested |
| Form config admin UI in v3.0 | Seed scripts sufficient; full UI is v3.1 scope |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| SCHEMA-01 | Phase 1 | Complete |
| SCHEMA-02 | Phase 1 | Complete |
| SCHEMA-03 | Phase 1 | Complete |
| SCHEMA-04 | Phase 1 | Complete |
| SCHEMA-05 | Phase 1 | Complete |
| AUTH-01 | Phase 2 | Complete |
| AUTH-02 | Phase 2 | Complete |
| AUTH-03 | Phase 2 | Pending |
| AUTH-04 | Phase 2 | Complete |
| ISOL-01 | Phase 2 | Complete |
| ISOL-02 | Phase 2 | Pending |
| ISOL-03 | Phase 2 | Pending |
| ISOL-04 | Phase 3 | Pending |
| ISOL-05 | Phase 2 | Complete |
| MGMT-01 | Phase 3 | Pending |
| MGMT-02 | Phase 3 | Pending |
| MGMT-03 | Phase 2 | Pending |
| FORM-01 | Phase 3 | Pending |
| FORM-02 | Phase 3 | Pending |
| FORM-03 | Phase 3 | Pending |
| FORM-04 | Phase 2 | Pending |
| FORM-05 | Phase 3 | Pending |

**Coverage:**
- v3.0.0 requirements: 21 total
- Mapped to phases: 21
- Unmapped: 0 ✓

---
*Requirements defined: 2026-03-13*
*Last updated: 2026-03-13 after roadmap creation*
*Revised: 2026-03-13 — AUTH-03 description corrected to match CONTEXT.md locked decision (no tenant selector); AUTH-04 session key corrected to tenant_id (not allowed_tenant_ids)*
