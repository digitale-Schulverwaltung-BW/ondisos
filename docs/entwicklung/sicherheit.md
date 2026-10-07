# Sicherheit

Implementierte Schutzmaßnahmen und bekannte Einschränkungen.


**Implementiert:**
- ✅ CSRF-Protection (Token-basiert; WordPress: WP-Nonce)
- ✅ SQL Injection Prevention (Prepared Statements)
- ✅ XSS Protection (htmlspecialchars überall)
- ✅ File Upload Validation (Type, Size, Extension, MIME per Inhalt)
- ✅ Directory Traversal Prevention
- ✅ Input Validation (AnmeldungValidator)
- ✅ Type Safety (declare(strict_types=1))
- ✅ Error Handling (keine sensitive Daten in Errors)
- ✅ **Tenant-Isolierung** (`TenantContext`, `tenant_id` in jeder Abfrage, IDOR-Erkennung → `idor_attempt` im Audit-Log)
- ✅ **Signierte API** (`HmacValidator`): `submit.php` über den Raw-Body, `upload.php` über `id:feldname:dateiname`; der Slug (`?tenant=`) adressiert nur, die Signatur autorisiert
- ✅ **Secret-Policy** (`SecretPolicy`): Platzhalter (`CHANGE_ME_IN_PRODUCTION`, leer) authentifizieren nie; der Dev-Default `dev-api-key-replace-in-production` wird bei `APP_ENV=production` abgelehnt; `migrate.php` bricht dort ab, wenn `API_SECRET_KEY` so ein Wert ist
- ✅ **Upload-Zuordnung:** Ein Upload wird nur angenommen, wenn die Anmeldung zum authentifizierten Tenant gehört (sonst 404 + `idor_attempt`)
- ✅ PDF Token Security (HMAC-SHA256, selbstvalidierend, zeitlich begrenzt; der Token autorisiert genau eine Anmeldung, deren Tenant wird für den Zugriff ermittelt)
- ✅ Secret Key Management (`PDF_TOKEN_SECRET`, `API_SECRET_KEY` in `.env`; `TENANT_API_SECRET` nur serverseitig, nie im Browser; WP-Einstellung ist write-only)
- ✅ Admin Authentication (Optional, session-basiert; bei `MULTI_TENANT_ENABLED=true` erzwungen)
- ✅ Session Security (Regeneration, Timeout, CSRF-Protection)
- ✅ Brute-Force Protection (0.5s Delay bei falschen Logins)
- ✅ Rate Limiting (File-based, 10 req/min, konfigurierbar)
- ✅ HTTPS Enforcement (Apache .htaccess + PHP Fallback)
- ✅ Virus Scanning (ClamAV via TCP/INSTREAM, Docker-Service, DSGVO-konform, EICAR-getestet)
- ✅ Audit Trail (JSON-Lines-Log: Login, Status-Änderungen, Uploads, Bulk-Actions, IDOR-Versuche)

**Bekannte Einschränkungen:**
- `GET /api/form-config.php` ist per Tenant-Slug ohne Signatur abrufbar und liefert z. B. `notify_email` — keine Geheimnisse in `config_json` ablegen.
- Signaturen enthalten keinen Zeitstempel (kein Replay-Schutz über die Transportschicht hinaus): HTTPS zwischen Frontend und Backend verwenden.

---
