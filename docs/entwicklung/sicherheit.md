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
- ✅ Brute-Force Protection (0,5 s Verzögerung bei falschen Logins; Sperre nach 5 Fehlversuchen je IP und Benutzername bzw. 20 je IP innerhalb von 15 Minuten, `LoginThrottle`, Audit-Ereignis `login_blocked`)
- ✅ Rate Limiting (File-based, 10 req/min, konfigurierbar)
- ✅ HTTPS Enforcement (Apache .htaccess + PHP Fallback)
- ✅ Virus Scanning (ClamAV via TCP/INSTREAM, Docker-Service, DSGVO-konform, EICAR-getestet)
- ✅ Audit Trail (JSON-Lines-Log: Login, Status-Änderungen, Uploads, Bulk-Actions, IDOR-Versuche)

**Bekannte Einschränkungen:**
- `GET /api/form-config.php` ist per Tenant-Slug ohne Signatur abrufbar und liefert z. B. `notify_email` — keine Geheimnisse in `config_json` ablegen.
- Signaturen enthalten keinen Zeitstempel (kein Replay-Schutz über die Transportschicht hinaus): HTTPS zwischen Frontend und Backend verwenden.
- Rate-Limit und Audit-Log verwenden die Absender-Adresse `REMOTE_ADDR`; hinter einem Reverse-Proxy ist das die Proxy-Adresse, das Limit gilt dann für alle Schulen gemeinsam ([Betriebsmodell](../betreiber/betriebsmodell.md#reverse-proxy-pflicht-sobald-das-backend-nicht-nur-intern-erreichbar-ist)).

## Behobene Befunde (Februar 2026)

Ergebnis einer Sicherheitsdurchsicht; die Maßnahmen sind im Code vorhanden und durch Unit-Tests abgesichert.

| Befund | Maßnahme | Wo |
|---|---|---|
| Fehlender CSRF-Schutz bei Löschen, Wiederherstellen und Sammelaktionen | Token-basierter Schutz mit `hash_equals`: `csrf_token()`, `csrf_validate()`, `csrf_field()`, `csrf_meta()`, `csrf_regenerate()`; Tokens in allen Formularen | `backend/inc/csrf.php`, `hard_delete.php`, `restore.php`, `bulk_actions.php` |
| Fehlender CSRF-Schutz bei Statusänderung und Löschen aus der Detailansicht (3.1.1; zuvor nur durch `SameSite=Lax` abgemildert) | `csrf_validate()` am Anfang des Endpunkts (Fehler ⇒ 403), `csrf_field()` in allen Formularen der Detailansicht; Struktur-Test stellt beides sicher | `change_status.php`, `detail.php`, `ChangeStatusCsrfTest` |
| Open Redirect über `return_url` bei Statusänderung und Löschen (3.1.1) | Ziel wird auf einen lokalen Skriptnamen mit optionaler Query beschränkt (kein Schema, Host, Pfad, Fragment, Steuerzeichen), sonst `index.php` | `App\Utils\SafeRedirect`, `change_status.php`, `SafeRedirectTest` |
| Datei-Upload nur nach Dateiendung geprüft | MIME-Typ per `finfo` aus dem **Inhalt**, Whitelist mit passenden Endungen (Doppelendungen wie `evil.php.jpg` und getarnte Dateien werden abgelehnt); `doc`/`docx` sind wegen Makro-Risiko **nicht** freigegeben (im Code auskommentiert) | `AnmeldungValidator`, `upload.php` |
| Mögliches XSS in der Detailansicht (Feldnamen) | `htmlspecialchars` beim Aufbereiten der Feldnamen und beim Ausgeben (doppelte Absicherung) | `DetailController::humanizeKey()`, `detail.php` |
| Rate-Limit durch User-Agent-Wechsel umgehbar | Schlüssel aus IP, SHA-256 des User-Agents und der Accept-Language | `RateLimiter::generateFingerprint()`, `submit.php` |
| Session-Fixation bei Logout und Ablauf | `session_regenerate_id(true)` bei Login, Logout und Timeout | `inc/auth.php`, `login.php`, `logout.php` |
| SQL-Injection über den Formular-Filter beim Export | Filter wird validiert (Repository und Service) | `AnmeldungValidator::validateFormularName()` |
| Directory Traversal beim Upload und Download | `basename()`, Dateinamen-Validierung, Pfad muss im Tenant-Verzeichnis liegen | `upload.php`, `DownloadController` |

## Missbrauchsschutz der öffentlichen Anmeldung (3.1.2)

| Maßnahme | Wirkung | Wo |
|---|---|---|
| Besucher-Kennung für das Rate-Limit | Das Frontend schickt `X-Ondisos-Client` (HMAC der Client-IP mit dem Tenant-Secret, nicht umkehrbar). Das Backend zählt je Besucher statt je Frontend-Server: ein Angreifer sperrt nicht mehr den Kanal für alle. Die Kennung ist nicht signiert, sie begrenzt nur. | `BackendApiClient`, `App\Utils\ClientIdentity`, `submit.php`, `upload.php` |
| Honeypot-Feld `website_url` | Unsichtbares Feld, das die Survey-Handler anlegen. Ein Treffer verwirft die Anmeldung still (Antwort: Erfolg, kein Backend-Aufruf, keine Mail). Schützt vor Bots, die alle Felder befüllen; nicht vor Bots, die den Endpunkt direkt ansprechen (dort greift das Rate-Limit). | `Frontend\Utils\Honeypot`, `save.php`, `class-ajax-handler.php`, `survey-handler*.js` |
| Rate-Limit für Uploads | je Besucher (`UPLOAD_RATE_LIMIT_MAX` je `UPLOAD_RATE_LIMIT_WINDOW`) | `upload.php` |
| Feldname gegen die Survey | Der Upload muss zu einer Datei-Frage des veröffentlichten Formulars gehören (`foto` oder `foto_0`). Formulare, deren Survey nur als Datei im Frontend liegt, werden hier nicht geprüft. | `App\Services\UploadPolicy` |
| Grenzen je Anmeldung | höchstens `UPLOAD_MAX_FILES_PER_ANMELDUNG` Dateien (20) und `UPLOAD_MAX_BYTES_PER_ANMELDUNG` (50 MB); Überschreiben desselben Namens zählt nicht doppelt; Antwort 429 und Audit-Eintrag | `upload.php`, `UploadPolicy::capError()` |

Offen: Der Zähler je Besucher bleibt umgehbar, wer viele IP-Adressen hat (Botnetz). Dagegen helfen nur Captcha oder vorgeschaltete Filter; bewusst nicht vorgesehen. Die Signatur des Uploads deckt den Dateiinhalt seit Format 2 ab (siehe unten).

## Content-Security-Policy (3.1.2)

Die Verwaltungsoberfläche und die Standalone-Formularseite senden eine CSP, die **Skripte nur von der eigenen Herkunft oder mit der Nonce der Anfrage** zulässt (kein `unsafe-inline`, kein `unsafe-eval` bei Skripten).
Eingeschleustes HTML, etwa aus einem Anmeldefeld, einer Survey oder einer Ausgabe, die einmal nicht escaped wird, kann damit kein JavaScript ausführen, auch kein `onerror=`/`onclick=`.

| | Verwaltungsoberfläche | Formularseite (Standalone) |
|---|---|---|
| Skripte | `'self'` + Nonce | `'self'` + Nonce |
| Styles | `'self'` + `'unsafe-inline'` (Style-Attribute, kleine `<style>`-Blöcke) | wie links (SurveyJS setzt Inline-Styles) |
| Bilder | `'self'`, `data:`, `blob:` (Unterschriften, Altdaten) | zusätzlich `https:` (Bilder aus Surveys und Themes) |
| Sonst | `connect-src`, `frame-src`, `form-action`, `base-uri` `'self'`, `object-src 'none'`, `frame-ancestors 'self'` | wie links, aber **ohne** `frame-ancestors` (Schulen binden das Formular per iframe ein) |
| Wo gesetzt | `inc/header.php`, `login.php` (`App\Utils\ContentSecurityPolicy`) | `index.php` (`Frontend\Utils\ContentSecurityPolicy`) |

**Regeln für Entwickler:** keine Inline-Handler (`onclick=` usw.); Bestätigungen über `data-confirm`, Auto-Submit über `data-autosubmit`, Neuladen über `data-reload` (`assets/js/admin-ui.js`); Inline-`<script>` nur mit
`nonce="<?= \App\Utils\ContentSecurityPolicy::nonce() ?>"`. `ContentSecurityPolicyTest` prüft das für alle Seiten in `backend/public` und `backend/inc`. Neue 2FA-Seiten (PLAN-3.1.2) folgen derselben Regel (WebAuthn-Skript als Datei oder mit Nonce).

**Betrieb:** `CSP_MODE=enforce` (Standard), `report-only` (nur Meldungen in der Browser-Konsole, zum Diagnostizieren nach einem Update) oder `off`. Die Vorschau im Formular-Editor sendet ihre eigene, strengere Richtlinie (Sandbox mit Nonce).
Das **WordPress-Plugin** sendet keine CSP: Die Seite gehört WordPress (Theme, Sicherheits-Plugins). Wer dort eine CSP setzt, muss die Nonce-Skripte des Plugins berücksichtigen.
Zusätzliche Header am Webserver gelten **gemeinsam** mit denen der Anwendung (die strengere gewinnt): keine zweite CSP in Apache oder Nginx setzen.

## Signierte API: Signaturformat 2 (3.1.2)

Das Frontend signiert jede Anfrage an `submit.php`, `upload.php` und `forms.php` mit dem Tenant-Secret. Format 2 (Header `X-Timestamp`, `X-Nonce`, `X-Signature`) schließt die Lücken des alten Formats:

| Eigenschaft | Format 1 (bis 3.1.1) | Format 2 |
|---|---|---|
| Replay | möglich, beliebig oft und beliebig spät | Zeitstempel höchstens `API_SIGNATURE_MAX_SKEW` (300 s) alt, Nonce nur einmal (`ReplayGuard`, `cache/replay/`) |
| Zweck | Signatur eines Endpunkts war nur durch die Form des Inhalts abgegrenzt | Zweck (`submit`, `upload`, `forms`) steckt in der Signatur |
| Upload-Inhalt | nicht signiert: gleicher Dateiname, anderer Inhalt | SHA-256 der Datei ist Teil der Signatur |

Der Aufbau steht in `HmacValidator::canonicalV2()` (Backend) und `BackendApiClient::signatureHeaders()` (Frontend); `SignatureVerifier` prüft Format, Frische und Wiederholung. Ein Request mit v2-Headern und falscher Signatur wird nie auf Format 1 zurückgestuft.

**Umstellung:** `API_SIGNATURE_MODE=compat` (Standard) nimmt beide Formate an und protokolliert Format 1 höchstens einmal pro Stunde und Tenant als `api_legacy_signature` im Audit-Log; `strict` nimmt nur Format 2 an. Reihenfolge: Backend aktualisieren, dann Frontends und Plugin, im Audit-Log auf das Ende von `api_legacy_signature` warten, dann `strict`. Ein Frontend mit Format 2 an einem älteren Backend scheitert mit 401; `API_SIGNATURE_VERSION=1` im Frontend ist der Notausstieg.

**Voraussetzung:** Die Uhren von Frontend- und Backend-Server dürfen um höchstens 5 Minuten abweichen (NTP). Bei Abweichung steht `API signature rejected (…): timestamp_skew` im Fehlerlog des Backends.

Der Replay-Schutz ist dateibasiert (ein Backend-Server) wie das Rate-Limit. Das Transportproblem (HTTPS zwischen Frontend und Backend) bleibt Pflicht: Die Signatur schützt nicht vor dem Mitlesen.

## Bewusst akzeptierte Restrisiken

Ergebnis des Audits vom Oktober 2026 (Punkte ohne Codeänderung):

| Thema | Einordnung |
|---|---|
| Betrieb ohne Login (Modell A, `AUTH_ENABLED=false`) | Jeder im Netz hat alle Rechte, das Audit-Log nennt keinen Benutzer, und die Empfänger-Adresse der Benachrichtigungen ist änderbar. Zulässig **nur im Verwaltungsnetz** (Sekretariat und erweiterte Schulleitung, vom Schüler-, Lehrer- und Gästenetz getrennt); in jedem anderen Netz ist Login Pflicht. Siehe [Betriebsmodell](../betreiber/betriebsmodell.md#verwaltungsoberfläche-verwaltungsnetz-oder-login). Die Netz-Allowlist ohne Login ist für 3.1.2 geplant (PLAN-3.1.2, AP2b). |
| `GET /api/form-config.php` ohne Signatur | liefert u. a. `notify_email`; die Antworten 401/404 verraten, ob ein Tenant-Slug existiert. Keine Geheimnisse in `config_json`. |
| WordPress-Plugin: Tenant-Secret in `wp_options` | Das Secret liegt im Klartext in der WordPress-Datenbank (die Oberfläche zeigt es nie wieder an). Wer Code in WordPress ausführen oder die Datenbank lesen kann, kann Anmeldungen signieren: bei Verdacht das Secret im Backend erneuern. |
| Vorbefüllung per `?prefill=<base64>` | Personenbezogene Daten stehen in der URL (Browser-Verlauf, Proxy- und Access-Logs, Referer). Nur verwenden, wenn die Schule das so will; keine sensiblen Felder vorbefüllen. |
| PDF-Links (Token) | Der Link ist ein Bearer-Link: 30 Minuten gültig, nicht einmalig, nicht widerrufbar. Wer ihn in einer E-Mail oder einem Log findet, kann das PDF laden. |
| E-Mail über PHP `mail()` | keine SMTP-Authentifizierung und keine TLS-Kontrolle (SMTP ist geplant). |
| Keine Content-Security-Policy für Admin-Oberfläche und Formularseite | Nur der Vorschau-Frame hat eine CSP. Eine Standard-CSP ist als Härtung offen. |

Spätere Maßnahmen (Tenant-Isolierung, signierte API, Secret-Policy, Härtung des Formular-Editors) stehen im [Changelog](changelog.md).
