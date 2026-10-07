# Offene Punkte

Stand: 2026-10-07 (Version 3.1). Hier steht, was noch zu tun ist. Was bereits geprüft wurde, steht in den [Abnahmeprotokollen](releases/ABNAHME.md),
behobene Sicherheitsbefunde in [sicherheit.md](sicherheit.md), Teststand und Pipeline in [tests.md](tests.md) und [CI_CD.md](CI_CD.md).

## Funktionen und Verbesserungen

| Punkt | Hintergrund |
|---|---|
| **WordPress-Plugin ohne Shell, Rest** | Verbindungscode und Update-Prüfung; die ZIP ist umgesetzt. Entwurf: [PLAN-3.1.1.md](plans/PLAN-3.1.1.md) |
| **SMTP-Versand** statt PHP `mail()` | Die Benachrichtigung geht vom Frontend-Server aus |
| **Datei-Fallback für Surveys abschaffen** | `frontend/surveys/` nur noch als Importquelle (`import-surveys.php`); ohne Termin |
| **Permalink auf die neueste Plugin-ZIP** | Das Release-Skript setzt jetzt feste Pfade ([CI_CD.md](CI_CD.md#release-wordpress-plugin)). Beim nächsten Release-Tag prüfen, dass `…/-/releases/permalink/latest/downloads/ondisos-plugin.zip` weiterleitet, und dann in `wordpress-plugin/INSTALL.md` verlinken |
| **Schreibaktionen im Modus „Alle Tenants“** | Als Plattform-Admin ohne gewählte Schule (Umschalter „Alle Tenants“) scheitern Statuswechsel, Löschen und Sammelaktionen mit „Ein unerwarteter Fehler ist aufgetreten“, weil `TenantContext::getTenantId()` dort bewusst eine Ausnahme wirft. Sinnvoll: verständlicher Hinweis „Bitte zuerst eine Schule wählen“ bzw. Buttons ausblenden |
| **Deutsche Locale** für SurveyJS | Validierungsmeldungen erscheinen englisch |
| **`X-Content-Type-Options: nosniff`** beim Datei-Download | Kleine Härtung in `DownloadController`; unbekannte Endungen gehen schon als `application/octet-stream` raus |
| **Monitoring und Logging** | strukturiertes Logging, Anbindung an Überwachung (z. B. Sentry); heute: `health.php` und Audit-Log |
| **API-Beschreibung** (OpenAPI) | die Endpunkte sind in [architektur.md](architektur.md) und im Betriebsmodell beschrieben |
| *Option, nicht geplant:* Managed Multi-Frontend | ein zentral gehostetes Frontend für mehrere Schulen; gestrichen, weil Standalone-Frontend und Plugin-ZIP reichen |

## Manuell zu prüfen

Nur im Browser prüfbar und seit den Abnahmen offen:

- [ ] Upload im Browser-Formular (Datei auswählen; der Server-Teil ist verifiziert)
- [ ] WordPress-Plugin: Verbindungsstatus-Seite (*Einstellungen → Ondisos*) mit den Zeilen „Secret passt" und der Formularzahl
- [ ] Breitenumschalter der Vorschau (Handy/Tablet) in einem echten Browser
- [ ] Vorschau gegen ein frisch gebautes Docker-Image prüfen (in der Abnahme wurde die Apache-Konfiguration im laufenden Container ersetzt)

## Testlücken

Eine Coverage-Messung läuft in der Pipeline (Job `coverage`); lokal ist kein Coverage-Treiber installiert. Die Liste stammt aus einer Suche nach Klassen, die in keiner Testdatei vorkommen
(Stand: 958 Unit-Tests; die Integration-Tests brauchen MySQL, siehe [tests.md](tests.md)).

| Bereich | Lücke | Aufwand |
|---|---|---|
| `PdfGeneratorService`, `PdfTemplateRenderer` | keine direkten Tests (Logo-Einbettung, Abschnitte, Feldfilter; mPDF-Erzeugung) | groß |
| `SpreadsheetBuilder` | keine Tests (Zellformate, Datum `YYYY-MM-DD` → `dd.mm.yyyy`, Spaltenbreite, Zebra-Streifen) | mittel |
| `AnmeldungController` | keine Tests (Integration gegen eine Test-Datenbank); `BulkActionsController` ist seit 3.1.1 mit Unit-Tests abgedeckt | groß |
| `NominatimService`, `NullableHelpers` | keine Tests (externer Dienst gemockt bzw. kleine Hilfsfunktionen) | klein |
| `AnmeldungRepository` | nur Mandanten-Isolierung und Nachbar-Abfragen getestet, CRUD, Soft-Delete und Filter fehlen als Integration-Tests | mittel |
| Endpoint-Skripte (`submit.php`, `upload.php`, `form-config.php`, `forms.php`, `pdf/download.php`) | keine Unit-Tests; ihre Logik liegt in getesteten Services, strukturelle Tests prüfen Reihenfolge und Abhängigkeiten | mittel |
| JavaScript (`survey-handler-*.js`) und WordPress-Plugin | keine automatisierten Tests | groß |
| Ende-zu-Ende | Submit, Upload und PDF als durchgängiger Ablauf (z. B. mit Browser-Automatisierung) | groß |

Langfristiges Ziel: über 80 % Line-Coverage. Die frühere Messung (55,7 % der Zeilen bei 513 Tests) ist überholt.
