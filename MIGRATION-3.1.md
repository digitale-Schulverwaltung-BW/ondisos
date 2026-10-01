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
   - Entfernte oder umbenannte Felder werden deutlich gemeldet: sie fehlen künftig in neuen Anmeldungen, im Excel-Export, in Prefill-Links und
     im PDF; bereits gespeicherte Anmeldungen behalten ihre Daten.
   - Liegt die Survey eines Formulars noch als Datei im Frontend, ist der Editor zunächst leer; zuerst importieren (Abschnitt 3) oder
     den Text aus dem Creator einfügen. Mit der ersten Veröffentlichung übernimmt das Backend das Formular.

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
