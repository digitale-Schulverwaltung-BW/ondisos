# Phase 3: Form Config, Frontend, and Tenant Management UI - Context

**Gathered:** 2026-03-15
**Status:** Ready for planning

<domain>
## Phase Boundary

DB-driven form configuration (replacing forms-config.php), frontend fetching config from backend API instead of local file, a backend UI for platform admins to manage tenants and tenant admins, and scoping ExpungeService to current tenant context. No form config admin UI — seed scripts are sufficient for v3.0 (full CRUD UI is v3.1 scope).

</domain>

<decisions>
## Implementation Decisions

### Tenant Management UI — Structure
- "Tenants" is a top-level nav item in the main header nav, visible only to platform admins (alongside Submissions, Dashboard, etc.)
- `/tenants.php` — list page showing all tenants; each row links to edit
- `/tenants.php?id=3` — edit page showing tenant fields at top, tenant admin list section below
- Platform admin only — tenant admins have no visibility into tenant config

### Tenant Management UI — List page
- Each row shows: tenant name, slug, active status badge (green/red)
- No admin count or created date columns

### Tenant Management UI — Tenant edit page
- Editable fields: name, CORS origin, active toggle
- API secret shown as masked (***) with a "Regenerate" button — regeneration shows new secret once in a highlighted box
- Slug shown read-only (not editable after creation)
- Tenant admin management lives as a section on the same page: list of admins, each with an active/disable toggle, plus an "Add Admin" button
- Disabling a tenant is an immediate toggle — no confirmation dialog

### Tenant creation workflow
- API secret is auto-generated on creation (cryptographically secure random bytes), shown once in a highlighted box after creation — never shown again
- Platform admin sets the initial password for new tenant admin accounts (no auto-generation)
- Platform admin can reset a tenant admin's password at any time via the edit section on the tenant page
- Slug field is pre-filled based on the tenant name (lowercased, spaces → hyphens), but editable — input rejects non-URL-safe characters in real time

### Form config DB schema
- All form configuration stored as a JSON blob in a single `config` column on the `form_configs` table (mirrors the existing PHP array structure exactly — no data loss, no nested schema)
- `form_configs` table: `id`, `tenant_id`, `form_key`, `config` (JSON), `active`
- `forms-config.php` is deleted after the seed script successfully migrates all entries to DB with `tenant_id=1`
- `FormConfig.php` is DB-only: `FormConfig::get($formKey)` queries `WHERE form_key = ? AND tenant_id = TenantContext::getTenantId()`. No file-based fallback.
- Single-tenant mode works transparently — TenantContext is always initialized to 1, so the same query path is used
- Seed script is a prerequisite for running v3.0+ — documented in upgrade instructions (run `migrate.php` then `seed-forms.php`)

### Frontend config fetch strategy
- Form config is fetched PHP-side at page load — `index.php` calls `BackendApiClient` to hit `/api/form-config.php?form=bs&tenant=<slug>` before rendering the page
- If the backend API is unreachable, render a user-friendly error page ("Formular vorübergehend nicht verfügbar") — no partial renders or JS fallback
- Tenant identity passed via query param: `?tenant=<slug>`. Frontend `.env` has `TENANT_SLUG=berufsschule-musterstadt`; `index.php` appends it to all API calls (config fetch, submit, upload)
- New backend endpoint: `backend/public/api/form-config.php` — returns JSON config for the given `?form=` and `?tenant=` params

### ExpungeService tenant scoping (ISOL-04)
- Mechanical fix: `ExpungeService::autoExpunge()` must scope `findExpiredArchived()` to current tenant context (already handled by the Phase 2 repository isolation — confirm it applies here and add a test)

### Claude's Discretion
- Exact Bootstrap 5 markup and styling for the tenant management pages (consistent with existing admin pages)
- HMAC authentication for `/api/form-config.php` (public read is likely fine since form config is not sensitive — or use the same per-tenant HMAC as submit.php)
- Exact error page template for frontend API unreachable
- Test structure for FormConfig DB-backed read and tenant scoping

</decisions>

<code_context>
## Existing Code Insights

### Reusable Assets
- `backend/inc/header.php`: Add "Tenants" nav item here — already modified in Phase 2 for tenant switcher dropdown. Platform admin check via `$_SESSION['is_platform_admin']`.
- `backend/src/Config/FormConfig.php`: Static singleton that currently reads from a PHP file — replace load logic with DB query via `Database::getInstance()`. Cache per request in `static ?array $config`.
- `backend/src/Services/BackendApiClient.php` (frontend): Existing HTTP client for backend calls — use for the new form-config fetch in `index.php`
- `frontend/src/Config/FormConfig.php`: Reads from `forms-config.php` locally — replace with pass-through from data fetched server-side (or a new `FormConfigApiClient`)
- `backend/src/Services/ExpungeService.php`: `autoExpunge()` calls `$this->repository->findExpiredArchived($daysOld)` — Phase 2 repository isolation should already scope this; verify and add test
- `backend/src/Repositories/AnmeldungRepository.php`: `findExpiredArchived()` must be tenant-scoped (part of Phase 2 repository isolation — confirm it was included)

### Established Patterns
- Static singleton with per-request cache: `Config`, `Database`, `FormConfig` — `FormConfig` DB read follows same pattern
- Admin pages bootstrap: `backend/inc/bootstrap.php` + `backend/inc/header.php` + `backend/inc/footer.php` — new `tenants.php` follows the same include structure
- CSRF protection on all POST forms (existing in `CsrfProtection` utility) — tenant create/edit forms use this
- Brute-force delay in login — not applicable to tenant management (platform admin only, already authenticated)
- `openssl_random_pseudo_bytes(32)` pattern for secret generation (used in `PdfTokenService`) — reuse for API secret generation

### Integration Points
- `backend/inc/header.php`: Add "Tenants" nav link, conditional on `$_SESSION['is_platform_admin']`
- `backend/public/api/form-config.php`: New file — tenant resolution via `?tenant=slug`, config read from DB via `FormConfig::get()`
- `frontend/public/index.php`: Replace `FormConfig::load()` + local file read with `BackendApiClient` call to `/api/form-config.php`
- `frontend/.env`: Add `TENANT_SLUG=` variable
- `backend/migrate.php` / new `seed-forms.php`: Seed script reads existing `forms-config.php`, inserts rows into `form_configs` table with `tenant_id=1`, then deletes the file (or outputs a reminder to delete it manually)

</code_context>

<specifics>
## Specific Ideas

- Slug field: pre-filled from name on input event, but manually editable — real-time sanitization rejects non-URL-safe characters (spaces, special chars) as user types
- API secret regeneration: "Regenerate" button shows new secret in a highlighted box with a copy icon — clearly labeled "Diese Zugangsdaten sicher verwahren — werden nur einmal angezeigt"
- The `form_configs` table already exists from Phase 1 (schema created by migrate.php) — the Phase 3 seed script only needs to INSERT rows, not CREATE the table

</specifics>

<deferred>
## Deferred Ideas

- Form config admin UI (CRUD via backend UI) — v3.1.0 scope (explicitly out of scope in PROJECT.md)
- Tenant admin self-service password reset — not needed (intranet tool, platform admin handles it)
- Per-tenant rate limiting — out of scope (REQUIREMENTS.md)
- Managed multi-tenant frontend (Scenario B, MTFE-01) — v3.0.5 scope

</deferred>

---

*Phase: 03-form-config-frontend-and-tenant-management-ui*
*Context gathered: 2026-03-15*
