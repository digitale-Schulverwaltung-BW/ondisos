# Ondisos

Schulanmeldung, mandantenfähig: PHP-8.2-Backend (`backend/`, Namespace `App\*`, Intranet) und öffentliches Frontend (`frontend/`, `Frontend\*`, oder `wordpress-plugin/`).
Überblick, Repository-Karte, bekannte Einschränkungen: [docs/entwicklung/README.md](docs/entwicklung/README.md). Vor Änderungen an Datenfluss oder Schema: [architektur.md](docs/entwicklung/architektur.md).

## Befehle

```bash
cd backend && composer test -- --testsuite=Unit     # ohne Datenbank
cd backend && composer test:filter MyServiceTest    # eine Klasse
node --test "backend/tests/js/*.test.mjs"           # Survey-Editor (Node 22+, keine Pakete)
node backend/tools/survey-builder-harness/check.mjs # Browserprüfung des grafischen Editors (Playwright)
docker compose --profile dev up -d                  # Backend :9080, Frontend :8081, phpMyAdmin :8082
php backend/migrate.php                             # Schema aktualisieren (idempotent)
make plugin-zip                                     # WordPress-Plugin nach dist/
```

Tests im Container: [docker-entwicklung.md](docs/entwicklung/docker-entwicklung.md).

## Mandantentrennung (nicht verletzen)

- Jede Abfrage filtert nach `tenant_id`; einen All-Tenants-Modus gibt es nicht. Prepared Statements immer.
- `TenantContext` wird in `bootstrap.php` gesetzt; uninitialisiert wirft er eine Exception. Nie auf „alle Daten“ ausweichen.
- API: `?tenant=<slug>` adressiert, die HMAC-Signatur autorisiert. `config_json` ist ohne Signatur abrufbar: keine Geheimnisse hinein.
- Details und Tabelle der Kontexte: [Tenant-Kontext](docs/entwicklung/README.md#tenant-kontext).

## Regeln

- PHP: `declare(strict_types=1)` in jeder Datei, Typen für alle Parameter und Rückgaben, PSR-12.
- UI-Texte und Fehlermeldungen nie im Code, sondern `M::get('…')` ([messages.md](docs/entwicklung/messages.md)).
- Neue Logik bekommt Unit-Tests unter `Tests\Unit\*` (eine Sache pro Test).
- Grafischer Survey-Editor: Der JSON-Text ist die einzige Wahrheit; Änderungen treffen nur die bearbeitete Stelle. Der Editor trägt keinen SurveyJS-Namen, der SurveyJS Creator (proprietär) kommt nicht ins Projekt.
- Nach SurveyJS-Updates `backend/tools/sync-preview-assets.sh` ausführen (Vorschau hat eigene Kopien).
- Git: nur explizit stagen (`git add <Datei>`), nie `git add -A`: Fremddateien liegen untracked im Arbeitsverzeichnis.

## Dokumentation

- Nach Rollen in `docs/`, Anrede „Sie“. Jede neue Datei in `docs/README.md` eintragen; im Repository-Stamm nur `README.md` und `CLAUDE.md` (`DocsLinksTest` prüft das und alle relativen Links).
- Befehle vor dem Aufschreiben ausführen; Behauptungen aus Code, Oberfläche oder Test belegen.
- Doku-Überschriften umbenennen bricht den „?“-Button (`App\Utils\HelpLinks`); `HelpLinksTest` meldet es.
- Versionsnummer an vier Stellen: `wordpress-plugin/ondisos.php`, `wordpress-plugin/readme.txt`, README-Badge, `App\Config\Version` (`HelpLinksTest` prüft).
