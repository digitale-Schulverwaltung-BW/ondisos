# CI/CD: GitLab-Pipeline

Die Pipeline steht in [`.gitlab-ci.yml`](../../.gitlab-ci.yml) und läuft auf dem GitLab der Entwicklung (`gitlab.hhs.karlsruhe.de`). Dieses Dokument beschreibt den **aktiven** Stand;
Änderungen gehören zuerst in die Datei, danach hierher. Das Deployment auf Server ist **nicht automatisiert** (siehe [unten](#deployment-bewusst-manuell)).

## Stufen und Jobs

```
install → test → coverage → security → build → release → deploy-staging → deploy-production
 aktiv     aktiv    aktiv      (aus)      (aus)    aktiv        (aus)            (aus)
```

| Job | Stufe | Wann | Was |
|---|---|---|---|
| `install_dependencies` | install | immer | `composer install` im `backend/`; `vendor/` wird als Artefakt und Cache weitergegeben |
| `test_unit` | test | immer | Unit-Tests mit PCOV-Coverage (`--testsuite=Unit`), JUnit- und Cobertura-Bericht; der Prozentwert erscheint im Badge der README |
| `test_integration` | test | immer, **darf fehlschlagen** | Integration-Tests gegen einen MySQL-8.0-Dienst; importiert `database/schema.sql` und richtet `.env.test` auf den Dienst aus |
| `lint_php` | test | immer | `php -l` für `backend/src`, `backend/public`, `frontend/src`, `frontend/public` |
| `coverage` | coverage | nur `main`, `master`, `develop` | Coverage-HTML mit Xdebug (`backend/coverage/`, 30 Tage als Artefakt) |
| `release:plugin_zip` | release | nur Tags `vX.Y.Z` | baut die WordPress-Plugin-ZIP samt SHA-256-Prüfsumme, legt beides in der Generic Package Registry ab und erstellt ein GitLab-Release |

Alle Jobs laufen im Image `php:8.2-cli`; Erweiterungen (`pdo_mysql`, `mysqli`, `gd`, `zip`) und Composer werden zur Laufzeit installiert. Die Testumgebung
(`APP_ENV=testing`, `DB_NAME=anmeldung_test`, Rate-Limit aus, Auth aus) setzt die Datei selbst, Secrets aus GitLab werden dafür **nicht** gebraucht.

## Lokal nachstellen

```bash
cd backend && composer install
composer test -- --testsuite=Unit          # wie test_unit
composer test:coverage -- --testsuite=Unit # wie coverage (Xdebug nötig)
find backend/src -name '*.php' -print0 | xargs -0 -n1 php -l    # wie lint_php
```

Die Integration-Tests brauchen MySQL mit `database/schema.sql` (siehe [UNITTESTS.md](../../backend/UNITTESTS.md)). Unit-Tests prüfen auch die Dokumentation:
ein Test stellt sicher, dass jede Datei in `docs/` im [Index](../README.md) steht und die Links in `docs/` auflösen.

## Release: WordPress-Plugin

1. Version in `wordpress-plugin/ondisos.php` hochsetzen; **der Tag muss dazu passen** (`v3.1.1` ↔ Plugin-Version 3.1.1).
2. Tag setzen und pushen: `git tag v3.1.1 && git push origin v3.1.1`.
3. Der Job `release:plugin_zip` führt `wordpress-plugin/publish-release.sh` aus. Ergebnis: `dist/ondisos-<version>.zip` und `.sha256` als Artefakt, in der Package Registry und am Release
   (ohne Anmeldung abrufbar, wenn das Projekt öffentlich ist).

Lokal erzeugen: `make plugin-zip` bzw. `wordpress-plugin/build-zip.sh` (nur versionierte Dateien kommen hinein). Hintergrund: [plans/PLAN-3.1.1.md](plans/PLAN-3.1.1.md).

## Deployment: bewusst manuell

Die Jobs für Build (`build:backend`, `build:docker`), Deployment auf Staging und Produktion sowie das Zurückrollen stehen in `.gitlab-ci.yml` nur als **auskommentierte Vorlage**.
Der Betrieb läuft beim jeweiligen Betreiber (Schulträger, Medienzentrum); die Aktualisierung beschreibt [betrieb.md](../betreiber/betrieb.md#updates), Notfälle [notfall.md](../betreiber/notfall.md).

Wer die Vorlage aktivieren will, muss sie vorher anpassen. Sie stammt aus einer früheren Fassung und passt nicht mehr zum aktuellen Stack:

- Der Health-Check ruft `/index.php` auf Port 8080 auf; richtig ist `/api/health.php` (die Oberfläche leitet auf den Login um), Standardport 9080.
- Sie verwendet `docker-compose` (v1) statt `docker compose` und überträgt den Code per `rsync` in ein Unterverzeichnis `backend/`; der Stack liegt aber im Projektstamm.
- Vor dem Deployment muss gesichert werden ([Sicherung](../betreiber/betrieb.md#sicherung-und-wiederherstellung)); das Rollback braucht bei Schema-Änderungen auch den Datenbank-Stand von vorher.
- Zugangsdaten (SSH-Schlüssel, Server, Pfade) gehören in **geschützte CI/CD-Variablen** von GitLab, nie ins Repository.
- Die Security-Jobs (`secret_detection`, `sast`, Code-Style, Security-Check) sind ebenfalls auskommentiert und lassen sich über die GitLab-Templates einschalten.
