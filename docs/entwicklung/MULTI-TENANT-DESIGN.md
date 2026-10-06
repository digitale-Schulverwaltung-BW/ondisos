# Multi-tenant ondisos — architecture and design decisions

Since **3.0**, one ondisos backend can serve several schools (*tenants*) with full data isolation, while a single school keeps
running with the same footprint as before (single-tenant mode, no extra administration).

> This document records the **architecture and the decisions behind it**. For operating a multi-tenant installation see
> [../MULTI-TENANT.md](../betreiber/MULTI-TENANT.md); for upgrading from 2.x see [../MIGRATION-3.0.md](../betreiber/MIGRATION-3.0.md).

Two deployment settings are possible. **Scenario A (implemented in 3.0):** several frontend servers — one per school — feed a single
multi-tenant backend. **Scenario B (dropped, kept as an option):** a managed multi-tenant frontend for several schools, e.g. in one municipality.

## Architectural decisions

### Database: shared schema, tenant tables always present

The tenant schema is **always created**, even in single-tenant mode; a default tenant (id 1, slug `default`) is seeded. This gives a
consistent data model everywhere, a zero-friction path from single- to multi-tenant (flip `MULTI_TENANT_ENABLED`), and no conditional
schema logic — every query includes `tenant_id`. Authoritative schema: `database/schema.sql` (fresh install) and `backend/migrate.php`
(upgrade, idempotent).

```sql
CREATE TABLE tenants (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(255) NOT NULL,
    slug          VARCHAR(100) NULL,        -- unique; addresses the tenant in API calls
    origin        VARCHAR(255) NULL,        -- CORS origin of the tenant's frontend
    api_secret    VARCHAR(255) NOT NULL,    -- HMAC shared secret per tenant
    active        TINYINT(1)   DEFAULT 1,
    created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE tenant_admins (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT NOT NULL,
    username          VARCHAR(100) NOT NULL,
    password_hash     VARCHAR(255) NOT NULL,
    is_platform_admin TINYINT(1) DEFAULT 0,
    active            TINYINT(1) NOT NULL DEFAULT 1,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_username (tenant_id, username),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE form_configs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT NOT NULL,
    form_key    VARCHAR(100) NOT NULL,
    config_json LONGTEXT NOT NULL,          -- form config (replaced forms-config.php)
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_form (tenant_id, form_key),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- anmeldungen: tenant_id INT NOT NULL DEFAULT 1 (+ indexes idx_tenant, idx_tenant_formular, idx_tenant_status, FK to tenants)
```

### Frontend scope: Scenario A first

3.0 targets Scenario A. Each frontend belongs to exactly one tenant: it knows the tenant **slug** and the tenant **secret**
(`TENANT_SLUG`, `TENANT_API_SECRET`), sends the slug with every API call and signs requests with the secret. No tenant switching is
needed in the frontend. Scenario B is not planned (see below).

### API security: per-tenant HMAC

Each tenant has an `api_secret`. The frontend signs `submit.php` (over the raw body) and `upload.php` (over
`"{anmeldung_id}:{fieldname}:{filename}"`) with HMAC-SHA256 and sends the tenant slug as `?tenant=`. The slug **addresses** the tenant;
only the signature **authorizes**. Verification: `HmacValidator`; publicly known secrets (schema placeholder, dev default in
production) are rejected by `SecretPolicy`. In single-tenant mode, tenant 1's secret is `API_SECRET_KEY`. Uploads are additionally
checked for ownership: the target Anmeldung must belong to the authenticated tenant.

The signature carries no timestamp, so HTTPS between frontend and backend is required in production.

### Form config: database storage, seed in 3.0, admin UI in 3.1

Form configurations live in `form_configs`. In 3.0 they are created by `seed-forms.php` (tenant 1; adds new forms, never overwrites) or by SQL.
The frontend **fetches the config from the backend on every form request** (`GET /api/form-config.php?form=…&tenant=…`, via
`FormConfigLoader`). The alternative considered — pushing survey files/config to the frontend — was dropped in favour of pulling from the backend in 3.1; the survey JSON files
still live on the frontend. The endpoint is reachable per slug without a signature, so a form config must not contain secrets.

### Tenant admin management: backend UI

The platform admin creates and manages tenants and tenant admins in `tenants.php`. The tenant secret is shown once on creation or regeneration.

### Audit trail: single file with tenant id

`audit.log` stays a single JSON-Lines file; each entry carries `tenant_id`. IDOR attempts are logged as `idor_attempt`.

### Tenant context propagation

`TenantContext` (request-scoped static singleton) is initialized once per request in `inc/bootstrap.php`; services and repositories read
`TenantContext::getTenantId()` instead of threading ids through signatures. An uninitialized context throws — it never silently falls back to
"all tenants". All-tenants mode (platform admin) is explicit (`isAllTenants()`).

| Situation | Tenant |
|---|---|
| `MULTI_TENANT_ENABLED=false` | always tenant 1 |
| API request (`?tenant=<slug>`) | tenant of the slug; unknown/inactive ⇒ uninitialized ⇒ 401 |
| Browser, tenant admin | from the session |
| Browser, platform admin | switched tenant or all tenants (`?switch_tenant=`) |
| `pdf/download.php` (token) | tenant of the Anmeldung the token authorizes (`findTenantIdById()`) |

## Backend

Multi-tenant operation is enabled with `MULTI_TENANT_ENABLED=true`; this **forces** the login (regardless of `AUTH_ENABLED`).

### Authentication and roles

- **Platform admin:** credentials in `.env` (`ADMIN_USERNAME`, `ADMIN_PASSWORD_HASH`). Manages all tenants, creates tenant admins, may see all data or switch into one tenant.
- **Tenant admin:** stored in `tenant_admins`, created by the platform admin. Sees only their own tenant.

### Repository layer

All `AnmeldungRepository` methods filter by `tenant_id` (from `TenantContext`; skipped only in all-tenants mode). `findById()` distinguishes
"does not exist" from "belongs to another tenant" and logs the latter as an IDOR attempt.

### File upload isolation

```
backend/uploads/
├── tenant-1/
│   ├── 123_document.pdf
│   └── 124_passport.jpg
└── tenant-5/
    └── 200_certificate.pdf
```

Existing flat uploads are moved to `tenant-1/` by `migrate.php`. Downloads are checked with `realpath()` against the active tenant's directory.

### Environment

```bash
MULTI_TENANT_ENABLED=false     # true = multi-tenant mode (login forced, tenant management, switcher)
ADMIN_USERNAME=admin           # platform admin (required when multi-tenant)
ADMIN_PASSWORD_HASH='$2y$…'    # password_hash(); single-quote it (Docker Compose interpolates $)
API_SECRET_KEY=…               # secret of tenant 1 (adopted by migrate.php)
```

## Frontend

The tenant belongs to the **installation**, not to a page or URL:

- **Standalone:** `TENANT_SLUG` and `TENANT_API_SECRET` in `frontend/.env`; forms are opened with `index.php?form=bs`.
- **WordPress:** *Tenant-Slug* and *Tenant-API-Secret* in the plugin settings (or `.env`); the shortcode stays `[ondisos form="bs"]`.

> **Deviation from the original design:** the first draft planned `[ondisos form="bs" tenant=5]` and `index.php?form=bs&tenant=5`, and a
> per-tenant directory layout under `frontend/surveys`. Neither was implemented — a frontend belongs to one tenant, which keeps the URL surface
> unchanged and prevents a visitor from choosing a tenant. Survey files stay in `frontend/surveys/`.

Entry points that use form config (`index.php`, `save.php`, `ical.php`, the WordPress shortcode and AJAX handlers) all go through
`Frontend\Config\FormConfigLoader::ensure()`.

## Deferred items

### 3.1 — Form config admin UI and survey management (implemented)

Done in 3.1, see [../docs/plans/PLAN-3.1.md](plans/PLAN-3.1.md) and [../docs/MIGRATION-3.1.md](../betreiber/MIGRATION-3.1.md):

- form configurations are edited through an HTML form in the backend (no JSON for school admins), saved with a revision history
- surveys and themes are stored in the backend per tenant (`form_resources`), pasted/uploaded by the admin, validated, previewed and published with draft/rollback;
  the frontend **pulls** them together with the config (ETag, file cache, stale-if-error; files in `frontend/surveys/` remain a fallback)
- new tenants start with the forms of another tenant (`FormCopyService`; recipients and logo are never copied)

### Managed multi-tenant frontend (Scenario B) — dropped, kept as an option

Decision (2026-10): not pursued. The standalone frontend and the self-contained WordPress plugin ZIP let schools run their own frontend, so a managed one is only worth it if schools should operate nothing at all. Kept here for the case it comes back. One frontend instance serves several tenants. Requires tenant selection/routing in the UI and a shared secret between that frontend and the backend
(per-tenant secrets offer no additional protection when the frontend is shared). (Formerly planned as 3.0.5, then 3.2.)

Separately, the file fallback for surveys (`frontend/surveys/`) is to be dropped at some point; the files then remain an import source only (no date).

## Impact summary (what changed in the code base)

**High:** `AnmeldungRepository` (tenant filtering), schema and migration, authentication (roles, session), `bootstrap.php` (`TenantContext`),
`submit.php` / `upload.php` (tenant routing, HMAC, ownership).

**Medium:** `AnmeldungService`, `ExportService`, `StatusService`, `ExpungeService` (tenant scope); `DetailController`/`DownloadController`
(tenant directory); `FormConfig` (DB instead of file); `AuditLogger` (`tenant_id`); `pdf/download.php` (tenant resolved from the token's Anmeldung);
frontend `BackendApiClient` (signing, slug), `FormConfigLoader`, WordPress plugin.

**Low/none:** `PdfTokenService` (stateless HMAC), `RateLimiter` (per IP), `VirusScanService`, `MessageService`, `PdfGeneratorService`, SurveyJS rendering.
