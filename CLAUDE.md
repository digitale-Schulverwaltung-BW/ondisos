# Schulanmeldungs-System - Projekt-Dokumentation

## 📋 Projekt-Übersicht

**Zweck:** Webbasiertes System für Schulanmeldungen mit SurveyJS-Frontend und PHP-Backend. Ab Version 3.0 **mandantenfähig**: Eine Backend-Instanz kann mehrere Schulen (*Tenants*) bedienen.

**Stack:**
- **Frontend:** SurveyJS, Vanilla JavaScript, Bootstrap 5 (Standalone-PHP-Frontend **oder** WordPress-Plugin)
- **Backend:** PHP 8.2+, MySQL/MariaDB
- **Architecture:** Clean MVC mit Service Layer

**Deployment:**
- Frontend-Server: Öffentlich zugänglich, zeigt SurveyJS-Formulare
- Backend-Server: Intranet, Admin-Interface für Anmeldungsverwaltung

**Kernkonzepte (3.0, erweitert in 3.1):**
- **Tenant** = eine Schule. Jede Anmeldung, jede Formular-Konfiguration und jedes Upload-Verzeichnis gehört zu genau einem Tenant. Tenant 1 (`slug = default`) gibt es immer; der Betrieb mit nur einer Schule ist der Single-Tenant-Modus (`MULTI_TENANT_ENABLED=false`).
- **Signierte API:** Das Frontend authentifiziert sich pro Tenant mit HMAC-SHA256 (`X-Signature`) und nennt den Tenant per `?tenant=<slug>`. Das Secret (`tenants.api_secret`) bleibt serverseitig.
- **Formular-Konfiguration in der Datenbank:** Tabelle `form_configs`; das Frontend holt sie per `GET /api/form-config.php`.
- **Formulare im Backend pflegen (3.1):** Schul-Admins ändern die Konfiguration eines Formulars in einem HTML-Formular (kein JSON) und bearbeiten die Survey
  (Text aus dem SurveyJS-Creator einfügen → prüfen → Entwurf → Vorschau → veröffentlichen, mit Verlauf und Wiederherstellen). Surveys und Themes liegen
  dann in der Datenbank (`form_resources`) und kommen mit der Config zum Frontend; die Dateien in `frontend/surveys/` bleiben als Fallback.
  Neue Tenants starten mit den Formularen eines anderen Tenants (`FormCopyService`; Empfänger und Logo werden nie kopiert).
  Der Creator selbst ist **nicht** Teil von Ondisos (proprietäre Lizenz), siehe [SURVEYJS.md](docs/SURVEYJS.md).

**Weiterführende Dokumente:** [docs/README.md](docs/README.md) (Index) · [MIGRATION-3.1.md](docs/MIGRATION-3.1.md) (Upgrade 3.0 → 3.1) · [MIGRATION-3.0.md](docs/MIGRATION-3.0.md) (Upgrade von 2.x) · [MULTI-TENANT.md](docs/MULTI-TENANT.md) (Betrieb mehrerer Schulen) · [DEPLOYMENT.md](docs/DEPLOYMENT.md) · [wordpress-plugin/INSTALL.md](wordpress-plugin/INSTALL.md)

---

## 🏗️ Architektur

### Gesamtstruktur

```
projekt/
├── frontend/                      # Öffentlich zugänglich
│   ├── public/
│   │   ├── index.php             # Formular-Anzeige (holt die Config vom Backend)
│   │   ├── save.php              # API-Endpoint für Submissions
│   │   ├── ical.php              # iCal-Download (optional pro Formular)
│   │   ├── csrf_token.php
│   │   ├── pdf/download.php      # PDF Download Proxy (leitet zum Backend)
│   │   ├── api/messages.json.php # UI-Texte für JavaScript
│   │   └── js/
│   │       ├── survey-handler-base.js  # gemeinsame Basis (Standalone + WordPress)
│   │       └── survey-handler.js       # Standalone-Handler
│   ├── inc/bootstrap.php         # Autoloader, .env laden
│   ├── src/
│   │   ├── Config/
│   │   │   ├── FormConfig.php        # Config-Container (wird per load() befüllt)
│   │   │   ├── FormConfigLoader.php  # holt/merged die Config (+ Survey/Theme) eines Formulars
│   │   │   ├── FormBundleCache.php   # Datei-Cache (ETag, Stale-if-error), frontend/cache/forms
│   │   │   └── SurveySource.php      # Survey/Theme: Backend zuerst, sonst frontend/surveys/*.json
│   │   ├── Services/
│   │   │   ├── AnmeldungService.php
│   │   │   ├── BackendApiClient.php  # signiert Requests, hängt ?tenant= an
│   │   │   ├── EmailService.php
│   │   │   └── MessageService.php
│   │   └── Utils/CsrfProtection.php
│   ├── config/
│   │   ├── forms-config-dist.php     # Vorlage / Quelle für backend/seed-forms.php
│   │   └── messages.php
│   └── surveys/                  # SurveyJS-Definitionen (bs.json, vabo.json, ...)
│
├── wordpress-plugin/              # Alternative zum Standalone-Frontend
│   ├── ondisos.php               # Plugin-Bootstrap, Shortcode [ondisos form="…"]
│   ├── includes/                 # class-shortcode, -ajax-handler, -pdf-proxy, -settings,
│   │                             # -form-config-loader, -assets, -plugin, -autoloader
│   ├── assets/js/survey-handler-wp.js
│   └── INSTALL.md
│
├── docs/                          # Betriebs- und Projektdokumentation (Index: docs/README.md)
│   └── plans/                    # Planungsdokumente (PLAN-3.1.md, …)
│
├── database/
│   ├── schema.sql                # Neuinstallation (Tenant 1 mit Platzhalter-Secret!)
│   └── migrations/               # einzelne SQL-Migrationen (z. B. add_pdf_config_column.sql, add_form_editor_tables.sql)
│
└── backend/                       # Intranet-Admin
    ├── migrate.php               # Schema-Migration auf 3.0 (idempotent)
    ├── seed-forms.php            # forms-config.php → Tabelle form_configs; `[--tenant=<slug>] [<datei>|-]` (Pfad bzw. STDIN), validiert jeden Eintrag
    ├── copy-forms.php            # Formulare von Tenant zu Tenant kopieren (3.1): `--from --to [--forms] [--overwrite] [--dry-run]`
    ├── import-surveys.php        # Survey-/Theme-Dateien → Datenbank (3.1): `[--tenant=<slug>] [--overwrite] [--dry-run] <verzeichnis>`
    ├── public/
    │   ├── index.php · detail.php · trash.php · dashboard.php
    │   ├── forms.php · form_edit.php   # Formular-Editor (3.1): Liste, anlegen, Konfiguration als HTML-Formular, Verlauf, löschen
    │   ├── form_pdf_preview.php · tenant_logo.php   # PDF-Vorschau mit Beispielangaben (GET gespeichert / POST aktuelle Formularwerte); Logo-Thumbnail
    │   ├── form_preview.php · form_preview_frame.php   # Vorschau (3.1): Entwurf/veröffentlichte Survey wie für Besucher, in einem per CSP sandboxed Frame
    │   ├── form_survey.php             # Survey-Editor (3.1): JSON einfügen/laden, prüfen (mit Zeilen), Diff, Entwurf, veröffentlichen, wiederherstellen
    │   ├── assets/                     # preview/ (SurveyJS-Laufzeit, Kopie des Frontends; tools/sync-preview-assets.sh), survey-editor.js; codemirror/survey-editor-cm.js (CodeMirror 6, MIT, vorgebaut; Quellen: tools/survey-editor-bundle/)
    │   ├── excel_export.php · bulk_actions.php · change_status.php
    │   ├── restore.php · hard_delete.php · download.php (Datei-Download)
    │   ├── login.php · logout.php
    │   ├── tenants.php           # Tenant-Verwaltung (Platform-Admin)
    │   ├── pdf/
    │   │   ├── download.php          # PDF per Token (ohne Session)
    │   │   └── admin_download.php    # PDF aus der Detailansicht (Admin)
    │   └── api/
    │       ├── submit.php        # Anmeldung speichern (HMAC)
    │       ├── upload.php        # Datei-Upload (HMAC, Virenscan)
    │       ├── form-config.php   # Formular-Konfiguration je Tenant (öffentlich per Slug); ?with=survey liefert Survey/Theme + ETag
    │       ├── forms.php         # Formular-Schlüssel des eigenen Tenants (HMAC über "forms:<slug>", für den Plugin-Status)
    │       └── health.php
    ├── src/
    │   ├── Config/        Config · Database · EnvLoader · FormConfig · TenantContext
    │   ├── Models/        Anmeldung · AnmeldungStatus (Enum)
    │   ├── Repositories/  AnmeldungRepository · TenantRepository · TenantAdminRepository
    │   │                  FormConfigRepository · FormResourceRepository · FormDraftRepository · FormRevisionRepository  (3.1, alle tenant-gefiltert)
    │   ├── Forms/         (3.1, reine Logik ohne DB) ValidationResult · Identifiers · SurveyValidator · HtmlPolicy · ThemeValidator
    │   │                  SurveyFieldExtractor · SurveyLinter · FormConfigSchema · FormConfigValidator · FormConfigFormMapper · EditorAccess · JsonLocator · SurveyDiff · ServiceResult
    │   ├── Controllers/   AnmeldungController · DetailController · BulkActionsController · DownloadController · FormEditorController · SurveyEditorController · SurveyPreviewController
    │   ├── Services/      AnmeldungService · StatusService · ExportService · SpreadsheetBuilder
    │   │                  ExpungeService · RequestExpungeService
    │   │                  PdfGeneratorService · PdfTemplateRenderer · PdfTokenService
    │   │                  HmacValidator · SecretPolicy · RateLimiter · VirusScanService · AuditLogger · UploadCleanupService
    │   │                  LoginService · MessageService · NominatimService · SchoolLookupService
    │   │                  FormPublishService · FormDeliveryService · SurveyImportService · FormSeedService · FormCopyService  (3.1)
    │   │                  TenantLogoService · PdfLogoResolver  (Schul-Logo: Upload durch Tenant-Admins, Reihenfolge der Logo-Quellen)
    │   ├── Cli/           CliArgs · ImportSurveysCommand · CopyFormsCommand  (Logik der CLI-Skripte, testbar)
    │   ├── Validators/    AnmeldungValidator
    │   └── Utils/         DataFormatter · FilenameSanitizer · NullableHelpers
    ├── inc/               bootstrap · auth · csrf · header · footer · form_editor · form_fields · form_copy (Editor-Helfer)
    ├── templates/pdf/     base.php · styles.css · sections/
    ├── config/            messages.php (+ messages.local.php, forms-config.php als Seed-Fallback)
    ├── scripts/           generate-password-hash.php
    ├── uploads/           tenant-<id>/ je Tenant · cache/ · logs/ (audit.log)
    ├── tests/             Unit/ · Integration/
    └── composer.json · PDF_SETUP.md · UPLOAD_SECURITY.md · UNITTESTS.md · MULTI-TENANT.md
```

### Tenant-Kontext

`TenantContext` (statisch, einmal pro Request) bestimmt den aktiven Tenant; `bootstrap.php` initialisiert ihn:

| Situation | Tenant |
|---|---|
| `MULTI_TENANT_ENABLED=false` | immer Tenant 1 |
| API-Request (`API_REQUEST`) | aus `?tenant=<slug>`; unbekannt/inaktiv ⇒ uninitialisiert ⇒ `401` |
| Browser, Tenant-Admin | aus der Session (`tenant_id`) |
| Browser, Platform-Admin | `switch_tenant`: ein Tenant oder „alle" (`isAllTenants()`) |
| Token-Endpoint (`pdf/download.php`) | Tenant der per Token autorisierten Anmeldung (`findTenantIdById()`) |

Ein nicht initialisierter Kontext wirft eine Exception (kein stilles Durchfallen auf „alle Daten"). Repositories filtern **jede** Abfrage nach `tenant_id` (außer im All-Tenants-Modus); `AnmeldungRepository::findById()` protokolliert fremde IDs als `idor_attempt`.

---

## 🔄 Datenfluss

### Formular anzeigen

```
1. Browser ruft frontend/public/index.php?form=bs auf (oder eine WordPress-Seite mit [ondisos form="bs"])
   ↓
2. FormConfigLoader::ensureWithSurvey('bs') → BackendApiClient::fetchFormBundle()
   GET {BACKEND_API_URL}/form-config.php?form=bs&tenant={TENANT_SLUG}&with=survey
   Header If-None-Match: <ETag der zwischengespeicherten Fassung>
   ↓
3. Backend: Config + veröffentlichte Survey/Theme (nie Entwürfe) + ETag; unverändert ⇒ 304.
   Antwort {"success":true,"config":{…},"survey_json":"…"|null,"theme_json":"…"|null}
   Das Frontend legt sie in FormBundleCache ab (frontend/cache/forms, WordPress: uploads/ondisos-cache)
   (Formular unbekannt ⇒ 404; Backend nicht erreichbar / Tenant abgelehnt ⇒ Wartungsseite 503 bzw. im Plugin eine neutrale Meldung für Besucher und die Diagnose für Administratoren; Ursache im PHP-Log)
   ↓
4. Backend nicht erreichbar ⇒ die zwischengespeicherte Fassung (höchstens 7 Tage alt) wird ausgeliefert;
   ohne Cache ⇒ Wartungsseite 503, bei unbekanntem Formular 404, bei abgelehntem Tenant 503 (Plugin: Fehlermeldung)
   ↓
5. SurveySource: Survey/Theme aus dem Backend, sonst Datei frontend/surveys/<form>.json (Fallback wie in 3.0);
   JsonEmbed kodiert beides neu (\u003C …), damit „</script>" im JSON nie aus dem <script>-Element ausbricht
```

### Submission Flow (Neue Anmeldung)

```
1. User füllt Formular aus
   ↓
2. JavaScript (survey-handler-base.js + survey-handler.js / -wp.js) sammelt Daten
   ↓
3. POST an frontend/public/save.php  (WordPress: admin-ajax.php?action=ondisos_submit)
   CSRF-Prüfung (Standalone) bzw. WP-Nonce
   ↓
4. Frontend AnmeldungService validiert & verarbeitet
   ↓
5. BackendApiClient signiert den Raw-Body mit dem Tenant-Secret und sendet ihn an
   POST {BACKEND_API_URL}/submit.php?tenant={slug}   Header: X-Signature: HMAC-SHA256
   ↓
6. Backend: Tenant auflösen → HMAC prüfen (HmacValidator + SecretPolicy) → Rate Limit →
   Validierung → AnmeldungRepository speichert mit tenant_id → PDF-Token erzeugen
   ↓
7. Uploads: pro Datei POST upload.php?tenant={slug}  (X-Signature über "id:feld:dateiname");
   Backend prüft, dass die Anmeldung zum Tenant gehört, scannt mit ClamAV, speichert in uploads/tenant-<id>/
   ↓
8. EmailService sendet Benachrichtigung
   ↓
9. Success-Meldung (+ PDF-Download-Karte) an User
```

### Admin Workflow

```
1. Login (Pflicht bei MULTI_TENANT_ENABLED=true, sonst optional via AUTH_ENABLED)
   - Platform-Admin (ADMIN_USERNAME/ADMIN_PASSWORD_HASH): sieht alle Tenants, wechselt per Tenant-Switcher,
     verwaltet Tenants unter tenants.php
   - Tenant-Admin (Tabelle tenant_admins): sieht nur den eigenen Tenant
   ↓
2. AnmeldungController holt Daten via Repository (automatisch auf den Tenant gefiltert)
   ↓
3. Status wird automatisch "neu" → "exportiert" gesetzt (bei Excel-Export, AUTO_MARK_AS_READ=true)
   ↓
4. Admin kann:
   - Einzeln ansehen (detail.php) inkl. PDF-Download und Datei-Download
   - Excel exportieren (excel_export.php)
   - Bulk-Actions (archivieren/löschen)
   - Papierkorb verwalten (trash.php)
```

---

## 🗄️ Datenbank-Schema

Maßgeblich ist `database/schema.sql` (Neuinstallation) bzw. `backend/migrate.php` (Upgrade). Hier die Kernstruktur:

```sql
CREATE TABLE tenants (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(255) NOT NULL,
    slug          VARCHAR(100) NULL,              -- adressiert den Tenant in API-Aufrufen (eindeutig)
    origin        VARCHAR(255) NULL,              -- CORS-Origin des Frontends
    api_secret    VARCHAR(255) NOT NULL,          -- HMAC-Secret für submit/upload
    active        TINYINT(1) DEFAULT 1,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE tenant_admins (       -- Backend-Benutzer eines Tenants
    id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, username VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL, is_platform_admin TINYINT(1) DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_username (tenant_id, username)
);

CREATE TABLE form_configs (        -- Formular-Konfiguration je Tenant
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT NOT NULL,
    form_key    VARCHAR(100) NOT NULL,
    config_json LONGTEXT NOT NULL,       -- wie ein Eintrag in forms-config-dist.php, als JSON
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_form (tenant_id, form_key)
);

-- 3.1 (Formular-Editor, Details: docs/plans/PLAN-3.1.md). Alle drei sind tenant-isoliert (FK → tenants, ON DELETE CASCADE).
-- form_resources:  veröffentlichte Surveys/Themes je Tenant, UNIQUE (tenant_id, kind, name), sha256 = Versions-Token
-- form_drafts:     höchstens ein Survey-Entwurf je Formular (based_on_sha = Live-Stand beim Anlegen → Konflikterkennung)
-- form_revisions:  Historie, nur anhängen (config | survey | theme), Aufbewahrung 50 je Formular und Typ

CREATE TABLE anmeldungen (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL DEFAULT 1,             -- FK → tenants.id
    formular VARCHAR(100) NOT NULL,
    formular_version VARCHAR(50) NULL,
    name VARCHAR(255) NULL,
    email VARCHAR(255) NULL,
    status VARCHAR(30) DEFAULT 'neu',
    data LONGTEXT NOT NULL,                       -- JSON mit allen Formulardaten
    pdf_config LONGTEXT NULL,                     -- JSON: PDF-Konfiguration zum Zeitpunkt der Anmeldung
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted TINYINT(1) DEFAULT 0,
    deleted_at DATETIME NULL,
    INDEX idx_tenant (tenant_id), INDEX idx_tenant_formular (tenant_id, formular),
    INDEX idx_tenant_status (tenant_id, status), INDEX idx_formular (formular),
    INDEX idx_email (email), INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Wichtige Felder:**
- `data`: JSON mit allen Formulardaten
- `status`: neu, exportiert, in_bearbeitung, akzeptiert, abgelehnt, archiviert
- `deleted` / `deleted_at`: Soft-delete
- `tenants.api_secret`: Secret für die HMAC-Signatur; `SecretPolicy` lehnt Platzhalter/Standardwerte ab
- Tenant 1 (`slug = default`) wird bei Migration/Seed immer angelegt

---

## ⚙️ Konfiguration

Docker: die **Root-`.env`** ist die Single Source of Truth (DB-Credentials, Secrets). `backend/.env` ist optional; **Werte in `backend/.env` überschreiben die Container-Umgebung** (`EnvLoader::load()`). Im Docker-Betrieb erzeugt der Entrypoint die Datei aus der Container-Umgebung und schreibt sie bei **jedem Start** neu (Marker `# GENERATED-BY-ENTRYPOINT` in Zeile 1; nicht verwaltete Zusatz-Schlüssel bleiben erhalten, eine Datei ohne Marker wird nie angefasst). Geänderte Compose-Variablen brauchen `docker compose up -d backend` (nicht `restart`). Ohne Docker stehen alle Backend-Werte in `backend/.env`.

### Backend (.env)

```bash
# Application
APP_ENV=production            # bei "production" werden bekannte Standard-Secrets abgelehnt
APP_DEBUG=false

# Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=anmeldung
DB_USER=admin
DB_PASS=secret

# Secrets (openssl rand -hex 32). Docker: in der Root-.env; manuell: hier anhängen
PDF_TOKEN_SECRET=...          # min. 32 Zeichen, signiert PDF-Download-Tokens
API_SECRET_KEY=...            # wird beim Migrieren das Secret von Tenant 1

# Multi-Tenant (Default: false)
MULTI_TENANT_ENABLED=false    # true ⇒ Login erzwungen, Tenant-Verwaltung, Tenant-Switcher
ADMIN_USERNAME=               # Platform-Admin (bei MULTI_TENANT_ENABLED=true erforderlich)
ADMIN_PASSWORD_HASH=''        # password_hash(), in EINFACHE Anführungszeichen (Compose-Interpolation von $)

# Auto-Expunge (Tage nach denen archivierte Einträge gelöscht werden)
AUTO_EXPUNGE_DAYS=90

# Auto-Mark as Read (bei Ansicht/Export)
AUTO_MARK_AS_READ=true

# Session / Auth
SESSION_LIFETIME=3600
SESSION_SECURE=true
AUTH_ENABLED=false

# Rate Limiting, Virenscan, PDF-Logo, ...: siehe backend/.env.example
```

### Frontend (.env)

```bash
# Backend API
BACKEND_API_URL=http://intranet.example.com/backend/api

# Tenant (Single-Tenant: default)
TENANT_SLUG=default
TENANT_API_SECRET=...         # Secret des Tenants; signiert Submit/Upload. Nur serverseitig!

# Email
FROM_EMAIL=noreply@example.com
MAIL_HEAD=Eine neue Anmeldung ist eingegangen.

# CORS
ALLOWED_ORIGINS=http://anmeldung.example.com

# File Upload
UPLOAD_MAX_SIZE=10485760
UPLOAD_ALLOWED_TYPES=pdf,jpg,jpeg,png
```

WordPress: `Tenant-Slug` und `Tenant-API-Secret` unter *Einstellungen → Ondisos* (haben Vorrang vor der `.env`).

### Formular-Konfiguration (Tabelle `form_configs`)

Die Konfiguration eines Formulars ist ein JSON-Objekt in `form_configs.config_json` (Tenant + `form_key`).
`frontend/config/forms-config-dist.php` dokumentiert die möglichen Schlüssel und dient als Quelle für
`backend/seed-forms.php` (`--tenant=<slug>`, Standard Tenant 1; neue Formular-Keys werden hinzugefügt, vorhandene nie überschrieben, ungültige Einträge übersprungen; Quelle ohne Argument `../frontend/config/forms-config.php` bzw. `config/forms-config.php`, sonst eine Datei oder `-` für STDIN — im Docker-Betrieb `docker compose exec -T backend php seed-forms.php - < frontend/config/forms-config.php`; das Skript warnt vor `@example.com`-Platzhaltern). Seit 3.1 werden bestehende Formulare im Backend bearbeitet (*Formulare*, `form_edit.php`; Felder und Regeln stehen einmal in `FormConfigSchema`);
`copy-forms.php` kopiert Formulare zwischen Tenants. Per SQL geht es weiterhin.

```php
// Beispiel: Inhalt einer forms-config.php (wird beim Seed zu config_json)
return [
    'bs' => [
        'db' => true,
        'form' => 'bs.json',
        'theme' => 'survey_theme.json',
        'version' => '2026-01-v1',
        'notify_email' => 'sekretariat@example.com',
        'prefill_fields' => ['Ausbildungsbetrieb', 'Ausbilder'],
        'pdf' => [ 'enabled' => true, /* siehe PDF Download System */ ],
        // optional: 'email' => ['intro_template' => …], 'ical' => […]
    ],
];
```

---

## 🎯 Feature-Liste

### ✅ Implementiert

**Multi-Tenant (3.0):**
- Mehrere Schulen pro Backend-Instanz, vollständige Datenisolierung (`tenant_id` in allen Abfragen)
- Platform-Admin (Zugang aus `.env`) mit Tenant-Switcher und Tenant-Verwaltung (`tenants.php`)
- Tenant-Admins (Tabelle `tenant_admins`) sehen nur ihren Tenant
- Signierte API: pro Tenant HMAC-SHA256 (`X-Signature`) für Submit und Upload
- Formular-Konfiguration pro Tenant in der Datenbank (`form_configs`), vom Frontend per API abgerufen
- Upload-Isolierung (`uploads/tenant-<id>/`), Audit-Log mit `tenant_id`, IDOR-Protokollierung
- Härtung: bekannte Platzhalter-/Standard-Secrets authentifizieren nichts (`SecretPolicy`)

**Frontend:**
- SurveyJS-Integration mit lokalen Fonts (DSGVO-konform)
- Standalone-PHP-Frontend **oder** WordPress-Plugin (Shortcode `[ondisos form="…"]`)
- Gemeinsame JS-Basis (`survey-handler-base.js`), Prefill per `?prefill=<base64>` oder einfachen Query-Parametern (`?Klasse=5a`)
- Dynamische Platzhalter (`placeholderExpression`)
- CSRF-Protection
- File-Upload Support
- Automatische Consent-Feld-Filterung
- Clean JavaScript (Class-based)
- PDF Download nach Submission:
  - Token-basiert (HMAC-SHA256, selbstvalidierend)
  - Konfigurierbar per Formular
  - Automatische Anzeige nach erfolgreicher Anmeldung

**PDF System:**
- On-Demand PDF-Generierung (kein permanenter Storage)
- HMAC-basierte Tokens (30 Min Gültigkeit, konfigurierbar)
- Frontend-Proxy für öffentlichen Zugriff (Backend bleibt im Intranet)
- Logo-Support mit automatischer Optimierung
- Custom Sections (Pre/Post Data-Table)
- Field-Filtering (Include/Exclude)
- Form-Feld-Reihenfolge wird beibehalten
- mPDF-Integration (DejaVu Sans für deutsche Umlaute)
- Error Pages mit User-Friendly Design

**Backend Admin:**
- Übersicht mit Pagination & Filterung
- Status-System mit Auto-Status-Update
- Bulk-Actions (Archivieren, Löschen)
- Soft-Delete mit Papierkorb
- Wiederherstellen aus Papierkorb
- Excel-Export mit:
  - Auto-Formatierung (Dates: YYYY-MM-DD → dd.mm.yyyy)
  - Zebra-Striping
  - Auto-Width
  - Frozen Header
  - Metadata-Sheet
  - Formular-Spalte verstecken bei Einzelformular-Export
- Detail-Ansicht mit:
  - Smart Value Detection (URLs, Emails, Dates)
  - File-Download
  - Auto-Mark as Read
- Dashboard mit Statistiken
- Auto-Expunge (request-based, alle 6h)
- Virus Scanning bei Upload (ClamAV TCP/INSTREAM, DSGVO-konform)
- Audit Trail (JSON-Lines: `backend/logs/audit.log`, Login/Status/Upload/Bulk-Events, mit `tenant_id`)
- Admin-PDF-Download in der Detailansicht, Prev/Next-Navigation zwischen Einträgen

**Architecture:**
- Clean MVC mit Service Layer
- Type-Safe PHP 8.2+ (strict_types, typed properties, readonly classes)
- PSR-4 Autoloading
- Dependency Injection vorbereitet
- Exception Handling
- Environment-basierte Config

---

## 📄 PDF Download System

### Übersicht

Nach erfolgreicher Formularübermittlung können Benutzer eine PDF-Bestätigung herunterladen. Das System verwendet HMAC-basierte Tokens für sichere, zeitlich begrenzte Downloads ohne Datenbank-Storage.

### Architektur

```
User submits form
  ↓
Frontend (save.php) → Backend API (submit.php)
  ↓
Backend generiert PDF-Token (HMAC-SHA256)
  ↓
Response mit pdf_download Object (URL: /pdf/download.php?token=...)
  ↓
Frontend (survey-handler.js) zeigt Download-Button
  ↓
User klickt Download → Frontend Proxy (frontend/public/pdf/download.php)
  ↓
Frontend Proxy leitet Anfrage weiter → Backend (backend/public/pdf/download.php)
  ↓
Backend: Token validieren → Tenant der Anmeldung ermitteln → Anmeldung laden → PDF generieren
  ↓
Backend sendet PDF → Frontend Proxy → User
```

**Wichtig:** Der Frontend-Proxy ist notwendig, weil:
- Frontend ist öffentlich erreichbar (Internet)
- Backend ist nur im Intranet erreichbar
- User können das Backend nicht direkt ansprechen
- Der Proxy leitet die Anfrage intern vom Frontend zum Backend weiter

### Token-Format

```
base64(id:timestamp:lifetime:hmac)
```

- **id**: Anmeldungs-ID
- **timestamp**: Unix-Timestamp der Token-Generierung
- **lifetime**: Gültigkeitsdauer in Sekunden
- **hmac**: HMAC-SHA256 Signatur über id:timestamp:lifetime

**Sicherheit:**
- Self-validating (keine DB-Abfrage nötig)
- Timing-safe Vergleich (hash_equals)
- Kann nicht gefälscht werden ohne PDF_TOKEN_SECRET
- Automatische Expiration

### Konfiguration

**Backend .env:**
```bash
# Min 32 Zeichen, generieren mit: openssl rand -hex 32
PDF_TOKEN_SECRET=your-secret-key-here
```

**Formular-Konfiguration** (`form_configs.config_json`, Quelle: `forms-config.php`):
```php
'bs' => [
    'pdf' => [
        'enabled' => true,
        'required' => false,
        'token_lifetime' => 1800,  // 30 Min
        'logo' => '/path/to/logo.png',
        'header_title' => 'Anmeldebestätigung',
        'intro_text' => 'Vielen Dank...',
        'footer_text' => 'Bei Fragen: ...',
        'include_fields' => 'all',
        'exclude_fields' => ['consent_datenschutz'],
        'pre_sections' => [],   // Vor Daten-Tabelle
        'post_sections' => [],  // Nach Daten-Tabelle
    ],
],
```

### Komponenten

**Backend:**
- **PdfTokenService**: Token-Generierung & Validierung
- **PdfGeneratorService**: PDF-Erstellung mit mPDF
- **PdfTemplateRenderer**: Template-System für PDFs
- **DataFormatter**: Daten-Formatierung (shared mit Email)
- **FormConfig**: PDF-Konfiguration laden

**Frontend:**
- **pdf/download.php**: Proxy für PDF-Downloads (leitet Anfragen an Backend weiter)
- **survey-handler.js**: PDF-Download-Button anzeigen
- **AnmeldungService.php**: pdf_download weitergeben
- **messages.php**: PDF-UI-Texte und Error-Messages

**Templates:**
- `backend/templates/pdf/base.php`: Haupt-Template
- `backend/templates/pdf/styles.css`: mPDF-kompatible Styles
- `backend/templates/pdf/sections/`: Header, Footer, Data-Table, Custom-Section

### API Response

**Mit PDF:**
```json
{
  "success": true,
  "id": 123,
  "pdf_download": {
    "enabled": true,
    "required": false,
    "url": "/backend/public/pdf/download.php?token=abc...",
    "title": "Bestätigung herunterladen",
    "expires_in": 1800
  }
}
```

**Ohne PDF:**
```json
{
  "success": true,
  "id": 123
}
```

### Dateiname-Format

```
bestaetigung-{formularname}-{id}.pdf
```

Beispiel: `bestaetigung-bs-123.pdf`

### Logo-Optimierung

Logos werden automatisch:
- Auf max 150px Breite skaliert
- In JPEG konvertiert (kleinere Dateigröße)
- Als Base64 in PDF eingebettet

### Field-Ordering

Die Reihenfolge der Felder im PDF entspricht der SurveyJS-Formular-Reihenfolge.
Metadaten `_fieldTypes` werden von survey-handler.js extrahiert und zur Sortierung verwendet.

### Testing

Siehe `backend/PDF_SETUP.md` für:
- Setup-Anleitung
- Test-Szenarien
- Debugging
- Troubleshooting

---

## 📊 Status-Flow

```
neu (User submitted)
  ↓ (beim Excel-Export wenn AUTO_MARK_AS_READ=true)
exportiert
  ↓ (manuell)
in_bearbeitung
  ↓ (manuell)
akzeptiert / abgelehnt
  ↓ (manuell via Bulk-Action)
archiviert
  ↓ (nach AUTO_EXPUNGE_DAYS)
[soft deleted] → [hard deleted]
```

---

## 🔐 Sicherheit

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

## 🚀 Deployment

> **📖 Vollständige Deployment-Dokumentation:** Siehe **[DEPLOYMENT.md](docs/DEPLOYMENT.md)**

### Quick Overview

Das Projekt bietet **3 Deployment-Optionen**:

| Option | Backend | Frontend | MySQL | Empfehlung |
|--------|---------|----------|-------|------------|
| **1. Docker Backend** | 🐳 Container | 📄 Apache/Nginx | 🐳 Container | ✅ **Empfohlen** |
| **2. Komplett Manuell** | 📄 Apache/PHP | 📄 Apache/PHP | 📄 MySQL Server | Einfachstes Setup |
| **3. Komplett Docker** | 🐳 Container | 🐳 Container | 🐳 Container | Dev/Testing |

### Quick Start (Docker Production)

```bash
# 1. Root .env konfigurieren (Single Source of Truth)
cp .env.example .env
nano .env  # DB_USER, DB_PASS, Secrets

# 2. Secrets generieren
openssl rand -hex 32  # → PDF_TOKEN_SECRET
openssl rand -hex 32  # → API_SECRET_KEY (Secret von Tenant 1; darf kein Standardwert sein)

# 3. Container starten (führt migrate.php bei jedem Start aus)
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

# 4. Formular-Konfiguration einspielen (fügt neue Formulare hinzu, überschreibt nichts)
cp frontend/config/forms-config-dist.php frontend/config/forms-config.php   # anpassen (Vorlage = Beispiele)
docker compose exec -T backend php seed-forms.php - < frontend/config/forms-config.php

# 5. Health Check
curl http://your-server:9080/api/health.php
```

**Credentials-Struktur:**
- ✅ `/.env` - Core Credentials (DB_USER, DB_PASS, Secrets) — **Single Source of Truth**
- ✅ `/backend/.env` - Optional; im Docker-Betrieb vom Entrypoint erzeugt (jeder Start), überschreibt die Container-Umgebung; Zusatz-Schlüssel bleiben erhalten
- ✅ Automatisches Mapping: `DB_USER` → `MYSQL_USER`, keine Duplikation!
- ✅ Frontend (manuell): `frontend/.env` mit `BACKEND_API_URL`, `TENANT_SLUG`, `TENANT_API_SECRET`

**Upgrade von 2.x:** [MIGRATION-3.0.md](docs/MIGRATION-3.0.md). **Mehrere Schulen:** [MULTI-TENANT.md](docs/MULTI-TENANT.md).

### Weitere Themen

Siehe **[DEPLOYMENT.md](docs/DEPLOYMENT.md)** für Details zu:

- **Option 1**: Docker Backend + Manuelles Frontend (empfohlen)
  - Docker-Setup mit vorkonfigurierten Compose-Files
  - Persistenz über Reboots (systemd)
  - Secrets Management
  - Admin Authentication

- **Option 2**: Komplett Manuell
  - Apache/Nginx Setup
  - Composer Dependencies
  - Database Import

- **Option 3**: Komplett Docker
  - Dev/Testing Environment
  - Referenz: [DOCKER.md](docs/DOCKER.md)

- **Wartung & Updates**
  - Docker-Updates & Rollbacks
  - Backup-Strategien (Docker Volumes, Cron)
  - Monitoring

- **HTTPS Enforcement**
  - Apache .htaccess
  - Nginx Reverse Proxy
  - Let's Encrypt (Certbot)
  - HSTS

- **Production Checkliste**
  - Security Checklist
  - Docker-spezifische Checks
  - Testing

---
## 🧪 Testing

### Automated Tests (PHPUnit)

Das Projekt verfügt über eine umfassende PHPUnit Test-Suite mit Unit- und Integration-Tests.

#### Test-Struktur

```
backend/tests/
├── bootstrap.php              # Test-Setup (Autoloader, Env-Variablen)
├── Unit/                      # Unit Tests (ohne DB; mysqli wird per Anonymous-Subclass gemockt)
│   ├── Auth/                  # LoginService
│   ├── Config/                # FormConfig (DB), TenantContext
│   ├── Controllers/           # DetailController
│   ├── Models/                # Anmeldung
│   ├── Repositories/          # Anmeldung (Adjacent/Tenant-Lookup), Tenant*, TenantAdmin
│   ├── Services/              # u. a. HmacValidation, SecretPolicy, BackendApiClient(+Signing),
│   │                          # FormConfigLoader, PdfToken, RateLimiter, VirusScan, AuditLogger, …
│   ├── Forms/                 # 3.1: SurveyValidator, HtmlPolicy, FormConfigValidator, SurveyLinter, Schema-Drift
│   ├── Upload/                # MIME, Sicherheit, Pfad-Isolierung
│   ├── Utils/                 # DataFormatter
│   └── Validators/
└── Integration/               # Tests mit DB (Repositories/AnmeldungRepositoryIsolationTest, Forms/ = Formular-Editor 3.1)
```

Stand: 924 Unit-Tests (`composer test -- --testsuite=Unit`; die 55,7 % Line-Coverage stammen aus einer früheren Messung) und 182 Integration-Tests (`--testsuite=Integration`, brauchen MySQL mit `database/schema.sql`). Der Test-Container braucht die PHP-Extension `mysqli`.

#### Tests lokal ausführen

**1. Dependencies installieren:**
```bash
cd backend
composer install
```

**2. Alle Tests ausführen:**
```bash
composer test
# oder direkt:
./vendor/bin/phpunit
```

**3. Nur Unit Tests:**
```bash
composer test -- --testsuite=Unit
```

**4. Nur Integration Tests:**
```bash
composer test -- --testsuite=Integration
```

**5. Spezifische Test-Klasse:**
```bash
composer test:filter RateLimiterTest
# oder:
./vendor/bin/phpunit --filter RateLimiterTest
```

**6. Mit Code Coverage:**
```bash
composer test:coverage
# Generiert: backend/coverage/index.html
```

**7. Mit ausführlicher Ausgabe (testdox):**
```bash
composer test -- --testdox
```

#### Test-Konfiguration

**phpunit.xml:**
- Bootstrap: `tests/bootstrap.php`
- Test-Suites: Unit, Integration
- Test-Environment-Variablen
- Coverage-Excludes: Config, NullableHelpers

**tests/bootstrap.php:**
- Lädt Composer Autoloader
- Setzt Test-Environment-Variablen
- Definiert Test-Konstanten: `TESTING`, `SKIP_AUTO_EXPUNGE`, `SKIP_AUTH_CHECK`

#### Schwerpunkte der Test-Suite

| Bereich | Was abgesichert ist |
|---|---|
| **Tenant-Isolierung** | `TenantContext`, tenant-gefilterte Repository-Abfragen (`findAdjacentIds`, `findTenantIdById`), Upload-Pfade, Expunge pro Tenant |
| **API-Sicherheit** | `HmacValidator` (Body/Upload-Signatur), `SecretPolicy` (Platzhalter, Dev-Default in Production), Round-Trip Client-Signatur ↔ Validator (`BackendApiClientSigningTest`) |
| **Frontend-Config** | `FormConfigLoader` (laden, mergen, einmalig abfragen), `BackendApiClient::fetchFormConfig` |
| **PDF** | `PdfTokenService` (Token-Format, Ablauf, Manipulation) |
| **Sonstiges** | `RateLimiter`, `MessageService`, `VirusScanService`, `AuditLogger`, Export, Status, Upload-Validierung |

**Nicht durch Unit-Tests abgedeckt:** die Endpoint-Skripte selbst (`public/api/*.php`, `pdf/download.php`) und der Browser-Teil (SurveyJS/JavaScript). Dafür gibt es die Manual Tests unten.

#### Neue Tests schreiben

**1. Test-Klasse erstellen:**
```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class MyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Setup vor jedem Test
    }

    public function testSomething(): void
    {
        $this->assertTrue(true);
    }
}
```

**2. Best Practices:**
- Namespace: `Tests\Unit\*` oder `Tests\Integration\*`
- Strict types: `declare(strict_types=1)`
- setUp/tearDown für Initialisierung/Cleanup
- Descriptive test names: `testMethodDoesWhatWhenCondition`
- Use type hints für alle Parameter
- Test eine Sache pro Test-Methode

**3. Test ausführen:**
```bash
composer test:filter MyServiceTest
```

### GitLab CI/CD Pipeline

Das Projekt verfügt über eine automatisierte GitLab CI/CD Pipeline:

#### Pipeline Stages

```
install → test → coverage → security
```

**install:**
- `install_dependencies`: Composer install, Cache vendor/

**test:**
- `test_unit`: Unit Tests mit testdox, JUnit-Report
- `test_integration`: Integration Tests mit MySQL 8.0 (allow_failure)
- `lint_php`: PHP Syntax-Check für alle .php-Dateien

**coverage:**
- `coverage`: Code Coverage mit Xdebug (nur main/master/develop)
  - HTML-Report als Artefakt (30 Tage)
  - Coverage-Prozentsatz in Pipeline sichtbar

**security:**
- `secret_detection`: GitLab Secret Detection
- `sast`: Static Application Security Testing

#### Pipeline lokal testen

**Mit GitLab Runner:**
```bash
# GitLab Runner installieren
curl -L https://packages.gitlab.com/install/repositories/runner/gitlab-runner/script.deb.sh | sudo bash
sudo apt-get install gitlab-runner

# Pipeline lokal ausführen
gitlab-runner exec docker test_unit
```

**Mit Docker direkt:**
```bash
docker run --rm -v $(pwd):/app -w /app/backend php:8.1-cli \
  bash -c "apt-get update && apt-get install -y git unzip && \
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
  composer install && composer test"
```

#### Pipeline-Konfiguration anpassen

**.gitlab-ci.yml:**
- PHP-Version ändern: `image: php:8.2-cli`
- Test-Kommandos anpassen: `script: - composer test:filter MyTest`
- Coverage nur auf bestimmten Branches: `only: - production`
- Optionale Jobs aktivieren: Code Style, Security Check auskommentieren

### Manual Tests

**Frontend Submission:**
```bash
# 1. Formular öffnen (Standalone) bzw. WordPress-Seite mit [ondisos form="bs"]
http://anmeldung.example.com/index.php?form=bs

# 2. Ausfüllen und absenden
# 3. Check Backend: sollte als "neu" erscheinen
```

**Backend Admin:**
```bash
# 1. Übersicht
http://intranet.example.com/backend/

# 2. Excel Export testen (Status sollte → "exportiert")
# 3. Detail ansehen
# 4. Bulk-Action: Archivieren
# 5. Papierkorb prüfen
```

**Auto-Expunge:**
```bash
# Dashboard öffnen
http://intranet.example.com/backend/dashboard.php

# Check "Auto-Expunge Status"
# Sollte zeigen: Letzter Lauf, Nächster Lauf, Anzahl bereit
```

### Test Coverage Ziele

**Gut abgedeckt:** RateLimiter, PdfTokenService, MessageService, VirusScanService, HmacValidator, SecretPolicy, TenantContext, Tenant-Repositories, Upload-Validierung, FormConfigLoader.

**Lücken:**
- ⏳ Endpoint-Skripte (`submit.php`, `upload.php`, `form-config.php`, `pdf/download.php`) — bisher nur manuell/live geprüft
- ⏳ AnmeldungRepository (Integration Tests gegen eine Test-Datenbank)
- ⏳ JavaScript (`survey-handler-*.js`), WordPress-Plugin

**Langfristig:** >80 % Code Coverage, Integration Tests mit Test-Datenbank, E2E-Tests für die kritischen Flows (Submit, Upload, PDF).

---
## 🐛 Known Issues & TODOs

### Known Issues
- ⚠️ Email-Service nutzt PHP `mail()` → ggf. auf SMTP umstellen
- ⚠️ `seed-forms.php` überschreibt vorhandene Einträge nie (Tenant per `--tenant=<slug>`); Änderungen bestehender Formulare laufen über den Editor im Backend
- ⚠️ Surveys liegen entweder im Backend (`form_resources`) oder als Datei im Frontend (Fallback bis 3.2); wer beides pflegt, sieht die Datenbank-Fassung. `import-surveys.php` überträgt die Dateien
- ⚠️ Die Vorschau im Backend trägt eigene Kopien der SurveyJS-Dateien (`backend/public/assets/preview/`); nach SurveyJS-Updates `backend/tools/sync-preview-assets.sh` ausführen
- ⚠️ Der Docker-Apache sendet `X-Frame-Options: SAMEORIGIN` (statt `DENY`), damit der Vorschau-Frame einbettbar ist
- ⚠️ `database/schema.sql` legt Tenant 1 mit dem Platzhalter-Secret an — erst `migrate.php` (oder ein manuell gesetztes Secret) macht ihn nutzbar
- ⚠️ Validierungsmeldungen von SurveyJS erscheinen englisch (keine Locale/i18n-Bundle eingebunden)

### TODOs
1. **Weitere Unit Tests** für Services, Repositories, Validators; Tests für die Endpoint-Skripte
2. **Integration Tests** mit Test-Datenbank
3. **Monitoring** Setup (z.B. Sentry, Prometheus)
4. **API Documentation** (OpenAPI/Swagger)
5. ~~Form-Config Admin-UI und Survey-Pflege im Backend~~ (3.1 ✅, Plan: [PLAN-3.1.md](docs/plans/PLAN-3.1.md))
6. **Managed Multi-Frontend** (ein Frontend für mehrere Tenants, 3.2)
7. **WordPress-Plugin ohne Shell** (ZIP, Verbindungscode, Update-Prüfung; Entwurf: [PLAN-3.1.1.md](docs/plans/PLAN-3.1.1.md))
8. **Datei-Fallback abschaffen** (`frontend/surveys/` nur noch als Importquelle, 3.2)
9. **Logo-Upload** für PDFs statt Dateipfad (bisher nur Plattform-Admin)
10. **Deutsche Locale** für SurveyJS

---

## 📚 Code-Konventionen

**PHP:**
- `declare(strict_types=1)` in jeder Datei
- Type Hints für alle Parameter
- Return Types dokumentieren
- PSR-4 Namespaces
- camelCase für Methoden, PascalCase für Klassen

**Namespaces:**
- Frontend: `Frontend\*`
- Backend: `App\*`

**Dateinamen:**
- Klassen: `PascalCase.php`
- Views: `kebab-case.php`

**Datenbank:**
- snake_case für Tabellen/Spalten
- Prepared Statements IMMER

---

## 🌐 Zentrale Message-Verwaltung

Das System verwendet einen zentralen MessageService für alle UI-Texte, Fehlermeldungen und Labels.
Dies ermöglicht lokale Anpassungen ohne git-Konflikte.

### Architektur

```
Standard Messages (Git)     Local Overrides (.gitignored)
     ↓                              ↓
messages.php                 messages.local.php
     ↓                              ↓
         → Merged at runtime →
                ↓
         MessageService
                ↓
    Placeholder Replacement ({{variable}})
                ↓
         Rendered Output
```

### Dateien

**Backend:**
- `backend/config/messages.php` - Standard-Messages (committed)
- `backend/config/messages.local.php` - Lokale Overrides (gitignored)
- `backend/config/messages.example.php` - Template für lokale Anpassungen
- `backend/src/Services/MessageService.php` - Message Manager

**Frontend:**
- `frontend/config/messages.php` - Standard-Messages (committed)
- `frontend/config/messages.local.php` - Lokale Overrides (gitignored)
- `frontend/config/messages.example.php` - Template für lokale Anpassungen
- `frontend/src/Services/MessageService.php` - Message Manager
- `frontend/public/api/messages.json.php` - JSON API für JavaScript

### PHP Usage

```php
use App\Services\MessageService as M;

// Einfacher Zugriff
echo M::get('ui.buttons.save');  // → "Speichern"

// Mit Fallback
echo M::get('ui.custom_label', 'Default Text');

// Mit Platzhaltern
echo M::format('success.restored', ['id' => 42]);
// → "Eintrag #42 wurde wiederhergestellt"

// Mit automatischem Contact-Info
echo M::withContact('errors.generic_error');
// → "Ein Fehler ist aufgetreten. Bei Problemen: sekretariat@example.com"
```

### JavaScript Usage

```javascript
// Messages werden beim init() geladen
class SurveyHandler {
    async init() {
        await this.loadMessages();  // Lädt von /api/messages.json.php
        // ...
    }

    // Zugriff auf Messages
    const errorMsg = this.msg('errors.submission_failed');

    // Mit Platzhaltern
    const formatted = this.formatMsg('success.count', {count: 5});
}
```

### Lokale Anpassungen

**1. Backend Custom Messages erstellen:**

```bash
cd backend/config
cp messages.example.php messages.local.php
# Edit messages.local.php
```

**2. Beispiel `messages.local.php`:**

```php
<?php
return [
    'contact' => [
        'support_email' => 'sekretariat@meineschule.de',
        'support_text' => 'Bei Problemen: sekretariat@meineschule.de',
    ],

    'ui' => [
        'anmeldungen' => 'Bewerbungen',  // Umbenennen
    ],

    'status' => [
        'neu' => 'Unbearbeitet',  // Custom Label
    ],
];
```

**3. Frontend analog:**

```bash
cd frontend/config
cp messages.example.php messages.local.php
# Edit messages.local.php
```

### Vorteile

✅ **Git-safe**: Lokale Anpassungen in `.local.php` (gitignored)
✅ **Kein Build-Step**: Alles zur Runtime, keine Generierung nötig
✅ **Native PHP**: PHP Arrays statt JSON
✅ **Runtime API**: JavaScript lädt Messages dynamisch via API
✅ **Placeholder-System**: `{{variable}}` für flexible Werte
✅ **Contact-Helper**: Automatische Support-Kontakte in Fehlermeldungen

### Message-Kategorien

**Backend (`backend/config/messages.php`):**
- `validation.*` - Validierungsfehler
- `errors.*` - Fehlermeldungen
- `success.*` - Erfolgsmeldungen
- `ui.*` - UI-Labels, Buttons, Tabellen-Header
- `status.*` - Status-Labels
- `bulk_actions.*` - Bulk-Action-Labels
- `excel.*` - Excel-Export-Metadaten
- `contact.*` - Kontakt-Informationen
- `api.*` - API-Error-Messages

**Frontend (`frontend/config/messages.php`):**
- `errors.*` - Fehlermeldungen
- `success.*` - Erfolgsmeldungen
- `ui.*` - UI-Labels
- `templates.*` - HTML-Templates mit Platzhaltern
- `email.*` - Email-Templates
- `validation.*` - Validierungsmeldungen
- `contact.*` - Kontakt-Informationen
- `forms.*` - Formular-spezifische Messages

### Troubleshooting

**Messages werden nicht geladen (JavaScript):**
```bash
# API testen
curl http://localhost/frontend/api/messages.json.php | jq .

# Browser Console prüfen
# Sollte keine Fehler beim fetch() zeigen
```

**Lokale Overrides werden ignoriert:**
```bash
# Prüfen ob .local.php existiert und nicht leer ist
ls -la backend/config/messages.local.php

# PHP Syntax prüfen
php -l backend/config/messages.local.php
```

**[missing: key] erscheint:**
→ Message-Key existiert nicht in messages.php
→ Check Schreibweise (case-sensitive!)
→ Oder add Fallback: `M::get('my.key', 'Fallback Text')`

---

## 🆘 Häufige Probleme

### "Class not found"
→ Check Autoloader in bootstrap.php
→ Verify namespace declaration

### "CORS error" im Frontend
→ Backend .env: ALLOWED_ORIGINS anpassen
→ Check api/submit.php CORS Headers

### `Access denied for user 'anmeldung'` beim Start (Migration)
→ Das MySQL-Volume behält die Zugangsdaten vom **ersten** Start; spätere Änderungen von `DB_PASS`/`MYSQL_ROOT_PASSWORD` in der `.env` kommen nicht an
→ Alte Werte wiederherstellen, Passwort per `ALTER USER` nachziehen, oder nur das MySQL-Volume neu anlegen (Datenverlust, nicht `down -v`) — [DEPLOYMENT.md](docs/DEPLOYMENT.md#datenbank-zugriff-verweigert)

### Admin-Login abgelehnt, obwohl das Passwort stimmt
→ Meist ein beschädigter `ADMIN_PASSWORD_HASH`: In der Root-`.env` MUSS der Hash in einfachen Anführungszeichen stehen, sonst expandiert Docker Compose die `$…`-Teile (Länge im Container ≠ 60: `docker compose exec backend sh -c 'echo ${#ADMIN_PASSWORD_HASH}'`)
→ Danach `docker compose up -d backend` (nicht `restart`); Backend-Log: `⚠️ ADMIN_PASSWORD_HASH looks damaged`
→ Sonderzeichen im Passwort (`#` u. a.) sind nicht die Ursache

### Absenden: "Unauthorized" / HTTP 401 vom Backend
→ `TENANT_API_SECRET` im Frontend (bzw. WP-Einstellung) muss dem `tenants.api_secret` des Tenants entsprechen
→ Backend-Log: `tenant api_secret is a known placeholder/default` ⇒ echtes Secret setzen (`openssl rand -hex 32`) und `php migrate.php`
→ `TENANT_SLUG` muss ein aktiver Tenant sein

### Wartungsseite (503) / „The form is currently unavailable"
→ Backend nicht erreichbar oder Tenant abgelehnt; die Ursache steht im PHP-Log (`FormConfigLoader: form "bs" not loaded (unreachable|unauthorized|error): …`), im WordPress-Plugin sieht sie ein angemeldeter Administrator direkt auf der Seite
→ `BACKEND_API_URL` erreichbar? `curl "$BACKEND_API_URL/health.php"`; WordPress in Docker: `localhost` ist der Container selbst (`host.docker.internal` bzw. Dienstname)
→ Richtiger `TENANT_SLUG`? (`curl "$BACKEND_API_URL/form-config.php?form=bs&tenant=<slug>"`: 401 = Tenant unbekannt/inaktiv)

### 404 „Formular nicht gefunden" / `Unknown form "bs"`
→ Das Backend ist erreichbar, kennt das Formular aber nicht für den Tenant: in `form_configs` vorhanden? (`seed-forms.php`), Formular-Key richtig geschrieben?

### `Unknown column 'tenant_id'`
→ Migration nicht gelaufen: `php backend/migrate.php` (Docker: läuft bei jedem Start)

### "Permission denied" für uploads/cache
→ `chmod 755 uploads cache`
→ `chown www-data:www-data uploads cache`

### Anmeldung „erfolgreich", aber nichts im Backend / Formular „currently unavailable" trotz erreichbarem Backend
→ Formular mit `db: false` speichert nicht im Backend (nur E-Mail an `notify_email`). Ohne gültige `notify_email` würde die Absendung verworfen: das Frontend zeigt das Formular dann nicht an (503 / neutrale Meldung, Administratoren sehen die Ursache), `seed-forms.php` warnt
→ Beheben per SQL: `db` auf `true` setzen oder eine `notify_email` eintragen — [MIGRATION-3.0.md § 6](docs/MIGRATION-3.0.md#6-danach-formular-konfiguration-ändern)

### Excel-Export zeigt Formular-Spalte
→ Check dass Filter gesetzt ist: `?form=bs`
→ Metadata['filter'] muss nicht-leer sein

### Auto-Expunge läuft nicht
→ Check .env: AUTO_EXPUNGE_DAYS > 0
→ Prüfe cache/last_expunge.txt Permissions
→ Dashboard zeigt Status

### Datumsfelder falsch formatiert
→ ExportService formatiert automatisch YYYY-MM-DD → dd.mm.yyyy
→ Check dass Feld ISO-Format hat (RegEx: `^\d{4}-\d{2}-\d{2}`)

---

## 📞 Support & Kontakt

**Entwickler:** [Name]
**Version:** 3.1
**PHP Version:** 8.2+
**Database:** MySQL 8.0+ / MariaDB 10.5+

---

## 🔄 Änderungshistorie

### 3.1

**Nachträge aus der ersten Praxis**
- ✅ Formular-Editor in Tabs (Allgemein · Benachrichtigungs-E-Mail · PDF-Bestätigung · Kalender-Download · Info), Speichern/Abbrechen neben den Tabs; Tab mit Fehlern wird geöffnet und markiert
- ✅ Ein Logo pro Schule: Upload auf *Formulare* (`forms.php`) durch Tenant-Admins, gespeichert unter `uploads/tenant-<id>/branding/logo.png|jpg` (nur PNG/JPEG, max. 2 MB, per GD neu kodiert). Reihenfolge im PDF: `logo: false` (keins) → Pfad in der Config (Plattform-Admin) → Schul-Logo → `PDF_LOGO_<FORM>` → `PDF_LOGO_PATH`
- ✅ PDF: Abschnitte ohne Titel und Text werden nicht ausgegeben (keine leeren Kästen)
- ✅ PDF-Vorschau (`form_pdf_preview.php`): Beispielangaben aus der Survey (Feldname, 1.1.2000, 1, erste Auswahl), nutzt die ungespeicherten Formularwerte, Wasserzeichen „VORSCHAU"
- ✅ Docker-Image: GD mit JPEG-Unterstützung (`libjpeg-dev`, vorher konnten JPEG-Logos nicht verarbeitet werden)

**Formulare im Backend pflegen** (Plan: [PLAN-3.1.md](docs/plans/PLAN-3.1.md), Upgrade: [MIGRATION-3.1.md](docs/MIGRATION-3.1.md))
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

### 3.0

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

**Upgrade von 2.x:** siehe [MIGRATION-3.0.md](docs/MIGRATION-3.0.md).

---

**Ende der Dokumentation**

*Für Code-Details siehe die entsprechenden Klassen in `src/`*