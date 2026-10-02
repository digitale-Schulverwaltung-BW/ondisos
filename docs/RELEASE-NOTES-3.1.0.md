# Ondisos 3.1.0 — Formulare im Backend pflegen

Schul-Admins ändern ihre Formulare jetzt selbst im Backend: die Einstellungen in einem normalen Formular, die Fragen (Survey) per Einfügen aus dem
SurveyJS-Creator, mit Prüfung, Vorschau und Verlauf. Neue Schulen starten mit den Formularen einer anderen Schule. Upgrade von 3.0: [MIGRATION-3.1.md](MIGRATION-3.1.md).

## Neu

**Versionsnummern:** Das WordPress-Plugin springt von 2.1.1 auf 3.1.0; Backend, Frontend und Plugin tragen ab jetzt dieselbe Nummer.

**Formular-Editor** (Menü *Formulare*)
- Konfiguration eines Formulars als HTML-Formular statt SQL oder JSON: Version, Speichern im Backend, Empfänger, Vorausfüll-Felder, PDF-Bestätigung, Kalender-Download, E-Mail-Text.
  Fehler erscheinen am Feld, eine gleichzeitige Änderung wird erkannt, jeder Stand ist im Verlauf wiederherstellbar.
- Rollen: Tenant-Admins pflegen die Formulare ihrer Schule, ändern aber weder Dateinamen noch das PDF-Logo (ein Dateipfad auf dem Server) und können nicht zwischen Tenants kopieren.
- Formulare mit Anmeldungen lassen sich nicht löschen. Warnung, wenn ein Formular ohne Empfänger und ohne Speichern im Backend vom Frontend nicht angezeigt würde.

**Survey-Editor**
- Text aus dem Creator einfügen (oder `.json` laden); Code-Editor mit Zeilennummern und Fehlermarkierung.
- *Prüfen*: Syntaxfehler mit Zeile und Spalte, unzulässige Inhalte, **Checkliste „Pflichtfelder für Ondisos"** (hat die Survey ein Feld für Name und eines für E-Mail, und sind es Pflichtfelder? Beides braucht jede Anmeldung, die im Backend gespeichert wird), Hinweise (z. B. Feldnamen aus der Konfiguration, die es nicht mehr gibt), geänderte/entfernte/neue Felder mit den
  Folgen für Excel, Vorausfüll-Links und PDF, Unterschied zur veröffentlichten Fassung.
- *Entwurf* ändert das öffentliche Formular nicht; *Veröffentlichen* wirkt sofort, schlägt eine neue Formular-Version vor und kann zurückgenommen werden (Verlauf).
- **Vorschau**: Entwurf oder veröffentlichte Fassung wie für Besucher, mit Handy-/Tablet-Breite; „Abschicken" sendet nichts. Läuft in einem abgeschotteten Frame.
- Der Creator selbst ist **nicht** Teil von Ondisos (proprietäre Lizenz); der Ablauf „Creator → JSON kopieren → einfügen" bleibt, siehe [SURVEYJS.md](SURVEYJS.md).

**Neue Schulen**
- Beim Anlegen eines Tenants (oder später) lassen sich die Formulare eines anderen Tenants übernehmen — Konfiguration, Survey und Theme. **Empfänger-Adressen und PDF-Logo werden bewusst nicht kopiert**,
  Anmeldungen, Uploads, Entwürfe, Verlauf und Secret nie. Ein Bericht nennt, was zu prüfen ist (Empfänger fehlt, Texte mit Namen der alten Schule, Kontaktdaten in der Survey).
- Das WordPress-Plugin zeigt im Verbindungsstatus jetzt, ob das Secret zum Tenant passt und wie viele Formulare der Tenant hat (bei 0 ein Hinweis). Dafür gibt es den signierten Endpunkt `GET /api/forms.php`.

**Frontend und WordPress**
- Surveys und Themes kommen mit der Konfiguration aus dem Backend (ein Aufruf, ETag/304). Das Frontend merkt sich die letzte Fassung und liefert sie weiter aus, wenn das Backend kurz
  nicht erreichbar ist (bis 7 Tage); ein gelöschtes Formular oder ein deaktivierter Tenant wird nicht aus dem Cache bedient. Die Dateien in `frontend/surveys/` bleiben als Fallback.
- Ein unbekanntes Formular ist ein 404, ein gestörtes Backend eine Wartungsseite (503) — mit der Ursache im Log.

**Kommandozeile**
- `import-surveys.php` (Survey-/Theme-Dateien ins Backend, mit Trockenlauf und `--overwrite`), `copy-forms.php` (Formulare zwischen Tenants), `seed-forms.php --tenant=<slug>` (prüft jeden Eintrag).

## Sicherheit

- HTML in Surveys nur aus einer Allowlist; Kommentare und Sonderkonstrukte sind verboten, jeder Tag-Name im Rohtext wird geprüft (nicht nur der geparste DOM). 54 Umgehungsformen sind getestet.
- Das Backend liefert nur Surveys/Themes aus, die die Prüfung *heute* bestehen (auch Altbestand und per SQL Gespeichertes); das Frontend kodiert JSON beim Einbetten zusätzlich.
- Schreibende Aktionen sind je Benutzer begrenzt (60/min), Größenlimits (Survey 512 KB, Konfiguration 64 KB, 100 Formulare je Tenant), CSRF und Mandanten-Isolierung in jeder Abfrage.
- Audit-Ereignisse ohne Inhalte, darunter `form_survey_rejected`, `survey_import_rejected`, `form_delivery_rejected`, `form_copied`, `form_copy_denied`.
- Das Backend lädt Bootstrap lokal (keine externe CDN mehr).
- Der Name **ondisos** steht jetzt deutlich in der Kopfzeile des Backends und auf der Anmeldeseite.

## Behoben

- Standalone-Frontend: `index.php` brach nach dem `<head>` ab, weil `$tenantSlug` nicht gesetzt war (Regression aus einem Fix von 3.0).
- `seed-forms.php`: Die Warnung vor Formularen, die Anmeldungen verwerfen würden, prüft jetzt den gewählten Tenant statt immer Tenant 1.

## Upgrade in Kürze (Details: [MIGRATION-3.1.md](MIGRATION-3.1.md))

1. Code aktualisieren; `php backend/migrate.php` (Docker: läuft beim Start) legt drei Tabellen an. Nichts Bestehendes wird verändert.
2. **Docker-Backend-Image neu bauen** (`X-Frame-Options: SAMEORIGIN` für die Vorschau).
3. Cache-Verzeichnis des Frontends beschreibbar machen (`frontend/cache/`, WordPress: `uploads`).
4. Optional: Surveys ins Backend übernehmen (`import-surveys.php`); neue Schulen mit „Formulare übernehmen".

Ohne diese Schritte laufen alle Formulare wie unter 3.0 weiter; ein 3.1-Frontend arbeitet auch gegen ein 3.0-Backend und umgekehrt.

## Bekannte Grenzen

- Surveys liegen im Backend **oder** als Datei im Frontend (Fallback; Abschaffung ohne Termin); wer beides pflegt, sieht die Datenbank-Fassung.
- Das Theme fehlt in der Vorschau, solange es nur als Datei im Frontend liegt.
- `choicesByUrl` in einer Survey lässt den Browser der Besucher einen fremden Server kontaktieren (Warnung im Editor, kein Verbot).
- Die Vorschau trägt eigene Kopien der SurveyJS-Dateien (`backend/tools/sync-preview-assets.sh` nach SurveyJS-Updates).
- Ausblick: 3.1.1 WordPress-Plugin ohne Shell (ZIP ist fertig; Verbindungscode und Update-Prüfung offen) ([plans/PLAN-3.1.1.md](plans/PLAN-3.1.1.md)).
