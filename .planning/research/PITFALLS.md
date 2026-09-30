# Pitfalls Research

**Domain:** Adding multi-tenant capability to an existing single-tenant PHP school registration system
**Researched:** 2026-03-13
**Confidence:** HIGH (all findings grounded in actual codebase analysis + established multi-tenancy patterns)

---

## Critical Pitfalls

### Pitfall 1: Missing `tenant_id` in One or More Repository Methods

**What goes wrong:**
`AnmeldungRepository` has 15+ methods. Adding `WHERE tenant_id = ?` to most of them but missing one or two leaves a silent cross-tenant data leak. A tenant admin can see, export, or delete registrations from another school. No exception is thrown — the query simply returns wrong data.

**Why it happens:**
The migration is mechanical and repetitive. Developers add the clause to the methods they test (e.g., `findPaginated`, `findById`) and leave the less-obvious ones untouched: `getAllFormNames`, `getStatistics`, `findDeleted`, `findExpiredArchived`, `bulkUpdateStatus`, `bulkSoftDelete`, `hardDelete`, `restore`, `findByIds`. Each of these currently has zero tenant awareness.

**How to avoid:**
- Before writing any tenant-aware code, write an integration test for every repository method that asserts Tenant B cannot see Tenant A's data.
- Use a `TenantContext::getTenantId()` call at the top of each method and enforce it as a required binding parameter — never let it default to `1` silently.
- Code review checklist item: "Does this query touch `anmeldungen` without `AND tenant_id = ?`?"

**Warning signs:**
- `getAllFormNames()` returns form keys from multiple tenants in a single dropdown.
- `getStatistics()` shows counts that are too high for a newly created tenant.
- Dashboard numbers do not match per-tenant export counts.

**Phase to address:** Schema + TenantContext phase (Phase 1 of v3.0). Write the isolation tests before implementing any repository changes.

---

### Pitfall 2: `findById` Without Tenant Scope Enables IDOR

**What goes wrong:**
`findById(int $id)` fetches by primary key only. Once multi-tenancy is active, a tenant admin who knows any valid record ID (e.g., ID=5) can call the detail view for a record belonging to a different tenant. No 404 is returned — the record is returned normally.

**Why it happens:**
Sequential integer primary keys are predictable. The existing code has no ownership check because there was only ever one tenant. Adding `tenant_id` to the main list query but forgetting `findById` is an easy mistake.

**How to avoid:**
Change the signature to `findById(int $id, int $tenantId): ?Anmeldung` and add `AND tenant_id = ?` to the query. The method must return `null` if the record exists but belongs to a different tenant. The controller then returns 403 or 404 — not the record.

**Warning signs:**
- Detail view loads for IDs not present in the current tenant's list.
- PDF download endpoint can generate a PDF for a record from a different tenant when given a valid token.

**Phase to address:** Phase 1 (Repository layer). Must be tested with an explicit cross-tenant access test.

---

### Pitfall 3: Data Migration Assigns Existing Records to Wrong Tenant

**What goes wrong:**
The planned migration (`ALTER TABLE anmeldungen ADD COLUMN tenant_id INT NOT NULL DEFAULT 1`) assigns all existing records to tenant ID 1. If the migration runs after tenant records have already been created (e.g., a staging environment where tenant 2 was created first), the default becomes wrong. Or: the migration script is re-run on a system where tenant ID 1 has been renamed or deleted.

**Why it happens:**
`DEFAULT 1` in the DDL is a convenience shortcut that assumes the default tenant is always ID 1. In a fresh multi-tenant deployment (no prior data), this assumption holds. On a system with existing data or with a non-trivial migration order, it can silently misassign records.

**How to avoid:**
- The migration script must explicitly insert the default tenant row first (`INSERT INTO tenants (id, name, slug, ...) VALUES (1, 'Default', 'default', ...)`) before the `ALTER TABLE`.
- Use a transaction: insert tenant, alter table, add foreign key — all atomically or not at all.
- After migration, verify: `SELECT COUNT(*) FROM anmeldungen WHERE tenant_id NOT IN (SELECT id FROM tenants)` must return 0.

**Warning signs:**
- Foreign key constraint violation when altering the table.
- After migration, the default tenant's record count does not match the pre-migration `anmeldungen` count.

**Phase to address:** Phase 1 (DB migration scripts). Include a pre-flight check in the migration.

---

### Pitfall 4: Session Does Not Carry Tenant Scope — Platform Admin Sees All Without Realizing It

**What goes wrong:**
The current session stores only `admin_logged_in` and `login_time`. Adding multi-tenant auth means the session must also carry `tenant_id` and `is_platform_admin`. If `auth.php` is updated to check login but not to scope data access, a tenant admin who bypasses the TenantContext initialization (e.g., a page that does not call `TenantContext::initFromSession()`) will either see all data or no data.

**Why it happens:**
The existing `auth.php` is a simple gate (logged in / not logged in). Multi-tenant session scoping is a second concern layered on top. It is easy to update the gate but forget to propagate the tenant scope to every page that instantiates a repository.

**How to avoid:**
- `TenantContext::initFromSession()` must be called in `bootstrap.php` after the session starts, unconditionally for all admin pages.
- `TenantContext` must throw if accessed before initialization rather than silently returning `null` or a default.
- Platform admin sessions must explicitly acknowledge they are operating in "all tenants" mode; they should still be scoped to a single tenant when acting on behalf of one.

**Warning signs:**
- A tenant admin's dashboard shows aggregate counts from all tenants.
- A platform admin action (bulk archive) affects records from all tenants instead of the selected one.

**Phase to address:** Phase 2 (Auth + session). Write a test that logs in as Tenant B admin and verifies Tenant A records are inaccessible.

---

### Pitfall 5: File Uploads Use Flat Directory — Cross-Tenant File Access

**What goes wrong:**
`upload.php` currently saves files to `backend/uploads/{anmeldung_id}_{filename}`. In multi-tenant mode, two tenants can have registrations with overlapping IDs (e.g., after the migration, both Tenant A's record #5 and Tenant B's record #5 would produce the filename `5_document.pdf`). The second upload overwrites the first. Worse: `detail.php` constructs the file path from the stored filename without checking tenant ownership, so Tenant B admin can download Tenant A's file if they know the filename pattern.

**Why it happens:**
The filename includes only the record ID, which is globally unique in the current single-tenant DB. Post-migration, IDs remain globally unique (auto-increment, not per-tenant), so the collision risk is lower than it appears — but the directory traversal risk is real, and the access control gap is the primary concern.

**How to avoid:**
- Move to tenant-scoped directories immediately: `uploads/tenant-{id}/{anmeldung_id}_{filename}`.
- `upload.php` must receive and validate `tenant_id` from `TenantContext` before constructing the path — never from user input.
- File download endpoints must reconstruct the path from `TenantContext::getTenantId()`, not from the request.
- Existing files must be migrated to `uploads/tenant-1/` during the DB migration.

**Warning signs:**
- `detail.php` fetches the file path from `data` JSON or a stored filename without prefixing the tenant directory.
- File download works when a URL is shared between tenant admin sessions.

**Phase to address:** Phase 2 (file isolation). The directory structure change must coincide with the upload endpoint update.

---

### Pitfall 6: HMAC Secrets Are Not Per-Tenant — One Compromise Affects All

**What goes wrong:**
The current system uses a single `PDF_TOKEN_SECRET` in `.env`. The proposed per-tenant `api_secret` in the `tenants` table is architecturally correct, but if the code still falls back to the global `.env` secret for any code path, a single leaked `.env` value allows HMAC forgery for all tenants.

**Why it happens:**
`PdfTokenService` currently reads its secret from `Config`. During migration, developers may leave the `.env` fallback in place "for backwards compatibility" without realizing it undermines the per-tenant isolation goal.

**How to avoid:**
- In multi-tenant mode, the `api_secret` from the `tenants` table is the ONLY valid source for tenant HMAC operations. No fallback to `.env`.
- In single-tenant mode (the default tenant, ID=1), the `api_secret` in the tenants table can be seeded from the `.env` value during migration — but thereafter the DB value is authoritative.
- `PdfTokenService` and the API auth middleware must receive the secret as a constructor argument, never read it directly from the environment.

**Warning signs:**
- Token validation succeeds when the DB tenant `api_secret` has been rotated but the `.env` value has not.
- Tests pass with any tenant's token when using the global secret.

**Phase to address:** Phase 2 (API security). Ensure no code path reads HMAC secrets from `.env` when `MULTI_TENANT_ENABLED=true`.

---

### Pitfall 7: Auto-Expunge Runs Across All Tenants Without Scope

**What goes wrong:**
`ExpungeService::autoExpunge()` calls `repository->findExpiredArchived($daysOld)` which (currently) scans the entire `anmeldungen` table. In multi-tenant mode, `AUTO_EXPUNGE_DAYS` is a global setting. If Tenant A wants 30-day expunge and Tenant B wants 90 days, there is no mechanism to honor this. Worse: expunge runs in `bootstrap.php` on every request before `TenantContext` is initialized, meaning it will run without tenant scope and delete records across all tenants using the global setting.

**Why it happens:**
The expunge is a background task that was designed for a single configuration. `bootstrap.php` runs it early before any session or tenant context is available.

**How to avoid:**
- Move the auto-expunge trigger out of `bootstrap.php` to a cron job or a platform-admin-only action.
- If request-based triggering is kept, it must only fire after `TenantContext` is initialized — and it must either iterate all tenants explicitly (platform admin context) or be scoped to the current tenant (tenant admin context).
- For v3.0, a pragmatic choice is to run expunge only in platform admin context with the global `AUTO_EXPUNGE_DAYS` setting and document that per-tenant expunge config is a v3.1 item.

**Warning signs:**
- Expunge deletes records from Tenant B because a platform admin logged in to Tenant A's context.
- Dashboard expunge preview shows records from all tenants regardless of logged-in context.

**Phase to address:** Phase 3 (service layer). Expunge must be tenant-aware or explicitly global-with-documentation.

---

### Pitfall 8: Audit Log Entries Missing `tenant_id` — Post-Hoc Attribution Impossible

**What goes wrong:**
`AuditLogger` currently logs `event`, `user`, `ip`, and `details`, but no `tenant_id`. In multi-tenant mode, a log entry like `{"event":"status_changed","user":"admin","details":{"id":42}}` is ambiguous — record #42 could belong to any tenant. DSGVO compliance requires that data access events be attributable to a specific data subject and context.

**Why it happens:**
`AuditLogger` is a static class with no dependency injection. Adding `tenant_id` requires either threading it through every call site or reading it from `TenantContext` statically. The latter is the right approach but must be done carefully — if `TenantContext` is not yet initialized when the logger is called, reading it will fail.

**How to avoid:**
- Add `tenant_id` to every `AuditLogger::log()` call by having the logger read `TenantContext::getTenantIdOrNull()` (a safe version that returns `null` if not initialized, rather than throwing).
- Backfill is not possible for existing log entries — document this as a known gap at migration time.
- Add `tenant_id` to all new events from day 1 of v3.0.

**Warning signs:**
- Audit log entries post-migration lack `tenant_id` field.
- Log grep for `tenant_id:5` returns no results even after multi-tenant activity.

**Phase to address:** Phase 2 (alongside auth). The `AuditLogger` change is small but must be in the same phase as `TenantContext` to avoid a window where audit entries are missing tenant context.

---

### Pitfall 9: Backward Compatibility Break for Single-Tenant Deployments

**What goes wrong:**
Existing single-tenant deployments run with no `MULTI_TENANT_ENABLED` in `.env`. If v3.0 code requires `TenantContext` to be initialized from a tenants DB table that does not yet exist, every page load throws a `RuntimeException`. The system is dead on upgrade without schema migration.

**Why it happens:**
The new schema tables (`tenants`, `tenant_admins`, `form_configs`) do not exist in v2.6 deployments. Code that reads from these tables at bootstrap will fail immediately. Developers testing only in fresh environments miss this.

**How to avoid:**
- The migration script must be run before any v3.0 code executes. Document this clearly.
- `TenantContext` initialization must handle the case where `MULTI_TENANT_ENABLED=false`: skip the DB lookup entirely, return hardcoded `tenant_id=1`.
- In single-tenant mode with `MULTI_TENANT_ENABLED=false`, the repository `WHERE tenant_id = ?` clauses use `1` from a constant — no DB query for tenant resolution.
- Provide a migration script that can be run independently before deploying v3.0 code.

**Warning signs:**
- Bootstrap fails with "Table 'tenants' doesn't exist" on a v2.6 installation after a v3.0 code deploy.
- `TenantContext::getTenantId()` throws on a server where the schema migration has not run.

**Phase to address:** Phase 1 (DB migration). The migration must be deployable to a live v2.6 system without breaking it until v3.0 code is activated.

---

### Pitfall 10: FormConfig Migration From File to DB — Old Frontend Still Uses `forms-config.php`

**What goes wrong:**
The frontend `frontend/config/forms-config.php` is the current source of truth for which forms are enabled, their PDF settings, notify emails, etc. In v3.0, this moves to the `form_configs` DB table on the backend. But the frontend code in `AnmeldungService.php` and `FormConfig.php` still reads from the PHP file. If the file is left in place during migration, the system silently uses stale config. If it is removed before the DB is populated, all form submissions fail with "form not found."

**Why it happens:**
Config migration is a two-step process (populate DB, update code to read from DB, remove file). It is easy to complete step 1 and 2 but leave the file in place, creating a dual-source-of-truth that silently diverges over time when someone edits the file but not the DB.

**How to avoid:**
- Define a clear cutover: once DB config is live, `forms-config.php` is deleted and replaced with a read-from-API implementation.
- The seed script that populates `form_configs` must be validated against the current `forms-config.php` before the file is removed.
- Add a deprecation log warning if `forms-config.php` is detected at runtime in v3.0 mode.
- The backend `api/submit.php` currently has a fallback to `FormConfig::exists($formKey)` — this must be replaced with a DB lookup.

**Warning signs:**
- Form config changes made in the backend admin (future v3.1) are overridden by the static file on the next request.
- `submit.php` logs "Loaded form config from file" in v3.0 mode.

**Phase to address:** Phase 3 (form config migration). Keep the file-based fallback in `submit.php` until the DB config is fully seeded and verified, then remove it in the same commit.

---

## Technical Debt Patterns

Shortcuts that seem reasonable during multi-tenant migration but create long-term problems.

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| `TenantContext` defaulting to `1` silently when not initialized | Fewer places to handle null | Silent data leakage risk; masks initialization bugs | Never acceptable in production |
| Keeping `forms-config.php` alongside DB config during transition | Easier rollback | Dual source of truth; changes diverge silently | Only during the migration window, with a deadline to remove |
| Single `AUTO_EXPUNGE_DAYS` global setting for all tenants | Simple config | Some tenants retain data longer than their DSGVO requirement allows | Acceptable for v3.0 if documented; fix in v3.1 |
| Per-tenant `api_secret` generated once at tenant creation, never rotated | Simplicity | A long-lived static secret is a liability | Acceptable for school intranet context; document that rotation is manual |
| Skipping repository-level tenant scope enforcement in favor of controller-level checks | Faster to implement | One controller oversight = data leak | Never acceptable; defense in depth requires DB-level filtering |
| Global `audit.log` for all tenants (current plan) | Simple grep, no per-tenant files | Cannot easily extract a single tenant's audit trail for DSGVO data subject requests | Acceptable if `tenant_id` field is present in every entry |

---

## Integration Gotchas

Common mistakes when connecting the multi-tenant components.

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| Frontend `tenant` parameter | Trusting the `tenant` parameter from the HTTP request without validating against the HMAC signature | The HMAC signature binds the request to a specific tenant's secret; if the secret validates, the tenant is confirmed. Never accept `tenant_id` from request body as authoritative without HMAC validation. |
| `submit.php` CORS | Expanding `ALLOWED_ORIGINS` to a wildcard when supporting multiple school frontends | Each tenant registers their frontend origin; CORS validation checks the origin against the tenant's registered origin in the `tenants` table, not a global `.env` list. |
| Session tenant scoping | Storing `tenant_id` in session at login and trusting it for all subsequent requests without re-validation | Check `tenant_id` in session against the `tenant_admins` table on each request to ensure the tenant has not been disabled. |
| PDF proxy and download | Frontend proxy forwards the token to the backend; backend loads the record and generates the PDF without checking that the record's `tenant_id` matches the token's originating tenant | The token was generated for a specific `anmeldung_id`; `findById` must include `AND tenant_id = ?` where the tenant comes from `TenantContext`, not from the token payload. |
| `upload.php` and tenant directory | Receiving `tenant_id` as a POST parameter and constructing the upload path from it | Always derive tenant directory from `TenantContext` (which comes from the validated HMAC or session) — never from request input. |

---

## Performance Traps

Patterns that work in single-tenant but break under multi-tenant load.

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| Missing compound index `(tenant_id, formular, status)` | Filter queries slow as tenant count grows; `findPaginated` does full table scans | Add composite indexes at migration time alongside the `tenant_id` column | ~10k records per tenant, 5+ tenants |
| `getAllFormNames()` without tenant scope | Returns all form keys from all tenants; dropdown shows other schools' forms | Scope the query to the current tenant immediately | First multi-tenant deployment |
| `getStatistics()` without tenant scope | Dashboard counts reflect all tenants; platform admin and tenant admin see the same inflated numbers | Scope with `tenant_id = ?` for tenant admins; offer explicit "all tenants" aggregate for platform admin | First multi-tenant deployment |
| `findExpiredArchived()` without tenant scope at bootstrap | Expunge may delete records from tenants it has no business touching | Move expunge trigger out of `bootstrap.php`; only run in an explicitly tenant-scoped context | First request after v3.0 deploy |
| File-based rate limiter keyed by IP only | A single school's burst traffic exhausts the rate limit for other tenants behind the same NAT | Rate limit key should include `tenant_id` as well as IP | When two tenants share an IP (rare but possible in school networks) |

---

## Security Mistakes

Domain-specific security issues specific to multi-tenant PHP systems.

| Mistake | Risk | Prevention |
|---------|------|------------|
| Platform admin password stored in `.env` in cleartext | Compromised `.env` gives full platform access | Store as bcrypt hash (`PLATFORM_ADMIN_PASSWORD_HASH`) same as current `ADMIN_PASSWORD_HASH` pattern |
| Tenant admin can change their own `tenant_id` in session | Privilege escalation to another tenant's data | Never read `tenant_id` from POST/GET; always from session which was set by validated credentials at login |
| Tenant slug not validated before use in filesystem paths | Directory traversal: slug `../uploads/` creates a path outside `uploads/` | Whitelist slug to `[a-z0-9-]` at creation; reject slugs with path characters |
| `hardDelete` without tenant scope | A tenant admin could trigger expunge of another tenant's records via crafted request | `hardDelete` must verify `WHERE tenant_id = ?` or be callable only by platform admin in explicitly global mode |
| HMAC `api_secret` echoed in tenant admin UI | Secret exposed to browser, logged in access logs | Never display the full secret; show a masked version; provide a "Regenerate" button |
| Session does not store `allowed_tenant_ids` — only `is_platform_admin` | Platform admin acting as tenant admin could inadvertently access all tenants | Session stores explicit `allowed_tenant_ids` list per the MULTI-TENANT.md spec; `TenantContext` enforces this list |

---

## UX Pitfalls

User experience mistakes specific to the admin interface under multi-tenancy.

| Pitfall | User Impact | Better Approach |
|---------|-------------|-----------------|
| Tenant admin sees "No registrations" after migration because the admin UI defaults to `tenant_id=0` | Admins think the system is broken; support calls | Initialize `TenantContext` from session immediately; never show an empty state that contradicts what the admin knows is there |
| Platform admin creates a tenant but does not seed form config | School frontend throws "form not found" on first submission | Seed form configs as part of tenant creation wizard, not as a separate manual step |
| Tenant admin names (usernames) are scoped per-tenant but login form is shared | Tenant admin "mueller" at School A cannot distinguish their login from "mueller" at School B if both exist | Username must be unique globally in `tenant_admins`, or the login form must ask for tenant slug before username |
| "Export all" from platform admin context exports cross-tenant data in a single file | DSGVO violation: one school's data goes to another school's admin | Export must always be scoped to a single tenant; platform admin export must include a mandatory tenant filter |

---

## "Looks Done But Isn't" Checklist

Things that appear complete but are missing critical pieces specific to this migration.

- [ ] **Repository tenant filtering:** All 15+ methods updated — verify `getAllFormNames`, `getStatistics`, `findDeleted`, `findExpiredArchived`, `bulkUpdateStatus`, `bulkSoftDelete`, `hardDelete`, `restore`, `findByIds` have explicit `AND tenant_id = ?`.
- [ ] **`findById` IDOR protection:** Verify `findById` returns `null` (not the record) when `tenant_id` does not match the current context.
- [ ] **File upload paths:** Verify `upload.php` constructs upload path from `TenantContext`, not from request parameters. Verify existing `uploads/` files were migrated to `uploads/tenant-1/`.
- [ ] **Audit log `tenant_id`:** Every `AuditLogger` event after migration carries a `tenant_id` field — verify with a grep of `audit.log` after first multi-tenant action.
- [ ] **Backward compatibility:** Verify a fresh v2.6 database (no `tenants` table) with `MULTI_TENANT_ENABLED=false` runs without error after deploying v3.0 code, before running the migration script.
- [ ] **`AUTO_EXPUNGE` tenant scope:** Verify the expunge does not run cross-tenant; add a test that proves it.
- [ ] **HMAC secret source:** Verify no production code path reads HMAC secret from `.env` when `MULTI_TENANT_ENABLED=true`; the secret must come from the `tenants` table row.
- [ ] **`forms-config.php` removal:** Verify the file is deleted (or explicitly superseded) after DB form config is seeded — not left as a silent fallback.
- [ ] **Tenant admin username uniqueness:** Verify the `UNIQUE KEY (tenant_id, username)` in `tenant_admins` is also globally unique or the login form handles the ambiguity.
- [ ] **CORS origin per tenant:** Verify `submit.php` validates CORS against the tenant's registered origin, not the global `ALLOWED_ORIGINS` env var.

---

## Recovery Strategies

When pitfalls occur despite prevention, how to recover.

| Pitfall | Recovery Cost | Recovery Steps |
|---------|---------------|----------------|
| Cross-tenant data leak via missing `tenant_id` in a query | HIGH | 1. Identify affected query. 2. Determine which records were exposed (audit log). 3. Add `tenant_id` filter and deploy immediately. 4. DSGVO notification if personal data was accessed by wrong school admin. |
| Migration assigned records to wrong tenant | HIGH | 1. Do not run foreign key enforcement until tenant rows are confirmed. 2. Update `tenant_id` in `anmeldungen` to correct value. 3. Re-validate foreign key constraints. 4. No data loss but manual correction required. |
| File collision in flat upload directory | MEDIUM | 1. Identify affected files by comparing `anmeldungen.data` JSON references to filesystem. 2. Restore from backup if overwrite occurred. 3. Migrate directory structure to `tenant-{id}/` subdirectories. |
| `TenantContext` not initialized — system throws on first admin page load | LOW | 1. Ensure `bootstrap.php` initializes `TenantContext` before any repository access. 2. In single-tenant mode, ensure the fallback to `tenant_id=1` is in place. This is a deployment error, fixed by code rollback or config fix. |
| `forms-config.php` and DB config diverged silently | MEDIUM | 1. Compare DB `form_configs` rows against file. 2. Identify discrepancies. 3. DB is authoritative — update DB to match intended config. 4. Delete the file to prevent recurrence. |
| Audit log missing `tenant_id` for a period | LOW | Existing entries cannot be backfilled (no `tenant_id` available after the fact). Document the gap dates. Going forward, all entries will include the field. |

---

## Pitfall-to-Phase Mapping

How roadmap phases should address these pitfalls.

| Pitfall | Prevention Phase | Verification |
|---------|------------------|--------------|
| Missing `tenant_id` in repository methods | Phase 1: DB schema + repository layer | Integration test: Tenant B admin cannot see Tenant A records via any repository method |
| `findById` IDOR vulnerability | Phase 1: Repository layer | Test: `findById($tenantARecordId, $tenantBId)` returns `null` |
| Migration assigns records to wrong tenant | Phase 1: DB migration scripts | Pre-flight check in migration script; post-migration count verification |
| Session does not carry tenant scope | Phase 2: Auth + session | Test: Tenant B admin session returns 403 on Tenant A detail page |
| File upload cross-tenant access | Phase 2: File isolation | Test: Direct file path with wrong tenant directory returns 404 |
| HMAC secrets not per-tenant | Phase 2: API security | Test: Tenant B `api_secret` cannot validate a submission intended for Tenant A |
| Auto-expunge runs across all tenants | Phase 3: Service layer | Test: Expunge triggered in Tenant A context only deletes Tenant A's expired records |
| Audit log missing `tenant_id` | Phase 2: alongside auth | Verify first audit log entry after multi-tenant mode activation contains `tenant_id` |
| Backward compatibility break | Phase 1: migration scripts | Smoke test on v2.6 schema with `MULTI_TENANT_ENABLED=false` after v3.0 code deploy |
| FormConfig dual source of truth | Phase 3: form config migration | Verify `forms-config.php` is absent or inert after DB config is live |

---

## Sources

- Direct codebase analysis: `backend/src/Repositories/AnmeldungRepository.php` (15 methods, all tenant-unaware as of v2.6)
- Direct codebase analysis: `backend/public/api/submit.php` (no tenant routing as of v2.6)
- Direct codebase analysis: `backend/public/api/upload.php` (flat `uploads/` directory, no tenant scoping)
- Direct codebase analysis: `backend/inc/auth.php` (single admin gate, no tenant context)
- Direct codebase analysis: `backend/src/Services/AuditLogger.php` (no `tenant_id` field)
- Direct codebase analysis: `backend/src/Services/ExpungeService.php` (global scope, runs from bootstrap)
- Architectural decisions: `backend/MULTI-TENANT.md` (v3.0 design, 2026-03-13)
- Codebase concerns: `.planning/codebase/CONCERNS.md` (existing tech debt relevant to migration)
- Project scope: `.planning/PROJECT.md` (v3.0.0 requirements and constraints)

---
*Pitfalls research for: Multi-tenant migration of ondisos school registration system*
*Researched: 2026-03-13*
