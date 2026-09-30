# Feature Research

**Domain:** Multi-tenant school registration SaaS (adding multi-tenancy to existing single-tenant PHP system)
**Researched:** 2026-03-13
**Confidence:** HIGH — based on detailed architectural decisions in `backend/MULTI-TENANT.md`, full codebase analysis in `.planning/codebase/`, and established patterns from multi-tenant SaaS systems. External search unavailable; findings grounded in project documentation and well-established multi-tenant design principles.

---

## Feature Landscape

### Table Stakes (Users Expect These)

Features without which multi-tenant operation is broken or unsafe. These are non-negotiable for v3.0.0.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Tenant data isolation (shared schema, tenant_id filtering) | Every tenant admin must see only their own submissions — this is DSGVO-critical | HIGH | 15+ `AnmeldungRepository` methods all need `WHERE tenant_id = ?`; TenantContext propagation touches nearly every service |
| TenantContext request-scoped singleton | Without it, tenant_id must be threaded through every method signature — architectural prerequisite for all other features | MEDIUM | Mirrors existing `Config` singleton pattern; initialized once in `bootstrap.php` from session (admin) or HMAC header (API) |
| Multi-role authentication (platform admin vs tenant admin) | Platform admin must manage all tenants; tenant admin must be locked to their data | HIGH | Replaces single `.env` admin with DB-stored `tenant_admins` + `.env` platform admin credentials; session gains `is_platform_admin` + `allowed_tenant_ids` |
| Tenant CRUD management UI (platform admin) | Platform admin must be able to create and disable schools without code deploys | MEDIUM | Standard CRUD views; existing Bootstrap 5 admin patterns apply; `tenants` table with name, slug, api_secret, enabled |
| Tenant admin management UI (platform admin) | Creating tenant admins is the onboarding action for new schools | MEDIUM | CRUD for `tenant_admins` table; password hashing UI similar to existing hash-generator script |
| DB-driven form configuration per tenant | Schools need different forms; file-based config (forms-config.php) cannot be per-tenant | HIGH | Replaces `FormConfig.php` file loading with `form_configs` table lookups; `config_json` stores full form config as JSON; requires migration/seed scripts for existing forms |
| Per-tenant HMAC API secret | Without per-tenant secrets, any frontend can submit to any tenant — full data mixing | MEDIUM | `api_secret` stored in `tenants` table; backend validates HMAC signature on `submit.php` and `upload.php`; pattern already established by PDF token system |
| Frontend tenant parameter (Scenario A) | Each dedicated school frontend must identify itself when calling the backend API | LOW | `?form=bs&tenant=5` or `[ondisos form="bs" tenant=5]` in WordPress shortcode; `surveys/tenant-{id}/` directory structure to avoid filename conflicts |
| Tenant-scoped file upload directories | Uploaded files must not be accessible across tenant boundaries | LOW | Move from `uploads/` flat structure to `uploads/tenant-{id}/`; `DetailController` file access path changes; migration script needed for existing files |
| Tenant-aware audit trail | Audit log entries without tenant_id are unusable in multi-tenant operation | LOW | Add `tenant_id` field to every `AuditLogger::log()` call; single file maintained, grep-friendly |
| Database migration: anmeldungen → multi-tenant | Existing single-tenant data must survive the upgrade with tenant_id = 1 | MEDIUM | `ALTER TABLE anmeldungen ADD COLUMN tenant_id INT NOT NULL DEFAULT 1` + FK + indexes; existing records assigned to default tenant automatically |

### Differentiators (Competitive Advantage)

Features that make ondisos multi-tenancy better than a bare-minimum implementation, aligned with "zero-overhead single-tenant operation" and DSGVO requirements.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Zero-config single-tenant backward compatibility | Schools already running v2.6 get multi-tenant schema with zero migration pain — flip `MULTI_TENANT_ENABLED=true` when ready | LOW | Always-present tenant schema with default tenant (id=1); `MULTI_TENANT_ENABLED=false` disables role-switching UI but schema is identical |
| Seed scripts for form config (not just migration) | Platform admin can provision a new school's forms without writing SQL by hand | LOW | PHP seed scripts that INSERT into `form_configs`; defers admin UI to v3.1 without leaving admins helpless |
| Per-tenant api_secret rotation capability | If a school's secret is compromised, it can be rotated without affecting other tenants | LOW | Only requires UPDATE on `tenants.api_secret`; no distributed secret store needed |
| Tenant slug as human-readable identifier | `tenants.slug` enables log filtering, URL construction, and future subdomain routing without exposing numeric IDs | LOW | Already in schema; used in audit log entries, directory naming, and future Scenario B routing |
| Automatic tenant detection from session vs HMAC | Same `TenantContext` works for both admin sessions (tenant scoped by login) and API calls (tenant from HMAC header) — no dual code paths | MEDIUM | `TenantContext::initFromSession()` and `TenantContext::initFromApiRequest()` unify tenant resolution |
| Tenant-scoped file isolation with path validation | Files stored in `uploads/tenant-{id}/` prevent directory traversal attacks across tenant boundaries — a DSGVO-relevant security property | LOW | `DetailController` adds tenant_id path component; traversal check must verify tenant ownership |

### Anti-Features (Commonly Requested, Often Problematic)

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| Separate databases per tenant | Seems like "true" isolation; some SaaS vendors recommend it | Operational nightmare for school context (dozens of DB connections, separate migrations per tenant, no cross-tenant queries); MySQL connection overhead; no benefit at this scale | Shared schema with tenant_id is DSGVO-compliant when queries always filter correctly; simpler backups, single migration path |
| Form config admin UI in v3.0 | Admins want to manage forms in-browser without seed scripts | Increases v3.0 scope significantly; form JSON editor is a substantial UI feature (SurveyJS Creator integration or custom); blocks faster delivery of core multi-tenancy | Seed scripts + migration for v3.0; admin UI deferred to v3.1 where it belongs alongside survey file upload |
| Per-tenant rate limiting | Schools might want their own rate limit thresholds | Per-IP rate limiting already protects against submission floods; per-tenant adds DB lookup on every request; current file-based limiter does not support per-tenant config without complexity | Current per-IP approach is sufficient; escalate to per-tenant only if an actual abuse scenario emerges |
| Per-tenant message customization | Schools might want branded error messages | MessageService is stateless and currently gitignored via `.local.php`; adding tenant-aware message lookup requires DB query per message; complexity not justified | Keep MessageService tenant-agnostic; school-specific branding can go in form JSON (SurveyJS supports custom text) |
| Tenant-switching UI for admins (Scenario B frontend) | One frontend serving multiple tenants is convenient for managed deployments | Requires tenant selection/login page, shared secrets lose per-tenant security value, significantly more frontend complexity | Scenario A (dedicated frontend per school) ships first; Scenario B is scoped to v3.0.5 once core is proven |
| Real-time tenant onboarding (self-service signup) | Makes the system a proper SaaS product | Not the deployment model — schools are onboarded by a municipality IT admin, not self-service; adds auth complexity (email verification, payment, etc.) | Platform admin creates tenants and tenant admins manually via UI; this is the correct model for municipal government context |

---

## Feature Dependencies

```
[DB Migration: anmeldungen + new tables]
    └──required by──> [TenantContext singleton]
                          └──required by──> [Tenant-aware repository queries]
                                                └──required by──> [All admin UI changes]
                                                └──required by──> [Tenant-scoped audit trail]
                                                └──required by──> [Tenant-scoped file uploads]

[Per-tenant HMAC API secret]
    └──required by──> [Frontend tenant parameter (Scenario A)]
                          └──required by──> [DB-driven form config fetch by frontend]

[Multi-role authentication]
    └──required by──> [Tenant CRUD management UI]
    └──required by──> [Tenant admin management UI]
    └──required by──> [Tenant-scoped data isolation in admin views]

[Tenant CRUD management UI]
    └──enables──> [Tenant admin management UI]
    └──enables──> [Seed scripts for form config]

[DB-driven form config]
    └──required by──> [Per-tenant form config fetch from frontend]
    └──enhances──> [Tenant CRUD management UI] (v3.1 admin UI for forms)
```

### Dependency Notes

- **DB migration must be first:** All other features depend on `tenant_id` existing in `anmeldungen` and the `tenants`, `tenant_admins`, `form_configs` tables being present. Migration and default tenant seed are the foundational step.
- **TenantContext before repository changes:** The TenantContext singleton must be in place before any repository method is modified, or the methods have no way to obtain their tenant_id value.
- **Auth before UI:** Multi-role auth (platform admin + tenant admin) must be functional before tenant management UI pages are accessible, since those pages gate on `is_platform_admin`.
- **HMAC before frontend tenant parameter:** Frontend can't sign requests with a per-tenant secret until the secret exists in the tenants table and the backend validates it.
- **Form config DB before frontend fetches config:** Frontend currently reads `forms-config.php`; it can only pull config from the backend API once the `form_configs` table exists and is populated via seed scripts.
- **File isolation is independent:** Tenant-scoped upload directories can be implemented in parallel with auth/UI work since it only touches `DetailController`, `upload.php`, and the filesystem.

---

## MVP Definition

This is a brownfield milestone — "MVP" means the minimum set of features that makes multi-tenant operation safe and functional. All features in the table stakes section are required for v3.0.0.

### Launch With (v3.0.0)

- [ ] Database migration + new tables (tenants, tenant_admins, form_configs) — foundational; nothing else works without it
- [ ] TenantContext singleton — prerequisite for all tenant-aware code
- [ ] Tenant-aware repository queries (all 15+ methods) — data isolation is DSGVO-mandatory
- [ ] Multi-role authentication (platform admin + tenant admin) — required to gate management UI
- [ ] Tenant CRUD management UI — platform admin must be able to create schools
- [ ] Tenant admin management UI — platform admin must be able to create school admins
- [ ] Per-tenant HMAC API secret — required for frontend to authenticate against correct tenant
- [ ] DB-driven form configuration + seed scripts — forms-config.php cannot serve per-tenant forms
- [ ] Tenant-scoped file upload directories — file isolation is DSGVO-relevant
- [ ] Frontend tenant parameter support (Scenario A) — dedicated frontends must identify their tenant
- [ ] Tenant-aware audit trail (tenant_id in log entries) — audit log is useless without tenant context
- [ ] Tests alongside all new features — project constraint, not optional

### Add After Validation (v3.0.5)

- [ ] Managed multi-tenant frontend (Scenario B) — once core multi-tenancy is proven; adds tenant-switching UI and shared secret model

### Future Consideration (v3.1+)

- [ ] Form config admin UI — deferred; seed scripts suffice for v3.0; proper UI requires SurveyJS Creator integration or custom form editor
- [ ] Survey JSON file upload from backend to frontend — deferred; makes frontend a zero-maintenance zone for tenant admins
- [ ] Per-tenant message customization — low value; not needed in municipal government context

---

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|---------------------|----------|
| DB migration + new tables | HIGH | MEDIUM | P1 |
| TenantContext singleton | HIGH | MEDIUM | P1 |
| Tenant-aware repository queries | HIGH | HIGH | P1 |
| Multi-role authentication | HIGH | HIGH | P1 |
| Tenant CRUD management UI | HIGH | MEDIUM | P1 |
| Tenant admin management UI | HIGH | MEDIUM | P1 |
| Per-tenant HMAC API secret | HIGH | MEDIUM | P1 |
| DB-driven form config + seed scripts | HIGH | HIGH | P1 |
| Frontend tenant parameter (Scenario A) | HIGH | LOW | P1 |
| Tenant-scoped file upload directories | HIGH | LOW | P1 |
| Tenant-aware audit trail | MEDIUM | LOW | P1 |
| Zero-config backward compatibility | HIGH | LOW | P1 |
| Seed scripts for form config | HIGH | LOW | P1 |
| Per-tenant api_secret rotation | MEDIUM | LOW | P2 |
| Form config admin UI | MEDIUM | HIGH | P3 |
| Survey JSON upload mechanism | MEDIUM | HIGH | P3 |
| Scenario B managed frontend | MEDIUM | HIGH | P3 |

**Priority key:**
- P1: Must have for v3.0.0 launch
- P2: Should have, add when cost is low
- P3: Future consideration (v3.0.5 or v3.1+)

---

## Existing Features Requiring Adaptation

These v2.x features already exist but need modification to become tenant-aware. They are NOT new features, but each carries implementation cost.

| Existing Feature | Required Adaptation | Complexity | Risk |
|-----------------|---------------------|------------|------|
| `AnmeldungRepository` (15+ methods) | Add `WHERE tenant_id = ?` to all queries | HIGH | Missing tenant filter = data leak across tenants; must be exhaustive |
| `bootstrap.php` | Initialize TenantContext before any service runs | LOW | Wrong init order = wrong tenant_id for entire request |
| `api/submit.php` + `api/upload.php` | Extract tenant_id from HMAC-signed request; validate api_secret | MEDIUM | HMAC validation failure must reject request, not fall through |
| `inc/auth.php` | Support platform_admin + tenant_admin session roles | HIGH | Session must store `is_platform_admin` and `allowed_tenant_ids`; all page guards must check scope |
| `AnmeldungService`, `ExportService`, `StatusService` | Read `TenantContext::getTenantId()` instead of no tenant concept | LOW | Mostly transparent once TenantContext is initialized |
| `DetailController` | Scope file access to `uploads/tenant-{id}/` directory | LOW | Add tenant path component; re-verify directory traversal prevention |
| `FormConfig` | Load from `form_configs` DB table instead of `forms-config.php` | MEDIUM | Caching strategy for DB-loaded config; `forms-config.php` becomes migration source |
| `AuditLogger` | Add `tenant_id` field to all log events | LOW | Static call sites throughout codebase; low risk, mechanical change |
| WordPress plugin | Pass `tenant` parameter in shortcode and API calls | LOW | `[ondisos form="bs" tenant=5]`; minimal frontend changes |

---

## Complexity Notes

**Most complex features (HIGH):**

1. **Tenant-aware repository queries** — 15+ methods across a single repository file, all must be updated consistently. Missing even one method creates a data isolation gap. Integration tests are essential here to verify every code path returns tenant-filtered results.

2. **Multi-role authentication** — The existing single-admin session model must be replaced with a role-bearing session that distinguishes platform admin (can see all tenants) from tenant admin (locked to `allowed_tenant_ids`). Every page guard must be updated. The platform admin's credentials remain in `.env` while tenant admins are in the database, creating a two-source auth flow.

3. **DB-driven form configuration** — `FormConfig.php` is referenced throughout both frontend and backend. The backend replacement is straightforward (DB query by tenant_id + form_key), but the frontend must now make an API call to fetch config before rendering the form, adding a network dependency to the form display path.

**Least complex features (LOW) — good candidates for early wins:**

- Frontend tenant parameter addition (pure additive, no existing logic changes)
- Tenant-scoped upload directories (path string change + mkdir)
- Audit trail tenant_id addition (mechanical, low-risk)
- Zero-config backward compatibility (ensured by always seeding default tenant with id=1)

---

## Sources

- `backend/MULTI-TENANT.md` — Architectural decisions (2026-03-13), HIGH confidence
- `.planning/PROJECT.md` — Active requirements and out-of-scope decisions (2026-03-13), HIGH confidence
- `.planning/codebase/ARCHITECTURE.md` — Service/repository map, entry points, data flows (2026-03-13), HIGH confidence
- `.planning/codebase/CONCERNS.md` — Known complexity hotspots including repository layer size (2026-03-13), HIGH confidence
- Multi-tenant SaaS design patterns (shared schema vs isolated schema, TenantContext propagation, per-tenant secrets) — training knowledge, MEDIUM confidence; consistent with architectural decisions already made

---

*Feature research for: Multi-tenant school registration system (ondisos v3.0.0)*
*Researched: 2026-03-13*
