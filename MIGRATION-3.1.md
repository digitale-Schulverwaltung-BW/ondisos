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
