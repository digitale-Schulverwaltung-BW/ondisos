# Plan Ondisos 3.1 — Formulare im Backend pflegen

Status: **umgesetzt** (Stand 2026-10-01; Release-Abnahme siehe [../TODO.md](../TODO.md), Upgrade siehe [../MIGRATION-3.1.md](../../betreiber/MIGRATION-3.1.md)).
Grundlage: [../../CLAUDE.md](../../../CLAUDE.md), [../../backend/MULTI-TENANT.md](../MULTI-TENANT-DESIGN.md), [../SURVEYJS.md](../../redaktion/SURVEYJS.md), Code-Sichtung (siehe „Befunde").

## 0. Umsetzungsstand

| AP | Inhalt | Stand |
|---|---|---|
| 0 | Backend-Assets lokal (Bootstrap; DataTables war ungenutzt) | ✅ |
| 1 | Repositories, Services, Validatoren, Schema | ✅ |
| 2 | Auslieferung ans Frontend (ETag, Cache, Stale-if-error, sichere Einbettung) | ✅ |
| 3 | Import (`import-surveys.php`), `seed-forms.php --tenant` | ✅ |
| 4 | Admin-UI: Formularliste, Config-Editor | ✅ |
| 4b | Tenant-Onboarding: Formulare kopieren, signierter Status-Endpunkt (Nachtrag, Abschnitt 10) | ✅ |
| 5 | Survey-Editor (Prüfen, Diff, Entwurf, Veröffentlichen, Wiederherstellen; CodeMirror 6) | ✅ |
| 6 | Vorschau im Backend (sandboxed Frame) | ✅ |
| 7 | Härtung und Audit | ✅ |
| 8 | Doku, Roadmap, `docs/`-Ordner | ✅ |

**Abweichungen vom Entwurf** (bewusst, mit Grund):

- **Config-Formular speichert direkt**, nur die Survey hat einen Entwurf (Entscheidung 4/6 wurde geschärft): `form_drafts` enthält nur `survey_json`.
- **Vorschau im Backend statt über das Frontend**: eigene SurveyJS-Kopien (`assets/preview/`, Drift-Test), Frame mit `Content-Security-Policy: sandbox` und Nonce;
  dafür sendet das Docker-Apache `X-Frame-Options: SAMEORIGIN` statt `DENY` (Image neu bauen).
- **HTML-Allowlist zusätzlich im Rohtext geprüft** (nicht nur im DOM) und **bei der Auslieferung erneut angewendet**: Ein Test mit 54 Umgehungsformen zeigte,
  dass libxml und Browser kaputtes HTML verschieden parsen.
- **`TenantContext::runAs`** (neu) für Operationen, die einen Tenant lesen und einen anderen schreiben (Kopieren).
- Beim Einarbeiten von `main` wurde das Fehlervokabular des Frontends (Formular unbekannt ↔ Backend gestört) für das Bundle übernommen; ein Bug in `index.php`
  (`$tenantSlug` nicht definiert) wurde behoben.

## 1. Zielbild und Abgrenzung

**Ziel:** Ein Admin pflegt Formular-Konfiguration **und** Survey-Definition (JSON) im Backend. Das Frontend (Standalone und WordPress)
bekommt beides vom Backend und braucht bei Formularänderungen keinen Dateizugriff mehr („wartungsfrei"). Änderungen gehen erst nach
Prüfung, Vorschau und expliziter Veröffentlichung live und lassen sich zurückrollen. Alles ist tenant-isoliert.

**3.1 tut nicht:**
- **Kein SurveyJS-Creator im Backend.** Der Creator ist proprietär (Lizenz pro Entwickler; Ondisos wird an andere Schulen weitergegeben →
  jeder Betreiber bräuchte eine eigene Lizenz, außerdem Weitergabe-/Konkurrenzklauseln). Der Workflow bleibt: Creator auf surveyjs.io →
  JSON kopieren → im Backend einfügen. `SURVEYJS.md` dokumentiert das samt Lizenzhinweis. Die Form Library (Rendering, Vorschau) ist MIT.
- Kein Managed Multi-Frontend (bleibt 3.0.5, rückt hinter 3.1).
- Kein Logo-Upload für PDFs, keine Mehrsprachigkeit der Formulare, keine Änderung am Submit-/Upload-/PDF-Fluss.
- Keine Datenmigration bestehender `anmeldungen`.

## 2. Befunde aus der Code-Sichtung (relevant für den Entwurf)

1. **Survey-JSON wird roh in die Seite geschrieben:** `frontend/public/index.php` gibt `<?= $surveyJson ?>` und `<?= $themeJson ?>` direkt in ein
   `<script>` aus (auch in `console.log`), das WP-Plugin in `<script type="application/json">`. Solange die Dateien nur Admins mit Server-Zugriff
   ändern, ist das hinnehmbar; sobald Tenant-Admins sie im Browser pflegen, ist `</script>` im JSON ein Stored-XSS. → **Pflicht-Härtung** (AP 2/7).
2. **HTML in Surveys:** SurveyJS rendert `html`-Elemente ungefiltert. Die vorhandenen Surveys nutzen nur `a, b, br, p, h1, h3, h4`; keine
   `on*`-Attribute, kein `script`/`iframe`/`style`. Eine Allowlist beim Speichern ist also realistisch.
3. **Ausdrücke** (`visibleIf`, `requiredIf`, `setValueExpression`, `calculatedValues`, `placeholderExpression`) werden vom SurveyJS-eigenen
   Expression-Parser ausgewertet, **nicht** per `eval`. Das Risiko ist begrenzt auf registrierte Funktionen (zu prüfen: registrieren wir eigene
   Funktionen? In `frontend/public/js` keine `FunctionFactory`-Treffer) → Linter warnt bei unbekannten Funktionen.
4. **Dateiname aus der DB landet in einem Pfad** (`Frontend\Config\FormConfig::getFormPath/getThemePath`). Wird mit DB-gespeicherten Surveys
   überflüssig; für den Fallback auf Dateien bleibt eine strikte Namensprüfung nötig.
5. **`pdf.logo` ist ein Dateipfad** (auch absolut) → für Tenant-Admins nicht frei editierbar (siehe Entscheidung 2).
6. **`form-config.php` ist ungesigniert** und wird pro Request ohne Cache geholt; Backend weg ⇒ 503 für alle Formulare. Mit Survey-JSON in der
   Antwort wächst sie auf bis zu ~55 KB (bs.json) → ETag + Cache + Stale-if-error werden sinnvoll (löst nebenbei das Known Issue „503").
7. **Backend kennt keine Survey-Definition** und braucht sie auch nicht (`_fieldTypes` kommt mit der Anmeldung). Änderungen wirken nur auf neue
   Einträge. Der Editor muss das sichtbar machen (Feldnamen-Diff, siehe AP 5).
8. **Backend-UI lädt Bootstrap/DataTables von cdn.jsdelivr.net.** Neue Seiten erben das. Das Frontend liefert SurveyJS bereits lokal aus
   (`frontend/public/assets/survey.*`).

## 3. Entscheidungen (mit Empfehlung)

| # | Frage | Empfehlung | Begründung |
|---|---|---|---|
| 1 | Wo liegen Surveys? | **DB im Backend, Frontend zieht sie (Pull)**: `form-config.php` liefert Config + Survey + Theme in **einer** Antwort (`?with=survey`). | Bleibt im Pull-Modell, Backend im Intranet bleibt unerreichbar von außen, Frontend zustandslos. Push (b) bräuchte Schreibzugriff aufs öffentliche Frontend. |
| 2 | Wer darf bearbeiten? | **Platform-Admin: alles. Tenant-Admin: eigene Formulare**, aber **nicht** `pdf.logo` und nicht das Anlegen/Löschen von Formularen, die schon Anmeldungen haben. | Schulen sollen ihre Formulare selbst pflegen (Sinn von 3.1). Pfad-/Dateibezogene Felder bleiben beim Plattformbetreiber. |
| 3 | Validierung | Drei Stufen: **(a) Config-Schema** (erlaubte Schlüssel, Typen, E-Mail-Listen, PDF-Block) · **(b) Survey-Struktur** (gültiges JSON, Größe ≤ 512 KB, Seiten/Elemente vorhanden, eindeutige `name`s, Allowlist für HTML, keine `on*`/`javascript:`) · **(c) Querprüfung** (Feldnamen aus `prefill_fields`, `exclude_fields`, `intro_template`-Platzhaltern existieren in der Survey; `email`-Feld vorhanden, wenn Mail/PDF genutzt). Fehler blockieren, Warnungen nicht. | Die meisten realen Fehler sind Tippfehler in Feldnamen, nicht Syntax. |
| 4 | Versionierung | **Config-Formular speichert direkt** (Absenden → validieren → Revision des alten Stands → DB), kein Entwurf. **Survey: ein Entwurf pro Formular** (kein Multi-Draft); „Veröffentlichen" = validieren → Live-Stand als Revision sichern → Live ersetzen → Entwurf löschen. **Rollback = alte Revision veröffentlichen.** Optimistic Locking über Hash des Live-Stands. `config.version` ist ein Feld im Config-Formular (beim Veröffentlichen einer Survey wird ein neuer Wert vorgeschlagen, `YYYY-MM-vN`); `anmeldungen.formular_version` unverändert. | Config-Änderungen (Empfänger, PDF-Texte) sind klein und sofort wirksam gewollt; riskant ist nur die Survey. Kein neues Feld an `anmeldungen`. |
| 5 | Bestandsdaten | **Fallback auf Dateien bleibt in 3.1**: Liefert das Backend kein Survey für den Namen, nutzt das Frontend `frontend/surveys/<name>` wie bisher. **Import** per CLI und UI. Dateien werden in 3.2 abgeschafft. | Kein Big Bang; Single-Tenant-Installationen laufen unverändert weiter. |
| 6 | Editor | **Config: reines HTML-Formular, Schul-Admins sehen nie JSON.** Ein PHP-Feldschema (`FormConfigSchema`) beschreibt jedes Feld einmal und steuert **Formular, Validierung und Speichern** (eine Quelle der Wahrheit). **Survey: Code-Editor (CodeMirror 6, lokal) zum Einfügen/Hochladen mit Fehlermarkierung + Linter + Diff + Vorschau** — der Normalfall ist Copy+Paste aus dem Creator. Rohes Config-JSON bleibt nur dem Platform-Admin (Diagnose). | Kein Creator (Lizenz). Der Bruch im Workflow ist nur Copy+Paste. |
| 7 | Namensräume | Surveys/Themes sind **pro Tenant** gespeichert (`UNIQUE(tenant_id, kind, name)`); `form`/`theme` in der Config bleiben **Namen** (`bs.json`) → kein Schemawechsel. Namen strikt `^[a-z0-9][a-z0-9_-]{0,63}\.json$`. | Behebt Dateinamenkollisionen; Config bleibt abwärtskompatibel. |
| 8 | Szenario B | Kein Hindernis: alles ist bereits tenant-adressiert und per Pull abrufbar. HTML-Allowlist und sichere Einbettung (AP 2) sind genau das, was ein geteiltes Frontend sonst bräuchte. | — |
| 9 | Theme | Tenant-weites Theme als eigene Ressource (`kind='theme'`), ohne Entwurf, aber mit Revisionen und Vorschau. Fehlt es, Fallback auf `survey_theme.json` im Frontend. | Mehrere Formulare teilen ein Theme; Entwurf je Formular wäre unnötig. |
| 10 | Backend-Assets | **Bootstrap/DataTables in 3.1 lokal ausliefern** (eigenes AP, klein). | Der Editor braucht ohnehin lokale JS-Assets (Vorschau); schließt das Known Issue. |

## 4. Datenmodell und Migration

Neue Tabellen (in `database/schema.sql` **und** als idempotente Schritte in `backend/migrate.php`, Muster wie 3.0; zusätzlich
`database/migrations/add_form_resources.sql`):

```sql
CREATE TABLE form_resources (            -- veröffentlichte Surveys und Themes
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    kind ENUM('survey','theme') NOT NULL,
    name VARCHAR(100) NOT NULL,          -- z. B. 'bs.json'
    content LONGTEXT NOT NULL,           -- JSON, bereits validiert
    sha256 CHAR(64) NOT NULL,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by VARCHAR(100) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_kind_name (tenant_id, kind, name),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE form_drafts (               -- ein Entwurf je Formular
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    form_key VARCHAR(100) NOT NULL,
    survey_json LONGTEXT NOT NULL,       -- Entwurf der Survey (Config wird direkt gespeichert)
    based_on_sha CHAR(64) NULL,          -- Hash des Live-Stands beim Anlegen (Konflikterkennung)
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by VARCHAR(100) NULL,
    UNIQUE KEY uq_tenant_form (tenant_id, form_key),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE form_revisions (            -- Historie, nur anhängen
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    form_key VARCHAR(100) NOT NULL,
    kind ENUM('config','survey','theme') NOT NULL,
    name VARCHAR(100) NULL,              -- Ressourcenname bei survey/theme
    content LONGTEXT NOT NULL,
    sha256 CHAR(64) NOT NULL,
    note VARCHAR(255) NULL,
    created_by VARCHAR(100) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_form (tenant_id, form_key, created_at),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
```

- `form_configs` bleibt unverändert (bleibt die **Live-Config**; `FormConfig::get` und alle Leser laufen weiter).
- **Migration:** neue Schritte 11–13 in `migrate.php` (`CREATE TABLE IF NOT EXISTS`), keine Datenänderung → risikoarm, im Docker-Start sicher.
- **Rollback:** Tabellen sind rein additiv; Code von 3.0 ignoriert sie. Downgrade = Code zurück, Tabellen können stehen bleiben.
- **Aufbewahrung:** Revisionen pro Formular auf die letzten 50 begrenzen (Cleanup beim Veröffentlichen).
- Neue Audit-Ereignisse: `form_draft_saved`, `form_published`, `form_rolled_back`, `form_created`, `form_deleted`, `survey_imported` (mit `tenant_id`, Nutzer, Hash).

## 5. Arbeitspakete

Aufwand: S ≈ ½ Tag, M ≈ 1–2 Tage, L ≈ 3–5 Tage. Reihenfolge so, dass **nach AP 3 ein erster lauffähiger, produktiv nutzbarer Stand** existiert
(Surveys aus der DB, noch ohne UI) — Zwischenrelease 3.1.0-rc möglich.

### AP 0 — Backend-Assets lokal (S) — ✅
- Bootstrap, DataTables (und später die Editor-Bibliothek) nach `backend/public/assets/` (versioniert, mit Lizenzdateien); `inc/header.php`, `inc/footer.php`, `login.php` umstellen.
- **Test:** Demo ohne Internetzugang (Container ohne Egress oder Browser offline) → UI gestylt, DataTables funktioniert.
- **Doku:** `CLAUDE.md` Known Issues streichen; `TODO.md` abhaken.

### AP 1 — Repositories, Services, Validatoren (M) — ✅ — *Grundlage für alles*
- Neu: `FormConfigRepository` (CRUD auf `form_configs`, tenant-gefiltert), `FormResourceRepository`, `FormDraftRepository`, `FormRevisionRepository`.
- Neu: `FormConfigValidator` (Schema), `SurveyValidator`/`SurveyLinter` (Struktur, HTML-Allowlist, Querprüfung), `FormPublishService` (Entwurf → live, Revision, Konflikt, Rollback), `SurveyImportService`.
- Geschäftslogik **nur** in Services; Endpoints/Seiten bleiben dünn (Lehre aus 3.0: `public/*.php` sind nicht unit-testbar).
- Jede Abfrage `tenant_id` aus `TenantContext`; IDOR-Muster wie `AnmeldungRepository::findById` (fremder Schlüssel → 404 + `idor_attempt`).
- **Tests (Unit, mysqli-Mock wie `TenantRepositoryWriteTest`):** Tenant-Filter in jeder Methode; Validatoren mit Positiv-/Negativfällen (Pfad im Namen, `</script>`, `onerror=`, doppelte Namen, zu groß, kaputte E-Mail-Liste, unbekannte Config-Schlüssel); Publish: Revision wird vor dem Überschreiben geschrieben, Konflikt bei geändertem Live-Hash, Rollback; die **echten** `frontend/surveys/*.json` müssen den Validator ohne Fehler passieren (Regressionstest gegen Überstrenge).
- **Doku:** `UNITTESTS.md`.

### AP 2 — Auslieferung und Frontend-Anbindung (M) — ✅
- `form-config.php?with=survey` liefert `{config, survey, theme}` (Survey/Theme aus `form_resources` nach Namen der Config, sonst `null`), `ETag` (Hash über die Antwort), `If-None-Match` → 304. Antwort ohne `with` bleibt unverändert (3.0-Frontends laufen weiter).
- `BackendApiClient::fetchFormBundle()`; `FormConfigLoader` bekommt Cache: Datei-Cache in `frontend/cache/` (Standalone) bzw. Transient (WordPress), Revalidierung per ETag bei jedem Request, **Stale-if-error**: ist das Backend nicht erreichbar, wird die zuletzt gute Fassung ausgeliefert; nur ohne Cache bleibt es bei 503.
- `index.php` und `class-shortcode.php`: DB-Survey bevorzugen, sonst Datei (Fallback, Namensprüfung gegen Traversal).
- **Sichere Einbettung:** Survey/Theme **neu kodieren** (`json_encode(json_decode(...), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)`) statt Rohausgabe; das `console.log('Theme JSON…')` entfernen.
- **Tests:** Unit für `FormConfigLoader` (Cache-Treffer, 304, Stale-if-error, Fallback auf Datei), Test, dass `</script>` im Survey nicht aus dem Script-Block ausbricht. **Demo-E2E:** Survey nur in der DB ändern → Standalone **und** WordPress zeigen die Änderung; Backend stoppen → Formular erscheint weiter (Cache) und Submit meldet sauber den Fehler; ungesigniert abgerufene Antwort enthält nie einen Entwurf.
- **Doku:** `DEPLOYMENT.md` (Cache-Verzeichnis beschreibbar), `MIGRATION-3.1.md` (neu).

### AP 3 — Import und Bestandsmigration (S) — ✅
- `backend/import-surveys.php --tenant=<slug> --dir=<pfad> [--overwrite]` (nur CLI) und `seed-forms.php --tenant=<slug>` (heute nur Tenant 1). Importiert Surveys/Themes über `SurveyImportService` (gleiche Validierung wie die UI) in `form_resources`.
- **Tests:** Unit für den Service (idempotent, kein Überschreiben ohne Flag). **Demo-E2E:** `frontend/surveys` importieren → Dateien umbenennen/entfernen → Formulare laufen weiter.
- **Doku:** `MIGRATION-3.1.md` (Schritt „Surveys importieren"), `SURVEYJS.md`.

### AP 4 — Admin-UI: Formularliste und Config-Editor (L) — ✅
- Neue Seiten `forms.php` (Liste: Key, Version, Survey-Quelle DB/Datei, Entwurf vorhanden, Anzahl Anmeldungen) und `form_edit.php`: **HTML-Formular statt JSON** — Version, `db`, `notify_email` (Mehrfachadressen), `prefill_fields` (Auswahl aus den Feldnamen der Live-Survey), PDF-Block (Schalter, Titel, Intro/Footer, `exclude_fields` als Auswahl, Vor-/Nach-Abschnitte als wiederholbare Zeilen), E-Mail-Intro mit Platzhalter-Hilfe, iCal. „Speichern" validiert und schreibt direkt in `form_configs`, vorher Revision des alten Stands.
- **`FormConfigSchema`** (PHP, Service-Schicht) beschreibt Felder, Typen, Grenzen und Rollen **einmal**; daraus entstehen Formular, Validierung und das JSON für `config_json`. **Unbekannte Schlüssel im bestehenden JSON bleiben beim Speichern erhalten** (Merge statt Überschreiben), damit per SQL/Seed gesetzte Sonderfelder nicht verloren gehen. Neue Optionen später = ein Eintrag im Schema.
- Rohes JSON nur für Platform-Admins (Reiter „Diagnose", mit Validierung). Navigation in `inc/header.php`. Muster: `tenants.php` (CSRF, PRG, Flash, `action`-Feld).
- Rollen: Tenant-Admin nur eigener Tenant; `pdf.logo` nur Platform-Admin; Formular **anlegen** erlaubt, **löschen** nur, wenn keine Anmeldungen existieren (sonst gesperrt/Hinweis); **Umbenennen** eines `form_key` nicht möglich (Anmeldungen referenzieren ihn) → „Kopieren" statt „Umbenennen".
- Texte über `MessageService` (`backend/config/messages.php`).
- **Tests:** Rollen-/Isolationsmatrix als Unit-Tests der Services (Tenant-Admin A greift auf Formular von B zu → 404 + `idor_attempt`); **Demo-E2E:** Tenant-Admin ändert `notify_email` und PDF-Text, Platform-Admin sieht beide Tenants, `pdf.logo` für Tenant-Admin gesperrt, Löschen mit vorhandenen Anmeldungen verweigert.

### AP 5 — Survey-Editor: Einfügen, Linter, Diff, Entwurf, Veröffentlichen, Rollback (L) — ✅
- Seite `form_survey.php`: **CodeMirror 6** (lokal ausgeliefert, JSON-Modus, Fehlermarkierung an der Fehlerzeile, Bundle-Größe und Lizenz in AP 0 mitprüfen), Datei-Upload (`.json`, serverseitig gelesen, nicht ausgeführt), „Prüfen" (Linter-Bericht: Fehler/Warnungen mit Pfad), **Diff gegen Live** (Text-Diff + Feldnamen-Diff: hinzugefügt/entfernt/Typ geändert, mit Hinweis „Excel-Spalten, Prefill-Links und PDF-Reihenfolge betroffen"), Entwurf speichern, Veröffentlichen (Konflikthinweis, `version`-Vorschlag), Revisionsliste, Rollback.
- Link „Im SurveyJS-Creator bearbeiten" mit Anleitung (Copy+Paste, Hinweis auf kostenlosen Online-Creator und dass der Creator nicht Teil von Ondisos ist).
- **Tests:** Unit für Linter/Diff/Publish (AP 1); **Demo-E2E:** (1) Survey einfügen → Fehlerbericht bei kaputtem JSON, (2) gültig speichern → Entwurf, Live unverändert, (3) veröffentlichen → Frontend zeigt neu, (4) Rollback → altes Formular, (5) zwei Browser: Konflikt wird erkannt, (6) `<img onerror>` im html-Element wird abgelehnt.

### AP 6 — Vorschau im Backend (M) — ✅
- Seite `form_preview.php?form=…&draft=1`: rendert **Entwurf** (oder Live) mit lokal ausgeliefertem `survey.core`/`survey-js-ui` (aus dem Frontend-Build kopiert, MIT) und dem Theme; Senden ist deaktiviert (Banner „Vorschau — es wird nichts gespeichert"). Läuft nur in der Admin-Session; der Entwurf wird **nie** über den öffentlichen Endpoint ausgeliefert.
- Sicherstellen, dass die Vorschau dieselben SurveyJS-Erweiterungen kennt wie das Frontend (`placeholderExpression` aus `survey-handler-base.js`) — Ziel: gemeinsame, kleine JS-Datei statt Kopie. (Offenes Detail; sonst Vorschau ohne diese Erweiterung dokumentieren.)
- **Test (nur Demo-E2E, Browser):** Vorschau zeigt Bedingungslogik (`visibleIf`) und Platzhalter; „Absenden" erzeugt keine Anmeldung; Tenant-Admin kann Entwurf eines fremden Tenants nicht laden.

### AP 7 — Härtung und Audit (S) — ✅
- HTML-Allowlist (`a[href,target,rel], b, i, strong, em, br, p, ul, ol, li, h1–h6, span`; `href` nur `http(s):`/`mailto:`), `on*`/`style`/`script`/`iframe` ablehnen; Größenlimits; Rate Limit auf schreibende Aktionen (vorhandener `RateLimiter`); Audit-Ereignisse aus Abschnitt 4.
- Prüfen, dass kein Endpunkt Entwürfe oder `form_revisions` ausliefert.
- **Tests:** Unit (Allowlist: Positivfälle aus den echten Surveys, Negativfälle); Audit-Einträge enthalten `tenant_id`.

### AP 8 — Doku und Roadmap (S) — ✅
- `MIGRATION-3.1.md` (Upgrade 3.0→3.1: Migration, Cache-Verzeichnis, Import, Fallback), `SURVEYJS.md` (neuer Workflow + Lizenzhinweis zum Creator, Zusammenhang Config ↔ DB-Surveys), `DEPLOYMENT.md`, `CLAUDE.md` (Architektur, Schema, Dateiliste, Known Issues, Änderungshistorie 3.1), `MULTI-TENANT.md` und `backend/MULTI-TENANT.md` („Deferred items" → erledigt), `README.md` (Roadmap), `TODO.md` (Abnahme-Checkliste 3.1), `wordpress-plugin/INSTALL.md` (Cache/Transient).

**Abhängigkeiten:** 0 → (parallel) 1; 1 → 2, 3, 4; 4 → 5; 5 → 6 (Vorschau kann ab AP 2/3 mit Live-Daten entstehen); 7 begleitend, abschließend vor Release; 8 laufend.
**Gesamt:** grob 3–4 Wochen Entwicklung einer Person inkl. Demo-Abnahme.

## 6. Teststrategie

- **Unit (PHPUnit, mysqli-Mock):** alles in Services/Repositories/Validatoren; Isolation je Methode; Real-Daten-Regression (`frontend/surveys/*.json`).
- **Skripte ohne Unit-Tests** (`public/*.php`): bewusst dünn; je AP ein definiertes Demo-E2E (oben), danach in `TODO.md` als Abnahmeliste „v3.1.0" abhaken — wie bei 3.0.
- **Sicherheitsabnahme:** Tenant-A-Admin gegen Formulare/Entwürfe/Revisionen von Tenant B (alle neuen Routen), XSS-Fälle in Survey/Theme/Config-Texten, Entwurf nicht öffentlich, Backend aus → Cache/Fallback.
- **Demo-Umgebung** (`ondisos-demo`): vorhandener zweiter Tenant `schule-b-demo`; WordPress **und** Standalone prüfen.

## 7. Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| Linter zu streng → legitime Surveys lassen sich nicht speichern | Fehler nur bei Sicherheits-/Strukturproblemen, alles andere Warnung; Regressionstest mit den realen Surveys |
| Survey-Änderung bricht Excel/PDF/Prefill bei laufender Anmeldephase | Feldnamen-Diff mit Warnung, Entwurf+Vorschau, Rollback, `version`-Vorschlag |
| Frontend-Cache liefert veraltetes Formular | ETag-Revalidierung bei **jedem** Request; Stale nur bei Backend-Ausfall |
| Cache-Verzeichnis im Frontend nicht beschreibbar | Fallback ohne Cache + Hinweis im Health-/Log; Doku |
| Altes 3.0-Frontend gegen 3.1-Backend | `form-config.php` ohne `with` unverändert; Dateien bleiben Fallback |
| HTML-Allowlist verhindert gewünschte Inhalte (z. B. eingebettete Videos) | Allowlist erweiterbar, bewusst per Release |
| Scope-Creep Richtung „eigener Form-Builder" | Abgrenzung in Abschnitt 1; Creator-Lizenzfrage separat |

## 8. Entschiedene Fragen

1. **Tenant-Admins bearbeiten selbst:** ja (Einschränkungen aus Entscheidung 2).
2. **Zwischenrelease:** ja — 3.1.0 nach AP 0–3 (ohne UI, Import per CLI), UI folgt als 3.1.1.
3. **Survey-Editor:** CodeMirror 6, lokal. Config: HTML-Formular, kein JSON für Schul-Admins.

## 9. Roadmap-Umstellung in der Doku (erledigt)

- `README.md` (Abschnitt „Geplant"): **3.1** „Formulare im Backend pflegen (Config-UI, Survey-Editor mit Vorschau/Historie)" vor **3.0.5/3.2** „Managed Multi-Frontend". Empfehlung: Szenario B in **3.2** umbenennen (3.0.5 klingt nach Patch, wird aber ein Feature-Release).
- `MULTI-TENANT.md` (Tabelle Z. ~243): Zeilen tauschen, Status „in Planung".
- `backend/MULTI-TENANT.md` („Deferred items"): Abschnitte umsortieren, 3.1 mit Verweis auf `PLAN-3.1.md`.
- `CLAUDE.md`: „Known Issues"/„TODOs" (Punkte 5/6 tauschen), Hinweis „Formular-Konfiguration nur per SQL" bleibt bis zur Veröffentlichung von 3.1.

## 10. Nachtrag: Formulare für neue Tenants (AP 4b)

**Befund:** `tenants.php` legte nur die Zeile in `tenants` an. Ein neuer Tenant hatte keine Formulare (`form-config.php` → 404, „Formular nicht gefunden"),
und der Verbindungsstatus des WordPress-Plugins blieb grün. Eine Config von Hand auszufüllen *und* eine Survey einzufügen ist der mühsamste Einstieg.

**Umsetzung:**

- `FormCopyService` kopiert Konfiguration samt referenzierter Survey/Theme von einem Tenant zu einem anderen — ganz oder gar nicht (Transaktion), nur durch
  **Plattform-Admins** (im Service geprüft, abgelehnte Versuche werden auditiert). Quelle und Ziel sind ausdrückliche Parameter; `TenantContext::runAs` wechselt
  den Tenant und stellt ihn exakt wieder her. Aufrufe: `copy-forms.php`, Tenant anlegen („Formulare übernehmen von"), Tenant-Seite, `forms.php`.
- **Kopierregeln** (`FormConfigSchema::copyBehavior`): `notify_email` und `pdf.logo` werden **nie** kopiert (sonst gingen Anmeldungen an die falsche Schule);
  Texte mit Schulbezug (Titel, PDF-/Mail-/Kalendertexte) werden kopiert und zur Prüfung aufgelistet; Kontaktdaten in Surveys meldet `SurveyLinter::contactData`;
  Entwürfe, Verlauf, Anmeldungen, Uploads und Secret werden nie angefasst. Vorhandene Formulare des Ziels bleiben unverändert (außer `overwrite`; der alte Stand bleibt im Verlauf).
- **Sicher by default:** Eine Kopie mit `db: false` und ohne Empfänger wird vom Frontend nicht angezeigt (`FormConfig::discardsSubmissions`), bis ein Empfänger gesetzt ist; der Editor warnt dazu.
- **Signierter Endpunkt** `GET /api/forms.php` (HMAC über `forms:<slug>`): Formular-Schlüssel des eigenen Tenants. Das Plugin zeigt „Secret passt zum Tenant" und die Formularzahl
  (bei 0 ein Hinweis). Eine Signatur von `submit.php` oder für einen anderen Slug ist dort wertlos (getestet).
- Entschieden: keine Vorlagen-Bibliothek in 3.1 (Quelle ist ein vorhandener Tenant); Hinweisbanner „0 Formulare" auf der Tenant-Seite.
- Abgrenzung nach hinten: Das Plugin ohne Shell (ZIP, Verbindungscode, Update-Prüfung) ist als [PLAN-3.1.1.md](PLAN-3.1.1.md) entworfen.
