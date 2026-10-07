# Dokumentation

Ondisos wird von verschiedenen Menschen an verschiedenen Orten betrieben und benutzt. Die Dokumentation ist deshalb nach Rollen geordnet:
Suchen Sie sich die Rolle, die zu Ihnen passt.

| Ich bin … | Abschnitt |
|---|---|
| im Sekretariat und bearbeite eingehende Anmeldungen | [Sekretariat](#sekretariat) |
| zuständig für die Formulare meiner Schule | [Redaktion](#redaktion) |
| Administrator*in der Schulwebsite oder des Schulservers | [Schul-IT](#schul-it) |
| Betreiber des Backends (Medienzentrum, Schulträger) | [Betreiber](#betreiber) |
| Entwickler*in | [Entwicklung](#entwicklung) |

Eine Übersicht über das Projekt gibt die [README](../README.md).

---

## Sekretariat

Anmeldungen im Backend ansehen, ausdrucken, als Excel exportieren und in ASV-BW importieren.

| Dokument | Inhalt |
|---|---|
| [Handreichung für das Sekretariat](sekretariat/README.md) | Anmelden, Neues finden, ansehen, ausdrucken (PDF), Excel-Export, Status, Archivieren und Papierkorb |
| [ASV.md](sekretariat/ASV.md) | Excel-Export in ASV-BW importieren |

## Redaktion

Formulare einer Schule anlegen, ändern und veröffentlichen; Logo und PDF-Bestätigung pflegen.

| Dokument | Inhalt |
|---|---|
| [PDF-Bestätigung gestalten](redaktion/pdf-bestaetigung.md) | Schul-Logo, Farbe, Texte, Felder und angehängtes PDF; Hinweise zum Fallback-Logo für Betreiber |
| [SURVEYJS.md](redaktion/SURVEYJS.md) | Formulare mit SurveyJS entwerfen, im Backend einfügen, prüfen, veröffentlichen; Zusammenhang Konfiguration ↔ Survey; Lizenzhinweis zum Creator |

## Schul-IT

Das Formular auf der Schulwebsite einbinden und mit dem Backend verbinden.

| Dokument | Inhalt |
|---|---|
| [Standalone-Frontend](schul-it/standalone-frontend.md) | Frontend ohne WordPress einrichten, testen, Fehlersuche |
| [wordpress-plugin/INSTALL.md](../wordpress-plugin/INSTALL.md) | WordPress-Plugin installieren, konfigurieren, aktualisieren |
| [wordpress-plugin/README.md](../wordpress-plugin/README.md) | Plugin im Detail: Shortcode, Hooks, Architektur |

## Betreiber

Das Backend installieren, Schulen einrichten, sichern und aktualisieren.

| Dokument | Inhalt |
|---|---|
| [Betriebsmodell](betreiber/betriebsmodell.md) | Backend beim Schulträger, Frontend an der Schule: Aufgaben, Netzwerk, neue Schule anbinden |
| [installation.md](betreiber/installation.md) | Backend mit Docker installieren: Konfiguration, Start, Admin-Zugang, Formulare, Virenscan, Fehlersuche |
| [installation-ohne-docker.md](betreiber/installation-ohne-docker.md) | Backend auf Apache/PHP ohne Docker |
| [betrieb.md](betreiber/betrieb.md) | Updates, Sicherung und Wiederherstellung, HTTPS, Reverse-Proxy (Client-IP), Upload-Limits, Überwachung, Checkliste |
| [notfall.md](betreiber/notfall.md) | Notfall-Handbuch: Ausfall, Datenbank, Datenverlust, Sicherheitsvorfall, Speicher, Update-Rückfall |
| [MULTI-TENANT.md](betreiber/MULTI-TENANT.md) | Mehrere Schulen betreiben, neuen Tenant einrichten |
| [MIGRATION-3.1.md](betreiber/MIGRATION-3.1.md) | Upgrade 3.0 → 3.1: Formular-Editor, Cache, CLI |
| [MIGRATION-3.0.md](betreiber/MIGRATION-3.0.md) | Upgrade 2.x → 3.0: Mehrmandantenfähigkeit |
| [../backend/PDF_SETUP.md](../backend/PDF_SETUP.md) | PDF-System einrichten und testen |
| [../backend/UPLOAD_SECURITY.md](../backend/UPLOAD_SECURITY.md) | Datei-Uploads und Virenscan |

## Entwicklung

Architektur, Tests, Pipeline, Planung.

| Dokument | Inhalt |
|---|---|
| [../CLAUDE.md](../CLAUDE.md) | Einstieg für Entwicklung: Kernkonzepte, Orientierung, Befehle, Konventionen, Dokumentationskarte |
| [architektur.md](entwicklung/architektur.md) | Architektur, Dateiliste, Datenfluss, Datenbankschema, Funktionsumfang, Status-Ablauf |
| [konfiguration.md](entwicklung/konfiguration.md) | Referenz: Einstellungen von Backend und Frontend, Formular-Konfiguration |
| [pdf-system.md](entwicklung/pdf-system.md) | PDF-Download: Token, Proxy, Komponenten |
| [sicherheit.md](entwicklung/sicherheit.md) | Schutzmaßnahmen und bekannte Einschränkungen |
| [tests.md](entwicklung/tests.md) | PHPUnit-Suite, Pipeline, manuelle Tests |
| [messages.md](entwicklung/messages.md) | Zentrale Message-Verwaltung (UI-Texte) |
| [changelog.md](entwicklung/changelog.md) | Änderungshistorie |
| [MULTI-TENANT-DESIGN.md](entwicklung/MULTI-TENANT-DESIGN.md) | Architekturentscheidungen zur Mandantenfähigkeit (englisch) |
| [docker-entwicklung.md](entwicklung/docker-entwicklung.md) | Docker-Entwicklungsstack: Start, Code ändern, Tests, Fehlersuche |
| [../backend/UNITTESTS.md](../backend/UNITTESTS.md) | Tests ausführen und schreiben |
| [../backend/src/UPLOADS.md](../backend/src/UPLOADS.md) | Upload-Verarbeitung im Code |
| [../backend/tools/survey-editor-bundle/README.md](../backend/tools/survey-editor-bundle/README.md) | Code-Editor (CodeMirror) neu bauen |
| [CI_CD.md](entwicklung/CI_CD.md) | GitLab-Pipeline und Deployment |
| [TODO.md](entwicklung/TODO.md) | Offene Punkte: Verbesserungen, manuelle Prüfungen, Testlücken |
| [plans/PLAN-3.1.md](entwicklung/plans/PLAN-3.1.md) | Plan und Umsetzungsstand von 3.1 |
| [plans/PLAN-3.1.1.md](entwicklung/plans/PLAN-3.1.1.md) | WordPress-Plugin ohne Shell (ZIP umgesetzt; Verbindungscode, Update-Prüfung offen) |
| [releases/RELEASE-NOTES-3.1.0.md](entwicklung/releases/RELEASE-NOTES-3.1.0.md) | Was 3.1.0 bringt |
| [releases/ABNAHME.md](entwicklung/releases/ABNAHME.md) | Abnahmeprotokolle 3.0.0 und 3.1.0 |
| [releases/RELEASE-3.1.0.md](entwicklung/releases/RELEASE-3.1.0.md) | Release-Check: Prüfstand, Merge-Reihenfolge, Tag, Rückfall |
