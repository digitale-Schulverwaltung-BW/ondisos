# Release-Check 3.1.0

Stand der Vorbereitung: 2026-10-01. Release Notes: [RELEASE-NOTES-3.1.0.md](RELEASE-NOTES-3.1.0.md) · Upgrade: [MIGRATION-3.1.md](MIGRATION-3.1.md) · Plan: [plans/PLAN-3.1.md](plans/PLAN-3.1.md).

## 1. Was geprüft ist (Kopf des Stapels `feature/v31-ap8-docs`)

| Prüfung | Ergebnis |
|---|---|
| Unit-Tests (`composer test -- --testsuite=Unit`) | 912 grün |
| Integration-Tests (MySQL 8, `database/schema.sql`) | 178 grün |
| `php -l` über alle gegenüber `main` geänderten PHP-Dateien | 107 Dateien, keine Fehler |
| Neuinstallation: `database/schema.sql` auf leerer Datenbank | alle 7 Tabellen, inkl. `form_resources`, `form_drafts`, `form_revisions` |
| Upgrade: Schema von `main` + `migrate.php` (zweimal) | 3 neue Tabellen, zweiter Lauf ohne Änderung (idempotent) |
| Schema-Drift (`schema.sql` ↔ `migrations/add_form_editor_tables.sql` ↔ `migrate.php`) | Test grün |
| Dokumentationslinks (`DocsLinksTest`) | keine kaputten Links, Root nur `README.md` + `CLAUDE.md` |
| Demo-Ende-zu-Ende (Standalone, WordPress, Backend, zwei Tenants) | siehe Abnahmeliste in [TODO.md](TODO.md#v310--stand-der-abnahme) |

**Noch manuell zu prüfen** (steht auch in `TODO.md`):
- [ ] WordPress-Plugin: Statusseite *Einstellungen → Ondisos* mit „Secret passt" / „0 Formulare".
- [ ] Vorschau: Breitenumschalter (Handy/Tablet) in einem echten Browser.
- [ ] Docker: Backend-Image neu bauen und die Vorschau gegen das frische Image prüfen.
- [ ] Datenschutz/Betrieb (liegt bei dir): Wer pflegt `docs/ASV.md` — ändert 3.1 etwas an der Auftragsverarbeitung? (Neu gespeichert werden Verlauf und Entwürfe von Formularen; keine personenbezogenen Daten der Besucher.)

## 2. Merge-Reihenfolge

Der Stapel ist linear: jeder Branch enthält den vorherigen; `feature/v31-ap0-local-assets` liegt daneben. `origin/main` ist bis `4ca8552` bereits eingearbeitet.

**Variante A — wenige Merge Requests (empfohlen):**
1. `feature/v31-ap0-local-assets` → `main`
2. `feature/v31-ap8-docs` → `main` (enthält AP 1–7, AP 4b und die Doku)

**Variante B — in Scheiben reviewen (10 MRs, jeweils gegen `main`, in dieser Reihenfolge):**
`ap0-local-assets` → `ap1-form-services` → `ap2-delivery` → `ap3-import` → `ap4-admin-ui` → `ap5-survey-editor` → `ap4b-tenant-onboarding` → `ap6-preview` → `ap7-hardening` → `ap8-docs`
(alle mit dem Präfix `feature/v31-`).

Erwartete Konflikte mit AP 0 (`feature/v31-ap0-local-assets`), trivial: in `CLAUDE.md` die Known-Issue-Zeile zur CDN-Abhängigkeit (AP 0 streicht sie, AP 8 lässt sie stehen — **streichen**) und in `docs/TODO.md` der Punkt „Backend-Oberfläche: Bootstrap/DataTables lokal" (AP 0 hakt ihn ab — **abgehakt lassen**; durch den Umzug nach `docs/` erkennt Git die Datei als umbenannt).
Der Branch `docs/plan-3.1` ist überholt (sein Plan steckt in `docs/plans/PLAN-3.1.md`): Merge Request schließen.

Hinweis: `docs/plans/PLAN-3.1.1.md` ist ein Entwurf aus einer anderen Session, der versehentlich in AP 6 mitgekommen ist (siehe Commit-Meldung von AP 8); er ist inhaltlich unverändert bis auf die ergänzten Befunde.

## 3. Nach dem Merge

1. `main` auschecken, Tests laufen lassen (`composer test`).
2. Tag setzen: `git tag -a v3.1.0 -m "Ondisos 3.1.0 — Formulare im Backend pflegen"` und `git push origin v3.1.0`.
3. Betrieb nach [MIGRATION-3.1.md](MIGRATION-3.1.md): Backend aktualisieren (`migrate.php`, Docker-Image neu bauen), Frontend-Cache-Verzeichnis, WordPress-Plugin aktualisieren.
4. Rauchtest: Formular im Frontend öffnen und absenden; im Backend *Formulare* öffnen, ein Formular bearbeiten, Survey-Vorschau ansehen; Plugin-Statusseite prüfen.
5. Release Notes ([RELEASE-NOTES-3.1.0.md](RELEASE-NOTES-3.1.0.md)) in die GitLab-Release-Beschreibung übernehmen.

Plugin-Version: entschieden und umgesetzt — das Plugin springt von `2.1.1` auf **`3.1.0`** (`wordpress-plugin/ondisos.php` Header und `ONDISOS_PLUGIN_VERSION`, `readme.txt` mit Changelog und Upgrade-Hinweis); ab jetzt tragen Backend, Frontend und Plugin dieselbe Versionsnummer. Die Versionsnummer ist Teil der Asset-URLs, Browser laden die JavaScript-Dateien also neu.

## 4. Rückfall

- **Code:** Vorheriges Release (`v3.0.0`) auschecken. Die neuen Tabellen stören 3.0 nicht (rein additiv); sie können stehen bleiben.
- **Formulare:** Gelieferte Surveys kommen nur noch aus dem Backend, wenn dort eine liegt. Ein 3.0-Frontend ignoriert `survey_json` und nimmt seine Dateien.
- **Einzelnes Formular:** Im Editor einen früheren Stand wiederherstellen (Verlauf), nicht per SQL.
- **Backup:** Vor dem Upgrade einen Datenbank-Dump ziehen ([DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)); er enthält die neuen Tabellen mit.
