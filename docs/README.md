# Dokumentation

Die Projekt-Übersicht steht in [../README.md](../README.md), Architektur, Datenfluss und Konventionen in [../CLAUDE.md](../CLAUDE.md).

## Betrieb

| Dokument | Inhalt |
|---|---|
| [DEPLOYMENT.md](DEPLOYMENT.md) | Installation (Docker, manuell), Updates, HTTPS, Production-Checkliste |
| [DOCKER.md](DOCKER.md) | Docker-Umgebung im Detail |
| [SERVER_SETUP.md](SERVER_SETUP.md) | Server vorbereiten |
| [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) | Backup und Wiederherstellung |
| [CI_CD.md](CI_CD.md) | GitLab-Pipeline |
| [MULTI-TENANT.md](MULTI-TENANT.md) | Mehrere Schulen betreiben, neuen Tenant einrichten |
| [ASV.md](ASV.md) | Datenschutz / Auftragsverarbeitung |

## Upgrade

| Dokument | Inhalt |
|---|---|
| [MIGRATION-3.1.md](MIGRATION-3.1.md) | 3.0 → 3.1: Formular-Editor, Cache, CLI, Sicherheit |
| [MIGRATION-3.0.md](MIGRATION-3.0.md) | 2.x → 3.0: Mehrmandantenfähigkeit |

## Release

| Dokument | Inhalt |
|---|---|
| [RELEASE-NOTES-3.1.0.md](RELEASE-NOTES-3.1.0.md) | Was 3.1.0 bringt (für Anwender und Betreiber) |
| [RELEASE-3.1.0.md](RELEASE-3.1.0.md) | Release-Check: Prüfstand, Merge-Reihenfolge, Tag, Rückfall |

## Formulare

| Dokument | Inhalt |
|---|---|
| [SURVEYJS.md](SURVEYJS.md) | Formulare mit SurveyJS erstellen, Zusammenhang Konfiguration ↔ Survey, Lizenzhinweis zum Creator |

## Planung und Qualität

| Dokument | Inhalt |
|---|---|
| [plans/PLAN-3.1.md](plans/PLAN-3.1.md) | Plan und Umsetzungsstand von 3.1 |
| [plans/PLAN-3.1.1.md](plans/PLAN-3.1.1.md) | WordPress-Plugin ohne Shell (ZIP umgesetzt; Verbindungscode, Update-Prüfung offen) |
| [TODO.md](TODO.md) | Testabdeckung, Abnahmelisten (3.0.0, 3.1.0), offene Punkte |

## Bei den Komponenten

- Backend: [../backend/MULTI-TENANT.md](../backend/MULTI-TENANT.md) (Architekturentscheidungen), [../backend/PDF_SETUP.md](../backend/PDF_SETUP.md), [../backend/UPLOAD_SECURITY.md](../backend/UPLOAD_SECURITY.md), [../backend/UNITTESTS.md](../backend/UNITTESTS.md), [../backend/tools/survey-editor-bundle/README.md](../backend/tools/survey-editor-bundle/README.md) (Code-Editor neu bauen)
- WordPress-Plugin: [../wordpress-plugin/INSTALL.md](../wordpress-plugin/INSTALL.md), [../wordpress-plugin/README.md](../wordpress-plugin/README.md) — liegen beim Plugin, weil sie mit ihm ausgeliefert werden
