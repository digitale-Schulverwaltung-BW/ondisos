# Ondisos 3.2.0 — Formulare grafisch bearbeiten

Formulare entstehen jetzt im Backend ohne JSON und ohne fremdes Werkzeug: ein grafischer Editor mit Vorlagen, Bausteinen für Schulanmeldungen und einer Live-Vorschau.
Dazu kommen die Unterschrift per Smartphone, ein Editor für Farben und Schrift der Formulare, die SurveyJS Form Library 3 mit deutschen Standardtexten und
mehrere Härtungen aus einem Sicherheits-Audit. Upgrade von 3.1.2: [MIGRATION-3.2.md](../../betreiber/MIGRATION-3.2.md), Kurzfassung [unten](#upgrade-von-312).
Alle Änderungen im Einzelnen: [Changelog](../changelog.md#320).

## Neu

**Grafischer Survey-Editor** (Anleitung: [SURVEYJS.md](../../redaktion/SURVEYJS.md#der-grafische-editor))
- Reiter **Grafisch** neben **JSON**: Seiten, Gruppen und Felder anlegen, Eigenschaften und Antworten ändern, per Maus oder Tastatur verschieben, duplizieren, löschen; Rückgängig und Wiederholen.
  Beide Reiter bearbeiten denselben Text; der Editor ändert nur die Stelle, die Sie bearbeiten, alles andere bleibt Zeichen für Zeichen erhalten. Der Verlauf zeigt so nur echte Änderungen.
- **Vorlagen** für neue Formulare (Einfaches Formular, Rückmeldung, Rückmeldung ohne E-Mail, Veranstaltung, Schüleranmeldung) und fünf vollständige Beispiele aus einer Schule.
- **Bausteine** für Schulanmeldungen (Person, Herkunft, Anschrift, Erziehungsberechtigte, Vorbildung, Ausbildungsbetrieb) mit den Feldnamen und Auswahllisten der Import-Vorlage von
  ASV-BW, dazu Datenschutz-Zustimmung, Name und E-Mail, Unterschrift, Anmerkungen. Der Excel-Export geht damit ohne Nacharbeit in ASV ([ASV.md](../../sekretariat/ASV.md)).
- **Hilfe bei Bedingungen:** Vorschläge beim Tippen (Felder mit Titel, Vergleiche, Antworten), Klartext unter einfachen Bedingungen, Warnungen bei unbekannten Feldern und Werten,
  *Verwendet in* am Feld. *Prüfen* meldet Verweise auf Felder, die es nicht gibt, jetzt auch im JSON.
- **Live-Vorschau** im eigenen Fenster: folgt jeder Änderung ohne Speichern; Auswahl im Editor zeigt das Feld in der Vorschau und umgekehrt; *Ausgeblendete zeigen* blendet Felder mit nicht erfüllter Bedingung ein.
- **Einstellungen des Formulars** (Titel, Text nach dem Absenden, Knopftexte, Startseite …) über die Zeile **Formular** im Aufbau.
- Der SurveyJS Creator wird nicht mehr gebraucht; JSON aus ihm oder anderen Werkzeugen lässt sich weiter im Reiter **JSON** einfügen.

**Unterschrift per Smartphone** (3.2.0a; [SURVEYJS.md](../../redaktion/SURVEYJS.md#auf-dem-smartphone-unterschreiben))
- Unterschriftsfelder zeigen am Rechner einen QR-Code; unterschrieben wird auf Smartphone oder Tablet, die Unterschrift erscheint im Formular und wird erst mit *Übernehmen* zum Feldwert.
- Schalter je Formular (*Allgemein* → „Unterschrift auf dem Smartphone anbieten“), **Standard aus**. Standalone-Frontend und WordPress-Plugin.

**Design der Formulare** (3.2.0b; [SURVEYJS.md](../../redaktion/SURVEYJS.md#design-theme-ändern))
- *Formulare → Design*: Markenfarbe (mit Kontrasthinweis), Hintergrund, Karten, Schrift, Ecken als Felder, alles Weitere im JSON; Prüfen, Live-Vorschau mit wählbarem Formular, Veröffentlichen, Verlauf.
- Neue Vorgabe mit dunklerer, auf Weiß gut lesbarer Markenfarbe (Kontrast 5,3 : 1).

**Weiteres**
- **SurveyJS Form Library 3.2.0** (statt 2.3.15): Knöpfe und Meldungen des Formulars erscheinen **deutsch**; neue Standardoptik (siehe [Upgrade](#upgrade-von-312)).
- **PDF-Bestätigung gegliedert:** Seitentitel als Überschriften, Fragetitel statt Feldnamen (abschaltbar im Reiter *PDF-Bestätigung*; ältere Anmeldungen bleiben wie bisher).
- **Anmeldeliste:** bis zu acht Felder der Survey als zusätzliche Spalten, Auswahlfelder filterbar ([Sekretariat](../../sekretariat/README.md#2-sehen-was-neu-ist)).
- **Vorschau im Backend:** Leiste zum Springen auf jede Seite, ohne Pflichtfelder zu prüfen.

## Sicherheit

- **`API_SIGNATURE_MODE=strict` ist Standard:** Das Backend nimmt nur noch Signaturformat 2 an (seit 3.1.2 von Frontends und Plugin gesendet). `compat` bleibt als ausdrückliche Übergangslösung.
- **`PDF_TOKEN_SECRET`:** Die öffentlich bekannten Vorgaben aus dem Repository werden in Produktion abgelehnt; PDF-Links gelten höchstens 24 Stunden.
- **Docker:** `docker-compose.prod.yml` veröffentlicht den Backend-Port nur noch auf `127.0.0.1` (`BACKEND_BIND`).
- **PDF-Bestätigungen:** Rate-Limit vor dem Erzeugen je Anmeldung, Besucher und Schule (`PDF_RATE_LIMIT_*`); PDFs werden nicht mehr zwischengespeichert (`Cache-Control: no-store`).
- **Rate-Limits atomar:** Parallele Anfragen kamen bisher alle am Limit vorbei, auch an der Sperre nach falschen Anmeldungen. Zähler laufen jetzt unter einer Dateisperre, Anmeldeversuche werden vor der Prüfung gezählt.
- Live-Vorschau: gerendert wird nur, was der Server geprüft hat, in einem abgeschotteten Rahmen ohne eigene Herkunft; eigenes Rate-Limit (`EDITOR_PREVIEW_RATE_LIMIT_MAX`).
- Unterschrift per Smartphone: eigener Endpunkt nur mit Signaturformat 2, Einmal-Codes mit 10 Minuten Gültigkeit, Rate-Limit je Besucher ([sicherheit.md](../sicherheit.md#unterschrift-per-smartphone-320a)).

## Korrekturen

- `zq.json` und `vabo.json` verwiesen in Bedingungen auf Felder, die es nicht gibt (die Angaben der Erziehungsberechtigten wurden in `zq` nie Pflicht); behoben in den Dateien, Schulen mit
  übernommenen Surveys sehen jetzt eine Warnung beim *Prüfen* ([MIGRATION-3.2.md](../../betreiber/MIGRATION-3.2.md#korrekturen-an-den-beispiel-surveys)).
- *Prüfen* kannte die Funktionen von SurveyJS ungenau: `dateDiff()` u. a. galten als unbekannt, `ceil()`, `now()` u. a. gingen durch, obwohl sie nie einen Wert liefern. Jetzt genau die Funktionen des Bundles.
- Fragen in Auswahloptionen fehlten in PDF, E-Mail, Vorausfüllen und Upload-Prüfung.
- Docker: Nach einem `git pull` mit neuen Composer-Paketen fehlten diese im Container (`Class "BaconQrCode\Writer" not found`); der Start installiert sie jetzt.
- Ein neues Formular ohne Survey meldete „das Formular nutzt die Datei im Frontend“, auch ohne Datei.

## Upgrade von 3.1.2

Ausführlich in [MIGRATION-3.2.md](../../betreiber/MIGRATION-3.2.md). Reihenfolge: **Backend zuerst**, danach Frontends und WordPress-Plugin. Vorher sichern.

1. **Audit-Log prüfen:** Steht dort in den letzten Tagen `api_legacy_signature`, sendet noch ein Frontend das alte Signaturformat; erst aktualisieren oder übergangsweise `API_SIGNATURE_MODE=compat`.
2. **Docker auf mehreren Servern:** Erreichen Frontends das Backend direkt über Port 9080, vor dem Update `BACKEND_BIND=0.0.0.0` setzen und den Port per Firewall begrenzen.
3. **`PDF_TOKEN_SECRET`:** Steht noch eine Vorgabe aus dem Repository darin, einen eigenen Wert setzen (`openssl rand -hex 32`).
4. Update: `git checkout v3.2.0`, `docker compose build --pull backend`, `docker compose up -d backend`. Die Migration legt die Tabelle `signature_handoffs` an.
5. Frontends aktualisieren, WordPress: Plugin-ZIP 3.2.0.
6. **Schulen vorab informieren:** Formulare sehen mit SurveyJS 3 etwas anders aus (Titel dunkel, Karten mit Rahmen). Das alte Aussehen stellt *Formulare → Design* → **Auf Vorgabe zurücksetzen**
   mit der eigenen Markenfarbe wieder her.

Zurückrollen auf 3.1.2 geht ohne Datenbank-Rücksicherung (Migration rein additiv); Einschränkungen in [MIGRATION-3.2.md](../../betreiber/MIGRATION-3.2.md#zurückrollen).

## Hinweise

- Das WordPress-Plugin trägt dieselbe Versionsnummer (3.2.0).
- Die Live-Vorschau öffnet ein eigenes Fenster; der Browser darf es für die Verwaltungsoberfläche nicht blockieren.
- Ziehen mit der Maus gibt es auf Tablets und Smartphones nicht; dort verschieben die Knöpfe *Nach oben* und *Nach unten*.
- Andere Elementtypen (Matrizen, dynamische Panels) bleiben im grafischen Editor unverändert erhalten und sind im Reiter **JSON** änderbar.

**Vor dem Release nicht geprüft** (Abnahme: [ABNAHME.md](ABNAHME.md#v320-grafischer-survey-editor-theme-editor)):
Screenreader, Firefox und Safari mit dem grafischen Editor, Android im Mobilfunknetz bei der Unterschrift per Smartphone, Upgrade einer bestehenden Instanz.

## Nicht enthalten / vorgemerkt

- **Passkeys (WebAuthn)** als weiterer zweiter Faktor ([PLAN-3.1.2.md](../plans/PLAN-3.1.2.md#ap4--passkeys-webauthn-verschoben-auf-313)).
- WordPress-Plugin ohne Shell ([PLAN-3.1.1.md](../plans/PLAN-3.1.1.md)), SMTP-Versand, Abschaffung des Datei-Fallbacks für Surveys.
- Bekannte Einschränkungen: [CLAUDE.md](../../../CLAUDE.md#bekannte-einschränkungen) und [sicherheit.md](../sicherheit.md).
