# Migration 3.0 → 3.1

3.1 bringt die Formular-Pflege ins Backend (siehe [PLAN-3.1.md](../entwicklung/plans/PLAN-3.1.md)). Dieses Dokument wächst mit den Arbeitspaketen;
es beschreibt, was beim Update zu tun ist. **Kein Schritt ist zwingend**: ohne Änderung laufen alle Formulare wie unter 3.0 weiter.

## Backend

1. Code aktualisieren, danach die Migration ausführen (Docker: läuft bei jedem Start von selbst):

   ```bash
   php backend/migrate.php
   ```

   Sie legt die Tabellen `form_resources`, `form_drafts` und `form_revisions` an (idempotent, keine Daten werden geändert).
   Neuinstallationen bekommen sie über `database/schema.sql`.

2. `form-config.php` kennt den neuen Parameter `with=survey`. Ohne ihn antwortet der Endpoint wie in 3.0, ein
   3.0-Frontend funktioniert also gegen ein 3.1-Backend.

3. **Surveys und Themes in die Datenbank übernehmen** (optional, ab 3.1.0): Das Backend kann die Survey-Dateien ausliefern,
   das Frontend braucht sie dann nicht mehr lokal. Das Skript prüft jede Datei mit denselben Regeln wie der spätere Editor
   (HTML-Allowlist, doppelte Feldnamen, …); ungültige Dateien werden gemeldet und übersprungen, die übrigen trotzdem importiert.

   ```bash
   # manuelle Installation
   php backend/import-surveys.php --dry-run frontend/surveys    # nur prüfen
   php backend/import-surveys.php frontend/surveys              # importieren

   # Docker: das Backend-Image sieht frontend/ nicht, das Verzeichnis zuerst hineinkopieren
   docker compose cp frontend/surveys backend:/tmp/surveys
   docker compose exec backend php import-surveys.php /tmp/surveys
   ```

   Weitere Optionen: `--tenant=<slug>` (Standard `default`) und `--overwrite` (ersetzt abweichende Inhalte; die alte Fassung
   landet im Verlauf der Formulare, die diese Datei nutzen — ohne `--overwrite` werden abweichende Dateien übersprungen). Der Import ist
   wiederholbar (`unchanged`). Themes erkennt das Skript an der Formular-Konfiguration, am Namen `survey_theme.json` oder am Inhalt.
   Ein neuer Tenant: erst `seed-forms.php --tenant=<slug>`, dann `import-surveys.php --tenant=<slug>`.

   Sobald eine Survey in der Datenbank liegt, hat sie Vorrang vor der Datei; die Dateien bleiben als Fallback (Abschaffung ohne Termin).

4. `seed-forms.php` kennt jetzt `--tenant=<slug>` (bisher nur Tenant 1) und prüft jeden Eintrag: ungültige Einträge
   (fehlendes `form`/`theme`, kaputte E-Mail-Adresse, unzulässiger Formular-Schlüssel, …) werden gemeldet und nicht gespeichert,
   der Exit-Code ist dann 1. Vorhandene Formulare werden weiterhin nie überschrieben.

5. **Formular-Editor** (neu in der Oberfläche, Menüpunkt *Formulare*): Die Konfiguration eines Formulars lässt sich im Backend
   als HTML-Formular ändern; JSON sehen Schul-Admins nie. Jede Änderung wird geprüft und mit Verlauf gespeichert (Wiederherstellen
   möglich), gleichzeitige Änderungen werden erkannt.
   - **Rollen:** *Plattform-Admin* (und der Betreiber einer Einzel-Installation) darf alles; *Tenant-Admins* ändern die Formulare
     ihrer Schule, aber nicht die Datei-Namen (`form`, `theme`) und nicht das PDF-Logo (`pdf.logo`, ein Dateipfad auf dem Server).
     Ein Formular einer anderen Schule ist für sie schlicht „nicht vorhanden".
   - Formulare mit Anmeldungen lassen sich nicht löschen; der Schlüssel (`?form=…`) kann nach dem Anlegen nicht geändert werden.
   - Beim Speichern wird die Konfiguration einheitlich geschrieben: Benachrichtigungs-Adressen als Liste, leere Angaben und
     „kein Logo" (`false`) als fehlender Schlüssel. Das Verhalten ist dasselbe; nur die gespeicherte Form ändert sich.
   - Unbekannte Schlüssel, die jemand per SQL gesetzt hat, bleiben beim Speichern erhalten.
6. **Survey-Editor** (Seite *Survey bearbeiten* eines Formulars): Der Text aus dem SurveyJS-Creator wird eingefügt (oder als Datei geladen),
   mit *Prüfen* kontrolliert (Fehler mit Zeilen- und Spaltenangabe, Hinweise, geänderte Felder, Unterschied zur veröffentlichten Fassung),
   als *Entwurf* gespeichert und erst mit *Veröffentlichen* für Besucher sichtbar. Der bisherige Stand bleibt im Verlauf (Wiederherstellen).
   - Beim Veröffentlichen kann die Formular-Version erhöht werden (Vorschlag aus der bisherigen, z. B. `2026-01-v2` → `2026-01-v3`).
   - *Prüfen* zeigt eine Checkliste **„Pflichtfelder für Ondisos"**: Jede im Backend gespeicherte Anmeldung braucht ein Feld `Name` (oder `name`) und ein E-Mail-Feld (`email`, `email1`, `Email`, `E-mail`, `E-Mail`), jeweils als Pflichtfeld ohne Bedingung. Fehlt eines, ist es eine Warnung (Entwürfe dürfen unfertig sein); Formulare ohne Speichern im Backend sind ausgenommen.
   - Entfernte oder umbenannte Felder werden deutlich gemeldet: sie fehlen künftig in neuen Anmeldungen, im Excel-Export, in Prefill-Links und
     im PDF; bereits gespeicherte Anmeldungen behalten ihre Daten.
   - Der Editor ist ein Code-Editor (CodeMirror 6, MIT-lizenziert) mit Zeilennummern, JSON-Farben und Fehlermarkierung. Das fertige
     Bundle liegt unter `backend/public/assets/codemirror/` (mit Lizenztext); **Node.js ist für den Betrieb nicht nötig**, nur zum Neubauen
     (`backend/tools/survey-editor-bundle/README.md`). Fehlt das Bundle, arbeitet die Seite mit einer normalen Textarea.
   - Liegt die Survey eines Formulars noch als Datei im Frontend, ist der Editor zunächst leer; zuerst importieren (Abschnitt 3) oder
     den Text aus dem Creator einfügen. Mit der ersten Veröffentlichung übernimmt das Backend das Formular.

7. **Neue Tenants / Formulare kopieren:** `php copy-forms.php --from=<slug> --to=<slug> [--forms=bs,vabo] [--overwrite] [--dry-run]`
   (Docker: `docker compose exec backend php copy-forms.php …`) oder in der Oberfläche beim Anlegen eines Tenants bzw. auf der Tenant-Seite
   und unter *Formulare*. Empfänger-Adressen und PDF-Logo werden nicht kopiert; Details und Checkliste: [MULTI-TENANT.md](MULTI-TENANT.md).
8. **Neuer signierter Endpunkt** `GET /api/forms.php?tenant=<slug>` (Header `X-Signature` = HMAC-SHA256 über `forms:<slug>` mit dem
   Tenant-Secret): liefert die Formular-Schlüssel des eigenen Tenants. Das WordPress-Plugin nutzt ihn für den Verbindungsstatus
   (Secret passt? wie viele Formulare?). Anders als `form-config.php` ist er nur mit dem Secret abrufbar. Ein älteres Backend ohne diesen
   Endpunkt wird vom Plugin erkannt und nur als „nicht prüfbar" gemeldet.
9. **Hinweis im Editor:** Ohne Empfänger und ohne Speichern im Backend meldet die Konfigurations-Seite nach dem Speichern, dass das Formular
   vom Frontend nicht angezeigt wird.

10. **Vorschau** (Knöpfe *Vorschau* und *Entwurf speichern & Vorschau* im Survey-Editor): zeigt den Entwurf oder die veröffentlichte Survey so, wie
    Besucher sie sehen (SurveyJS-Laufzeit, Theme, dynamische Platzhalter), mit Umschalter für Handy-/Tablet-/Desktop-Breite. „Abschicken" sendet
    nichts, es wird nichts gespeichert.
    - Sicherheit: Die Vorschau läuft in einem Frame mit `Content-Security-Policy: sandbox allow-scripts` (eigener, leerer Origin — kein Zugriff auf
      Sitzung und Admin-Seiten), Skripte nur per Nonce, und es wird nur gerendert, was die Validatoren bestehen (eine früher per SQL gespeicherte
      Survey mit `<img onerror>` wird mit Begründung abgelehnt).
    - **Docker:** Der Frame muss vom Admin-Seiten-Origin eingebettet werden dürfen. Die mitgelieferte Apache-Konfiguration sendet deshalb
      `X-Frame-Options: SAMEORIGIN` statt `DENY` (Einbetten durch fremde Seiten bleibt verboten). **Backend-Image neu bauen**
      (`docker compose build backend`), sonst bleibt die Vorschau leer. Eigene Apache/Nginx-Konfiguration: `SAMEORIGIN` setzen, siehe `backend/public/.htaccess.example`.
    - Die Vorschau bringt eigene Kopien der SurveyJS-Dateien mit (`backend/public/assets/preview/`, ca. 2 MB); nach einem SurveyJS-Update im Frontend
      `backend/tools/sync-preview-assets.sh` ausführen (ein Test schlägt sonst an).
    - Das Theme wird angezeigt, wenn es im Backend liegt (Import); liegt es nur als Datei im Frontend, fehlt es in der Vorschau (mit Hinweis).

11. **Sicherheit des Formular-Editors** (Überblick, was 3.1 absichert):
    - **Inhalte:** HTML in Surveys nur aus einer Allowlist (Tags, Attribute, URL-Schemata); Kommentare und Sonderkonstrukte sind verboten, jeder
      Tag-Name im Rohtext wird geprüft (nicht nur der geparste DOM). `javascript:`-URLs, `on*`-Attribute, `style`, `script`, `iframe`, `svg`, … werden abgelehnt.
      Größenlimits: Survey 512 KB, Theme 256 KB, Konfiguration 64 KB, höchstens 100 Formulare je Tenant.
    - **Auslieferung:** Das Backend liefert dem Frontend nur Surveys/Themes, die die Validatoren *heute* bestehen (auch Altbestand und per SQL
      Gespeichertes). Sonst bleibt `survey_json` leer, das Frontend nimmt seine Datei oder zeigt „nicht gefunden", und es gibt einen Audit-Eintrag
      `form_delivery_rejected`. Die Einbettung ins Frontend kodiert JSON zusätzlich (`\u003C`).
    - **Zugriff:** CSRF-Token bei jeder Änderung, Rollen (Tenant-Admins ändern keine Dateinamen/Logo, kopieren nicht), Mandanten-Isolierung in jeder
      Abfrage; öffentliche Endpunkte kennen weder Entwürfe noch Verlauf (ein Test prüft das). Die Vorschau läuft in einem Sandbox-Frame.
    - **Missbrauchsschutz:** Schreibende Aktionen sind je Benutzer und Adresse begrenzt (Standard 60 pro Minute; `EDITOR_RATE_LIMIT_MAX`,
      `EDITOR_RATE_LIMIT_WINDOW`, `RATE_LIMIT_ENABLED`); danach HTTP 429 mit `Retry-After`.
    - **Audit** (`logs/audit.log`, ohne Inhalte): `form_created`, `form_config_saved`, `form_draft_saved`, `form_draft_discarded`, `form_published`,
      `form_rolled_back`, `form_deleted`, `form_copied`, `form_copy_denied`, `survey_imported`, plus die Sicherheitssignale `form_survey_rejected`
      (jemand wollte unzulässige Inhalte speichern: Zahl und Pfade der Fehler), `survey_import_rejected` und `form_delivery_rejected`.
    - **Bekannte Grenzen:** `choicesByUrl` in einer Survey lässt den Browser der Besucher einen fremden Server kontaktieren (Warnung im Editor, kein Verbot);
      Revisionen belegen Speicher (höchstens 50 je Formular und Art, je bis 512 KB); die Validatoren kennen keine Inhalte, die SurveyJS künftig neu
      als HTML rendert — bei einem SurveyJS-Update die Allowlist prüfen.

## Frontend / WordPress

1. Code aktualisieren. Das Frontend fragt jetzt Config, Survey und Theme in **einer** Anfrage ab (`with=survey`).
   Enthält die Backend-Datenbank keine Survey für ein Formular, wird wie bisher `frontend/surveys/<form>.json` gelesen.
2. **Cache-Verzeichnis:** der Webserver-Benutzer braucht Schreibrecht auf `frontend/cache/` (Standalone) bzw. `wp-content/uploads`
   (WordPress). Siehe [DEPLOYMENT.md](DEPLOYMENT.md), „Formular-Cache". Optional: `FORM_CACHE_DIR` in der `.env`.
3. Ein 3.1-Frontend gegen ein **3.0-Backend** läuft ebenfalls (die Survey kommt dann aus den Dateien); der Cache hält dann
   nur die Config.

## Verhalten, das sich ändert

- **Ausfallschutz:** Ist das Backend kurz nicht erreichbar, liefert das Frontend die zuletzt gelieferte Fassung eines Formulars
  aus dem Cache (höchstens 7 Tage alt), statt der Wartungsseite. Das Absenden braucht weiterhin das Backend.
  Ein gelöschtes Formular oder ein deaktivierter Tenant wird **nicht** aus dem Cache ausgeliefert.
- **Sichere Einbettung:** Survey und Theme werden vor dem Einbetten neu kodiert (`<` → `<` usw.). Inhaltlich ändert das
  nichts; die Debug-Ausgabe `console.log('Theme JSON…')` im Standalone-Frontend entfällt.
