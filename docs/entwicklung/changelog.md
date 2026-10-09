# Änderungshistorie

Versionsübersicht. Details zu 3.1: [Release Notes](releases/RELEASE-NOTES-3.1.0.md), [PLAN-3.1.md](plans/PLAN-3.1.md).


## 3.1.2

**Sicherheit**
- ✅ Netz-Allowlist für Admin-Zugänge (AP1, AP2, AP2b): `PLATFORM_ADMIN_ALLOWED_NETWORKS` (`.env`) und `tenants.admin_allowed_networks` (je Tenant unter `tenants.php`); Prüfung beim Login und bei jedem Seitenaufruf, ohne Login (`AUTH_ENABLED=false`) für jede Browser-Anfrage. Neue Klassen `App\Utils\CidrList` (aus `ClientIp` herausgelöst) und `App\Services\AdminNetworkPolicy`; Audit-Ereignisse `login_network_denied`, `admin_network_denied`, `admin_networks_changed`; Notausstieg `clear-network-restriction.php`. Migration: Spalte `tenants.admin_allowed_networks` (`php migrate.php`). Details: [sicherheit.md](sicherheit.md)
- ✅ Zwei-Faktor-Anmeldung mit Authenticator-App (AP3): TOTP (`spomky-labs/otphp`, QR-Code als SVG mit `bacon/bacon-qr-code`), Pflicht für Platform-Admins im Mehrschul-Betrieb mit Schonfrist, Einrichtungs-Assistent mit Recovery-Codes, „Mein Konto“, Zurücksetzen durch Platform-Admin oder `reset-2fa.php`. Neue `.env`-Werte `TWO_FACTOR_REQUIRED`, `TWO_FACTOR_GRACE_LOGINS`, `TWO_FACTOR_KEY` (Pflicht, wenn 2FA genutzt wird), neue Tabellen `admin_two_factor` und `admin_recovery_codes` (`php migrate.php`). Betrieb: [zwei-faktor.md](../betreiber/zwei-faktor.md)
- ✅ Content-Security-Policy für die Verwaltungsoberfläche und die Standalone-Formularseite (Skripte nur mit Nonce, `CSP_MODE=enforce|report-only|off`): die Seiten haben keine Inline-Handler mehr (`data-confirm`, `data-autosubmit`, `assets/js/admin-ui.js`). Details: [sicherheit.md](sicherheit.md#content-security-policy-312)
- ✅ Signaturformat 2 für die API (`X-Timestamp`, `X-Nonce`, Inhaltshash, auch für Dateien): Replay-Schutz und Zeitfenster von 5 Minuten; `API_SIGNATURE_MODE=compat` nimmt das alte Format noch an und protokolliert es (`api_legacy_signature`), `strict` schaltet es ab. Hinweis: Uhren von Frontend und Backend müssen übereinstimmen. Details: [sicherheit.md](sicherheit.md#signierte-api-signaturformat-2-312)
- ✅ Missbrauchsschutz der Anmeldung: Rate-Limit je Besucher statt je Frontend-Server (`X-Ondisos-Client`), Honeypot-Feld in beiden Frontends, Upload-Rate-Limit, Prüfung des Upload-Feldnamens gegen die Survey und Grenzen je Anmeldung (20 Dateien, 50 MB; `UPLOAD_MAX_*_PER_ANMELDUNG`). Details: [sicherheit.md](sicherheit.md#missbrauchsschutz-der-öffentlichen-anmeldung-312)
- ✅ Login-Drosselung (`LoginThrottle`): 5 Fehlversuche je IP und Benutzername, 20 je IP in 15 Minuten, Audit-Ereignis `login_blocked`; gleiche Laufzeit bei unbekanntem Benutzer (Dummy-Hash)
- ✅ Session-Cookies: `HttpOnly`, `SameSite=Lax` und `Secure` (Backend über `SESSION_SECURE`, Frontend automatisch bei HTTPS) werden im Code gesetzt (`App\Utils\SessionCookie`), nicht nur in der Docker-`php.ini`
- ✅ Excel-Export (setzt ggf. den Status) und Mandantenwechsel nur noch per POST mit CSRF-Token; der Mandantenwechsel war ein GET-Link
- ✅ Metadaten der Anmeldung (`submitted_at`, `ip_address`, `user_agent`) kann der Browser nicht mehr überschreiben; WordPress-Plugin prüft den Nonce vor dem Formularschlüssel
- ✅ PDF-Logo nur aus `templates/pdf/assets` und `uploads` (kein beliebiges Bild des Servers); `uploads/.htaccess` und Apache-Konfiguration sperren direkten Zugriff
- ✅ Docker: MySQL und phpMyAdmin nur an `127.0.0.1`; `php.ini` ist auf Produktion ausgelegt (keine Fehleranzeige, OPcache ohne Zeitstempelprüfung), die Entwicklungswerte setzt der Entrypoint bei `APP_ENV=development`
- ✅ Excel-Export schreibt Eingaben immer als Text (keine Formel-Injection); CSRF-Token für „Jetzt manuell ausführen“ im Dashboard; SVG-Uploads nicht mehr erlaubt; Downloads mit `nosniff`, Inline-Anzeige nur für PDF und Bilder
- ✅ `AUTH_ENABLED=true` ist Standard in `.env.example` und im Docker-Entrypoint (ändert keine bestehende `.env`); wer ohne Login arbeitet, setzt `false` bewusst und sollte `PLATFORM_ADMIN_ALLOWED_NETWORKS` auf das Verwaltungsnetz setzen

**Hilfe**
- ✅ Hilfe-Button „?“ in der Navigationsleiste des Backends: öffnet die zur Seite passende Dokumentation (Anmeldeliste → Handreichung und ASV, Formulare → Formulare pflegen, Tenants → Multi-Tenant usw.). Standardziel ist GitHub in der installierten Version (`blob/v<Version>/`); Betreiber können mit `HELP_BASE_URL` eine eigene Kopie angeben (`App\Utils\HelpLinks`, `App\Config\Version`). Ein Test prüft, dass jedes Ziel samt Anker existiert

**Funktionen**
- ✅ Tenant löschen (Plattform-Admin, `tenants.php`): nur inaktiv, ohne Formulare und ohne Anmeldungen (auch Papierkorb; die Fremdschlüssel kaskadieren), Tenant 1 nie. Prüfung unter Zeilensperre in `TenantRepository::delete()` (`TenantDeletionPolicy`), danach Upload-Verzeichnis entfernen (`TenantUploadCleaner`) und Audit-Ereignis `tenant_deleted`
- ✅ Unterschriftsfelder (SurveyJS `signaturepad`, Wert als PNG/JPEG-Data-URI): Detailansicht und PDF zeigen das Bild, Excel und Benachrichtigungs-Mail den Platzhalter „[Unterschrift]“; `submit.php` lehnt andere `data:`-Werte in Feldern ab (`DataFormatter::isImageDataUri()`, höchstens 500.000 Zeichen, kein SVG)

**Korrekturen**
- ✅ Excel-Export ohne CSRF-Token liefert 403 statt eines Fehlers 500; das Slug-Muster im Formular zum Anlegen eines Tenants ist für aktuelle Browser gültig (`[a-z0-9\-]+`)

**Betrieb**
- ✅ Neue Werte in der `.env`: `PLATFORM_ADMIN_ALLOWED_NETWORKS`, `TWO_FACTOR_REQUIRED`, `TWO_FACTOR_GRACE_LOGINS`, `TWO_FACTOR_KEY` (auch in `docker-compose.yml` und im Entrypoint); `API_SIGNATURE_MODE`, `CSP_MODE`, `UPLOAD_MAX_*_PER_ANMELDUNG`
- ✅ Neue Skripte: `clear-network-restriction.php <slug>`, `reset-2fa.php <benutzer>`; neue Composer-Pakete `spomky-labs/otphp` und `bacon/bacon-qr-code` (mit vier kleinen Abhängigkeiten)
- ✅ Migration `php migrate.php` (Docker: beim Start): Spalte `tenants.admin_allowed_networks`, Tabellen `admin_two_factor` und `admin_recovery_codes`

## 3.1.1

**Betrieb und Sicherheit**
- ✅ Reverse-Proxy: `TRUSTED_PROXIES` (IPs/CIDR); nur von dort wird `X-Forwarded-For` für Rate-Limit und Audit-Log ausgewertet (`App\Utils\ClientIp`, im Frontend `Frontend\Utils\ClientIp` für die IP in der Anmeldung; im WordPress-Plugin als Einstellung *Vertrauenswürdige Proxys* (`ondisos_trusted_proxies`); dort wurden bisher `Client-IP`/`X-Forwarded-For` u. a. ungeprüft übernommen), leer = Header ignoriert. Zuvor vertraute das Audit-Log dem Header bedingungslos, und hinter einem Proxy teilten sich alle Schulen ein Rate-Limit
- ✅ Docker: MySQL wird im Produktions-Overlay nicht mehr auf dem Host-Port veröffentlicht (das `3306:3306` der Basisdatei wurde beim Zusammenführen nur ergänzt, nicht ersetzt)
- ✅ Docker: `SESSION_SECURE` und `FORCE_HTTPS` lassen sich über die Root-`.env` schalten; `BACKEND_BIND` und `BACKEND_PORT` binden das Backend an localhost, wenn ein Reverse-Proxy auf demselben Server davor steht
- ✅ Dokumentation: Reverse-Proxy ist Pflicht, sobald das Backend nicht nur im vertrauenswürdigen Intranet erreichbar ist ([Betriebsmodell](../betreiber/betriebsmodell.md#reverse-proxy-pflicht-sobald-das-backend-nicht-nur-intern-erreichbar-ist))

**Funktionen**
- ✅ Sammelaktionen in der Anmeldungsliste: Die Statusbuttons der Detailansicht (📝 In Bearbeitung, ☑️ Akzeptiert, 👎 Abgelehnt) gibt es auch für die Auswahl (zwischen „Archivieren“ und „Löschen“); `BulkActionsController`, `StatusService::bulkUpdateStatus()`, Audit-Ereignisse `bulk_in_bearbeitung`, `bulk_akzeptiert`, `bulk_abgelehnt`

**Korrekturen**
- ✅ Sicherheit: Open Redirect in `change_status.php` behoben: `return_url` wird über `App\Utils\SafeRedirect::local()` auf Seiten der Oberfläche (`name.php?query`) beschränkt, alles andere führt auf `index.php`
- ✅ Sicherheit: `change_status.php` (Statuswechsel und Löschen aus der Detailansicht) prüfte kein CSRF-Token; jetzt `csrf_validate()` (ungültig oder fehlend ⇒ 403) und `csrf_field()` in den Formularen der Detailansicht (Struktur-Test `ChangeStatusCsrfTest`)
- ✅ Namenssuche in der Anmeldungsliste: Die Filterfelder lagen in verschachtelten Formularen im Sammelformular, Enter im Namensfeld löste „Fehler: Invalid action“ aus; jedes Filterfeld hat jetzt ein eigenes GET-Formular außerhalb des Sammelformulars (Struktur-Test `IndexFormStructureTest`)
- ✅ Der Excel-Export einer einzelnen Anmeldung setzt den Status auf „Exportiert“ (wie der Listen-Export)
- ✅ Die Detailansicht zeigt das Status-Label statt des Rohwerts (`in_bearbeitung`), findet Uploads im Tenant-Verzeichnis, und die Datei-Heuristik trifft nicht mehr Felder wie „Ausbildungsbetrieb“
- ✅ WordPress-Plugin: Überschrift „Verfügbare Formulare“ nur einmal; Einstellung *Vertrauenswürdige Proxys*
- ✅ Tests: Unit-Tests laufen im Dev-Container, `LoginTest` unabhängig von den bcrypt-Kosten (PHP 8.5)
- ✅ Release: feste Asset-Pfade an den Release-Links ergeben einen Permalink auf die neueste Plugin-ZIP ([CI_CD.md](CI_CD.md#release-wordpress-plugin))

**Dokumentation neu geordnet:** nach Rollen (Sekretariat, Redaktion, Schul-IT, Betreiber, Entwicklung) mit Handreichung für das Sekretariat, Betriebsmodell, Installation, Betrieb, Notfall-Handbuch und Plugin-Installation per ZIP bzw. Git; `CLAUDE.md` ist nur noch der Entwickler-Einstieg ([Index](../README.md)).

## 3.1

**Nachträge aus der ersten Praxis**
- ✅ Formular-Editor in Tabs (Allgemein · Benachrichtigungs-E-Mail · PDF-Bestätigung · Kalender-Download · Info), Speichern/Abbrechen neben den Tabs; Tab mit Fehlern wird geöffnet und markiert
- ✅ Ein Logo pro Schule: Upload auf *Formulare* (`forms.php`) durch Tenant-Admins, gespeichert unter `uploads/tenant-<id>/branding/logo.png|jpg` (nur PNG/JPEG, max. 2 MB, per GD neu kodiert). Reihenfolge im PDF: `logo: false` (keins) → Pfad in der Config (Plattform-Admin) → Schul-Logo → `PDF_LOGO_<FORM>` → `PDF_LOGO_PATH`
- ✅ Akzentfarbe der PDFs pro Schule (Balken links an Einleitung und Abschnitten): Farbfeld + Hex-Eingabe auf *Formulare* (`forms.php`), gespeichert als `uploads/tenant-<id>/branding/accent.txt` (`TenantAccentColor`, nur `#rrggbb`; Standard `#3498db`), über `PdfLogoResolver` als `accent_color` in die PDF-Konfiguration und vom `PdfTemplateRenderer` ins Stylesheet eingesetzt
- ✅ Angehängtes PDF pro Formular: Upload im PDF-Tab des Formular-Editors (`FormPdfAttachmentService`, `uploads/tenant-<id>/forms/<form>/attachment.pdf`, höchstens 5 MB / 20 Seiten, Prüfung durch Probe-Import); wird hinter jede PDF-Bestätigung gesetzt (Download, Admin-Download, Vorschau), auch für bereits eingegangene Anmeldungen. Nicht lesbar sind PDFs mit komprimierter Querverweistabelle (PDF 1.5+ aus manchen Office-Programmen) und verschlüsselte; sie werden beim Upload mit Hinweis abgelehnt. Wird beim Kopieren von Formularen nicht übernommen
- ✅ PDF: Abschnitte ohne Titel und Text werden nicht ausgegeben (keine leeren Kästen)
- ✅ PDF-Vorschau (`form_pdf_preview.php`): Beispielangaben aus der Survey (Feldname, 1.1.2000, 1, erste Auswahl), nutzt die ungespeicherten Formularwerte, Wasserzeichen „VORSCHAU"
- ✅ Docker-Image: GD mit JPEG-Unterstützung (`libjpeg-dev`, vorher konnten JPEG-Logos nicht verarbeitet werden)

**Formulare im Backend pflegen** (Plan: [PLAN-3.1.md](plans/PLAN-3.1.md), Upgrade: [MIGRATION-3.1.md](../betreiber/MIGRATION-3.1.md))
- ✅ Neue Tabellen `form_resources` (veröffentlichte Surveys/Themes), `form_drafts` (ein Entwurf je Formular), `form_revisions` (Verlauf); `migrate.php` (Schritte 8–10)
- ✅ Formular-Editor: Liste, anlegen, Konfiguration als HTML-Formular (Schema-getrieben, rollenabhängig: Tenant-Admins ohne Dateinamen/Logo), Verlauf mit Wiederherstellen, Konflikterkennung
- ✅ Survey-Editor: JSON einfügen/laden (CodeMirror 6), Prüfung mit Zeile/Spalte, Feldänderungen, Diff, Entwurf, Veröffentlichen mit Versionsvorschlag, Wiederherstellen
- ✅ Vorschau im Backend (Entwurf/veröffentlicht, Breitenumschalter) in einem per CSP sandboxed Frame
- ✅ Auslieferung ans Frontend: `form-config.php?with=survey` mit ETag (304), Datei-Cache im Frontend mit Stale-if-error (7 Tage), sichere Einbettung (`JsonEmbed`); Datei-Fallback
- ✅ Neue Tenants: Formulare von einem anderen Tenant kopieren (`copy-forms.php`, Oberfläche), Empfänger/Logo werden nie kopiert; signierter Endpunkt `api/forms.php` → Plugin-Status „Secret passt / 0 Formulare"
- ✅ CLI: `import-surveys.php`, `copy-forms.php`, `seed-forms.php --tenant`
- ✅ Härtung: HTML-Allowlist (auch Rohtext-Prüfung), Auslieferung nur validierter Inhalte, Rate-Limit für Schreibaktionen, Limits (Größen, 100 Formulare/Tenant), Audit-Ereignisse ohne Inhalte
- ✅ Backend ohne CDN (Bootstrap lokal); Fix: `index.php` ohne definierte `$tenantSlug`
- ✅ Dokumentation nach `docs/` verschoben (Index: `docs/README.md`)

## 3.0

**Multi-Tenant**
- ✅ Tenants (`tenants`, `tenant_admins`), Platform-Admin, Tenant-Switcher, Tenant-Verwaltung (`tenants.php`)
- ✅ Datenisolierung: `tenant_id` in allen Abfragen, `TenantContext`, IDOR-Protokollierung
- ✅ Signierte API (HMAC-SHA256 pro Tenant) für Submit und Upload; Upload-Isolierung und -Zuordnungsprüfung
- ✅ Formular-Konfiguration in der Datenbank (`form_configs`), `seed-forms.php`, API `form-config.php`
- ✅ `migrate.php` (idempotent) — läuft im Docker-Backend bei jedem Start

**Frontend / WordPress**
- ✅ `FormConfigLoader`: gemeinsamer Weg, die Config eines Formulars vom Backend zu laden (Standalone `index/save/ical`, WordPress)
- ✅ `BackendApiClient` signiert Requests und hängt den Tenant an
- ✅ WordPress-Plugin (damals 2.1, seit 3.1.0 gleiche Versionsnummer wie das Gesamtprojekt): Config vom Backend, Einstellungen *Tenant-Slug* und *Tenant-API-Secret* (write-only)
- ✅ Gemeinsame JS-Basis `survey-handler-base.js`, Prefill über einfache Query-Parameter, `placeholderExpression`

**Härtung**
- ✅ `SecretPolicy`: Platzhalter-/Standard-Secrets authentifizieren nichts; `migrate.php` ersetzt den Platzhalter von Tenant 1 durch `API_SECRET_KEY`
- ✅ PDF-Download funktioniert im Multi-Tenant-Modus (Tenant wird aus der Anmeldung ermittelt)

**Sonstiges**
- ✅ Admin-PDF-Download und Prev/Next-Navigation in der Detailansicht
- ✅ Dateinamen-Sanitizing (`FilenameSanitizer`), Download-Links für Uploads in E-Mails
- ✅ Endgültiges Löschen (Hard-Delete, Auto-/manuelles Expunge) entfernt auch die Upload-Dateien (`UploadCleanupService`)
- ✅ Docker: Migration bei jedem Start, `docker compose` (Compose-Plugin), Makefile

**Upgrade von 2.x:** siehe [MIGRATION-3.0.md](../betreiber/MIGRATION-3.0.md).

---

**Ende der Dokumentation**
