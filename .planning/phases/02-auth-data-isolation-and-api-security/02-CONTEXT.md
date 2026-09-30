# Phase 2: Auth, Data Isolation, and API Security - Context

**Gathered:** 2026-03-13
**Status:** Ready for planning

<domain>
## Phase Boundary

Implement multi-role authentication (platform admin + tenant admin), tenant-scoped data isolation across all repository methods, per-tenant HMAC API authentication, tenant-scoped file uploads, and tenant-aware audit logging. This phase makes tenant data strictly isolated at every layer. Tenant CRUD management UI and form config migration are Phase 3.

</domain>

<decisions>
## Implementation Decisions

### Login flow & roles
- Tenant admin usernames are globally unique — no tenant selector needed on login form
- Login form stays simple: username + password only. System resolves tenant automatically from DB lookup on `tenant_admins` table
- Platform admin authenticates via `.env` credentials (existing `ADMIN_USERNAME` / `ADMIN_PASSWORD_HASH`)
- Platform admin sees a tenant switcher dropdown in the nav bar header to switch between tenants; default view shows "All tenants"
- `MULTI_TENANT_ENABLED=true` forces authentication on regardless of `AUTH_ENABLED` setting — multi-tenant without auth is a security hole

### Repository isolation
- All `AnmeldungRepository` methods read `TenantContext::getTenantId()` and add `WHERE tenant_id = ?` automatically — no method signature changes
- `TenantContext` gets an `isAllTenants()` flag for platform admin "all tenants" view — repository methods skip `tenant_id` filter when this is true
- `insert()` auto-injects `tenant_id` from `TenantContext` — caller cannot forget
- IDOR prevention: `findById` with a cross-tenant ID returns `null` + logs the attempt to audit trail (DSGVO incident detection)

### File & audit isolation
- Existing uploads in `uploads/` are moved to `uploads/tenant-1/` during migration (part of `migrate.php`, not a separate script)
- New uploads go to `uploads/tenant-{id}/` for all tenants
- Download access control via path validation: verify requested path starts with `uploads/tenant-{currentTenantId}/`, reject otherwise — no DB lookup needed
- `AuditLogger::log()` auto-injects `tenant_id` from `TenantContext` into every log entry — callers don't change

### API HMAC security
- Frontend identifies tenant via slug in URL parameter: `?tenant=schule-a`
- Backend looks up tenant by slug, validates HMAC signature against that tenant's `api_secret`
- HMAC mechanism: Frontend computes `HMAC-SHA256(request_body, tenant_secret)` and sends as `X-Signature` header. Backend recomputes and compares (timing-safe `hash_equals`)
- Per-tenant CORS: Each tenant record has an `origin` column. Backend validates `Origin` header against the tenant's configured origin
- Generic HTTP 401 for all auth failures (wrong tenant slug, disabled tenant, invalid HMAC) — prevents tenant enumeration

### Claude's Discretion
- Session structure details (`$_SESSION` keys beyond `is_platform_admin` and `allowed_tenant_ids`)
- Tenant switcher UI component design (dropdown placement, styling)
- Exact test structure for repository isolation integration tests
- How `isAllTenants()` interacts with write operations (likely should throw on insert/update/delete in all-tenants mode)
- PDF token generation: whether to scope tokens to tenant context

</decisions>

<code_context>
## Existing Code Insights

### Reusable Assets
- `App\Config\TenantContext`: Already created in Phase 1 with `initialize(int $tenantId)` and `getTenantId()`. Needs `isAllTenants()` flag and `isPlatformAdmin()` added
- `backend/inc/auth.php`: Existing session-based auth check. Needs extension for tenant admin DB lookup and platform admin detection
- `backend/public/login.php`: Existing login form with CSRF protection and brute-force delay. Needs dual-path auth (`.env` for platform admin, DB for tenant admin)
- `App\Services\AuditLogger`: Static class with `log()` method. Needs `tenant_id` field added via `TenantContext::getTenantId()`
- `App\Repositories\AnmeldungRepository`: 15+ methods all need `tenant_id` filtering — `findPaginated`, `findById`, `findForExport`, `findByIds`, `updateStatus`, `bulkUpdateStatus`, `softDelete`, `bulkSoftDelete`, `hardDelete`, `findExpiredArchived`, `getStatistics`, `findDeleted`, `restore`, `insert`, `getAllFormNames`

### Established Patterns
- Static singleton pattern (`TenantContext`, `Config`, `Database`) — tenant resolution follows this pattern
- Prepared statements exclusively in repository — `tenant_id` filter added as additional `WHERE` clause with bind parameter
- `SKIP_AUTH_CHECK` constant used by `login.php` to bypass auth — same pattern usable for API endpoints
- Session keys: `$_SESSION['admin_logged_in']`, `$_SESSION['admin_username']`, `$_SESSION['login_time']` — extend with `is_platform_admin`, `tenant_id`, `allowed_tenant_ids`

### Integration Points
- `backend/inc/bootstrap.php` line 49-52: Phase 2 replaces the `else` branch with real tenant resolution from session or API key
- `backend/public/api/submit.php`: Needs HMAC validation before processing submission
- `backend/public/api/upload.php`: Needs same HMAC validation + tenant-scoped upload directory
- `backend/inc/header.php`: Tenant switcher dropdown for platform admin goes here

</code_context>

<specifics>
## Specific Ideas

- Tenant slug in URL params (`?tenant=schule-a`) should be documented for Phase 3 to use in WP shortcodes and standalone URL params consistently
- The `isAllTenants()` platform admin mode means queries can show a "Tenant" column in admin tables when viewing all tenants — this is UI but affects the data layer

</specifics>

<deferred>
## Deferred Ideas

- WP shortcode and standalone frontend slug parameter usage — Phase 3 scope (FORM-03)
- Tenant CRUD management UI — Phase 3 scope (MGMT-01, MGMT-02)
- Per-tenant rate limiting — explicitly out of scope (REQUIREMENTS.md)

</deferred>

---

*Phase: 02-auth-data-isolation-and-api-security*
*Context gathered: 2026-03-13*
