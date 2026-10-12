# Offene Punkte

Stand: 2026-10-07 (Version 3.1). Hier steht, was noch zu tun ist. Was bereits geprüft wurde, steht in den [Abnahmeprotokollen](releases/ABNAHME.md),
behobene Sicherheitsbefunde in [sicherheit.md](sicherheit.md), Teststand und Pipeline in [tests.md](tests.md) und [CI_CD.md](CI_CD.md).

## Funktionen und Verbesserungen

| Punkt | Hintergrund |
|---|---|
| **WordPress-Plugin ohne Shell, Rest** | Verbindungscode und Update-Prüfung; die ZIP ist umgesetzt. Entwurf: [PLAN-3.1.1.md](plans/PLAN-3.1.1.md) |
| **Passkeys (WebAuthn) als zweiter Faktor (3.1.3)** | Netz-Allowlist, Zwei-Faktor mit TOTP und Login-Rate-Limit sind mit 3.1.2 umgesetzt. Offen: Passkeys (AP4), `web-auth/webauthn-lib`, Registrierung im Wizard und unter „Mein Konto“, Auswahl der Methode auf der Prüfseite. [PLAN-3.1.2.md](plans/PLAN-3.1.2.md#ap4--passkeys-webauthn-verschoben-auf-313) |
| **Signaturformat: `strict` als Standard (ab 3.1.3)** | Mit 3.1.2 ist `API_SIGNATURE_MODE=compat` Standard (Umstellungsphase). Im Folgerelease `SignatureVerifier::DEFAULT_MODE`, `.env.example` und Doku auf `strict` stellen, später Format 1 entfernen. `SignatureModeDefaultTest` schlägt ab Version 3.1.3 fehl, bis das erledigt ist. [PLAN-3.1.2.md](plans/PLAN-3.1.2.md#ap6--signaturformat-2-für-die-api-umgesetzt-festschreibung-offen) |
| **Theme im SurveyJS-3-Format** | Mit 3.2.0 übersetzt `applyTheme` das alte `survey_theme.json` (`--sjs-*`), Markenfarbe und Schrift bleiben. Titel, Kartenstil und Hintergrund folgen aber den neuen Standardwerten (schwarzer Titel, abgerundete Karten, getönter Hintergrund). Wer das alte Aussehen will, baut das Theme mit `--sjs2-*`-Variablen nach |
| **SMTP-Versand** statt PHP `mail()` | Die Benachrichtigung geht vom Frontend-Server aus |
| **Datei-Fallback für Surveys abschaffen** | `frontend/surveys/` nur noch als Importquelle (`import-surveys.php`); ohne Termin |
| **Monitoring und Logging** | strukturiertes Logging, Anbindung an Überwachung (z. B. Sentry); heute: `health.php` und Audit-Log |
| **API-Beschreibung** (OpenAPI) | die Endpunkte sind in [architektur.md](architektur.md) und im Betriebsmodell beschrieben |
| *Idee, nicht geplant:* Rolle „Sekretariat“ | Heute sieht jedes Konto einer Schule alle Anmeldungen und darf Formulare sowie die Empfänger-Adresse (`notify_email`) ändern, im Mehrschul-Betrieb wie im Einzelbetrieb; im Einzelbetrieb hat jedes Konto zusätzlich die Platform-Rolle im Editor (`EditorAccess`). Möglich wäre eine Rolle `sekretariat` an `tenant_admins` (nur Anmeldungen bearbeiten, exportieren, PDF; kein Editor, keine Empfänger-Adresse, keine Kontenverwaltung). Gegenargument: Export und Löschen braucht das Sekretariat ohnehin, und in kleinen Schulen sind Sekretariat und Redaktion oft dieselbe Person. Wird erst verfolgt, wenn eine Schule es braucht. Verwandte Idee: Hinweis-Mail an die bisherigen Empfänger, wenn `notify_email` geändert wird (schützt gegen stilles Umleiten, ohne neue Rollen). Hintergrund: Audit F-09, [PLAN-3.1.2.md](plans/PLAN-3.1.2.md) |
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
