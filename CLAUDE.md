# Ondisos: Entwicklerleitfaden

Digital souveräne Schulanmeldung: webbasiertes System mit SurveyJS-Frontend und PHP-Backend, ab 3.0 **mandantenfähig** (eine Backend-Instanz bedient mehrere Schulen).
Dies ist der Einstieg für Entwicklung (und für KI-Assistenten). Die Doku für Betreiber, Schul-IT, Redaktion und Sekretariat steht nach Rollen geordnet in [docs/README.md](docs/README.md),
die Projektübersicht in der [README](README.md).

**Stack:** PHP 8.2+ (Backend, Clean MVC mit Service Layer), MySQL/MariaDB, Vanilla JavaScript, SurveyJS, Bootstrap 5; Frontend als Standalone-PHP **oder** WordPress-Plugin.
**Betrieb:** Frontend öffentlich (Schulwebsite), Backend im Intranet (Verwaltungsoberfläche, API, Datenbank, Virenscan). Details: [Betriebsmodell](docs/betreiber/betriebsmodell.md).

## Kernkonzepte

- **Tenant** = eine Schule. Jede Anmeldung, Formular-Konfiguration und jedes Upload-Verzeichnis gehört zu genau einem Tenant. Tenant 1 (`slug = default`) gibt es immer; Einzelbetrieb ist `MULTI_TENANT_ENABLED=false`.
- **Signierte API:** Das Frontend authentifiziert sich pro Tenant mit HMAC-SHA256 (`X-Signature`) und nennt den Tenant per `?tenant=<slug>`. Der Slug adressiert, die Signatur autorisiert. Das Secret (`tenants.api_secret`) bleibt serverseitig.
- **Formular-Konfiguration in der Datenbank** (`form_configs`); das Frontend holt sie per `GET /api/form-config.php` (mit `?with=survey` samt veröffentlichter Survey und Theme, ETag, Cache, Stale-if-error).
- **Formulare im Backend pflegen (3.1):** Konfiguration als HTML-Formular, Survey-Editor (einfügen → prüfen → Entwurf → Vorschau → veröffentlichen, mit Verlauf). Surveys und Themes liegen in `form_resources`; `frontend/surveys/` ist Fallback und Importquelle.
  Der SurveyJS-Creator ist **nicht** Teil von Ondisos (proprietäre Lizenz), siehe [SURVEYJS.md](docs/redaktion/SURVEYJS.md).

## Orientierung im Repository

```
backend/                       Admin-Oberfläche und API (Namespace App\*)
  public/                      Web-Root: index, detail, trash, dashboard, forms*, form_*, tenants, excel_export, login, pdf/, api/ (submit, upload, form-config, forms, health)
  src/Config · Models · Repositories · Controllers · Services · Forms (reine Logik) · Cli · Validators · Utils
  inc/                         bootstrap, auth, csrf, header/footer, Editor-Helfer
  templates/pdf/               PDF-Vorlagen (mPDF)
  migrate.php · seed-forms.php · import-surveys.php · copy-forms.php     idempotente CLI-Skripte
  tests/Unit · tests/Integration
frontend/                      öffentliches Frontend (Namespace Frontend\*)
  public/                      index, save, ical, csrf_token, pdf/download (Proxy), js/, api/messages.json.php
  src/Config (FormConfigLoader, FormBundleCache, SurveySource) · Services (BackendApiClient, AnmeldungService, EmailService) · Utils
  surveys/ · config/           Survey-Dateien (Fallback) · Vorlagen (forms-config-dist.php = Seed-Quelle) und Messages
wordpress-plugin/              Plugin: Shortcode [ondisos form="…"], AJAX, PDF-Proxy, Einstellungen, ZIP-Build
database/                      schema.sql (Neuinstallation), migrations/
docs/                          Dokumentation nach Rollen (Index: docs/README.md)
```

Genauer (Dateiliste, Datenfluss, Schema, Status-Ablauf): [docs/entwicklung/architektur.md](docs/entwicklung/architektur.md).

### Tenant-Kontext

`TenantContext` (statisch, einmal pro Request) bestimmt den aktiven Tenant; `bootstrap.php` initialisiert ihn:

| Situation | Tenant |
|---|---|
| `MULTI_TENANT_ENABLED=false` | immer Tenant 1 |
| API-Request (`API_REQUEST`) | aus `?tenant=<slug>`; unbekannt/inaktiv ⇒ uninitialisiert ⇒ `401` |
| Browser, Tenant-Admin | aus der Session (`tenant_id`) |
| Browser, Platform-Admin | `switch_tenant`: ein Tenant oder „alle" (`isAllTenants()`) |
| Token-Endpoint (`pdf/download.php`) | Tenant der per Token autorisierten Anmeldung (`findTenantIdById()`) |

Ein nicht initialisierter Kontext wirft eine Exception (kein stilles Durchfallen auf „alle Daten"). Repositories filtern **jede** Abfrage nach `tenant_id` (außer im All-Tenants-Modus);
`AnmeldungRepository::findById()` protokolliert fremde IDs als `idor_attempt`.

## Befehle

```bash
# Tests (auf dem Host: PHP 8.2+, Composer; im Container siehe docs/entwicklung/docker-entwicklung.md)
cd backend && composer install
composer test -- --testsuite=Unit             # ohne Datenbank
composer test:filter MyServiceTest            # eine Klasse

# Entwicklungsstack (Backend :9080, Frontend :8081, phpMyAdmin :8082)
docker compose --profile dev up -d
docker compose exec -T backend php seed-forms.php - < frontend/config/forms-config.php

php backend/migrate.php                       # Schema auf den aktuellen Stand (idempotent; Docker: bei jedem Start)
make plugin-zip                               # WordPress-Plugin als ZIP nach dist/
```

Mehr: [Tests](docs/entwicklung/tests.md) · [Docker für die Entwicklung](docs/entwicklung/docker-entwicklung.md) · [CI/CD](docs/entwicklung/CI_CD.md).

## Konventionen

**PHP:** `declare(strict_types=1)` in jeder Datei; Typen für alle Parameter und Rückgaben; PSR-4 und PSR-12; camelCase für Methoden, PascalCase für Klassen.
**Namespaces:** Backend `App\*`, Frontend `Frontend\*`. **Dateien:** Klassen `PascalCase.php`, Views `kebab-case.php`.
**Datenbank:** snake_case; Prepared Statements **immer**; jede Abfrage tenant-gefiltert.
**Texte:** UI-Texte und Fehlermeldungen über den `MessageService` (`M::get('…')`), nicht im Code; lokale Anpassungen in `messages.local.php`. Siehe [messages.md](docs/entwicklung/messages.md).
**Tests:** Neue Logik bekommt Unit-Tests (`Tests\Unit\*`, `declare(strict_types=1)`, sprechende Namen, eine Sache pro Test); die Endpoint-Skripte und der Browser-Teil sind nicht abgedeckt, dafür gibt es die manuellen Tests.
**Dokumentation:** nach Rollen in `docs/` (betreiber, schul-it, redaktion, sekretariat, entwicklung), im Stil „Sie"; **jede neue Datei in `docs/README.md` eintragen** und relative Links prüfen
(`DocsLinksTest` schlägt sonst fehl). Im Repository-Stamm bleiben nur `README.md` und `CLAUDE.md`. Befehle in Anleitungen vorher ausführen; Behauptungen aus Code, Oberfläche oder Test ableiten.
**Release/Version:** die Nummer steht an vier Stellen (`ondisos.php`, `readme.txt`, README-Badge, `App\Config\Version`); `HelpLinksTest` prüft die Übereinstimmung. Der „?“-Button im Backend ordnet Seiten Doku-Abschnitten zu (`App\Utils\HelpLinks`); wer Überschriften der Doku umbenennt, bekommt vom Test Bescheid.
**Git:** nur explizit stagen (`git add <Datei>`), nie `git add -A`: Fremddateien liegen oft untracked im Arbeitsverzeichnis.

## Bekannte Einschränkungen

- Der E-Mail-Versand nutzt PHP `mail()` (SMTP ist geplant); die Benachrichtigung geht vom **Frontend-Server** aus und enthält die Angaben der Anmeldung.
- `GET /api/form-config.php` ist per Tenant-Slug ohne Signatur abrufbar (liefert z. B. `notify_email`): keine Geheimnisse in `config_json`.
- Signaturen enthalten keinen Zeitstempel (kein Replay-Schutz über die Transportschicht hinaus): HTTPS zwischen Frontend und Backend ist Pflicht.
- `seed-forms.php` überschreibt vorhandene Einträge nie; bestehende Formulare ändern die Schulen im Backend.
- Surveys liegen im Backend (`form_resources`) **oder** als Datei im Frontend (Fallback, Abschaffung ohne Termin); wer beides pflegt, sieht die Datenbank-Fassung. `import-surveys.php` überträgt die Dateien.
- Die Vorschau im Backend trägt eigene Kopien der SurveyJS-Dateien (`backend/public/assets/preview/`); nach SurveyJS-Updates `backend/tools/sync-preview-assets.sh` ausführen.
- Der Docker-Apache sendet `X-Frame-Options: SAMEORIGIN` (statt `DENY`), damit der Vorschau-Frame einbettbar ist.
- `database/schema.sql` legt Tenant 1 mit einem Platzhalter-Secret an; erst `migrate.php` (oder ein gesetztes Secret) macht ihn nutzbar.
- Validierungsmeldungen von SurveyJS erscheinen englisch (keine Locale eingebunden).
- Mehrere Docker-Stacks aus demselben Verzeichnis teilen sich `backend/.env`, die der Entrypoint bei jedem Start neu schreibt ([docker-entwicklung.md](docs/entwicklung/docker-entwicklung.md)).

**Geplant:** WordPress-Plugin ohne Shell (Verbindungscode, Update-Prüfung; [PLAN-3.1.1.md](docs/entwicklung/plans/PLAN-3.1.1.md)), SMTP-Versand, Datei-Fallback abschaffen, deutsche SurveyJS-Locale, weitere Tests (Endpoint-Skripte, Integration),
Monitoring, OpenAPI-Beschreibung. *Option, nicht geplant:* Managed Multi-Frontend (ein Frontend für mehrere Tenants; gestrichen, weil Standalone-Frontend und Plugin-ZIP reichen).

## Dokumentationskarte

| Thema | Dokument |
|---|---|
| Architektur, Dateiliste, Datenfluss, Schema, Status-Ablauf | [architektur.md](docs/entwicklung/architektur.md) |
| Entscheidungen zur Mandantenfähigkeit (englisch) | [MULTI-TENANT-DESIGN.md](docs/entwicklung/MULTI-TENANT-DESIGN.md) |
| Konfiguration (`.env`, `form_configs`) | [konfiguration.md](docs/entwicklung/konfiguration.md) |
| PDF-System | [pdf-system.md](docs/entwicklung/pdf-system.md) · [backend/PDF_SETUP.md](backend/PDF_SETUP.md) |
| Sicherheit | [sicherheit.md](docs/entwicklung/sicherheit.md) · [backend/UPLOAD_SECURITY.md](backend/UPLOAD_SECURITY.md) |
| Tests, Pipeline | [tests.md](docs/entwicklung/tests.md) · [backend/UNITTESTS.md](backend/UNITTESTS.md) · [CI_CD.md](docs/entwicklung/CI_CD.md) · [TODO.md](docs/entwicklung/TODO.md) |
| Messages (UI-Texte) | [messages.md](docs/entwicklung/messages.md) |
| Änderungshistorie, Pläne, Releases | [changelog.md](docs/entwicklung/changelog.md) · [plans/](docs/entwicklung/plans/PLAN-3.1.md) · [releases/](docs/entwicklung/releases/RELEASE-NOTES-3.1.0.md) |
| Installation, Betrieb, Notfall, Upgrade | [docs/README.md → Betreiber](docs/README.md#betreiber) |
| Formulare, Sekretariat, Schul-IT | [docs/README.md](docs/README.md) |
