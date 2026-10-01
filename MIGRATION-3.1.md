# Migration 3.0 → 3.1

3.1 bringt die Formular-Pflege ins Backend (siehe [PLAN-3.1.md](PLAN-3.1.md)). Dieses Dokument wächst mit den Arbeitspaketen;
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

   Sobald eine Survey in der Datenbank liegt, hat sie Vorrang vor der Datei; die Dateien bleiben als Fallback (bis 3.2).

4. `seed-forms.php` kennt jetzt `--tenant=<slug>` (bisher nur Tenant 1) und prüft jeden Eintrag: ungültige Einträge
   (fehlendes `form`/`theme`, kaputte E-Mail-Adresse, unzulässiger Formular-Schlüssel, …) werden gemeldet und nicht gespeichert,
   der Exit-Code ist dann 1. Vorhandene Formulare werden weiterhin nie überschrieben.

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
