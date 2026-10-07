# Formulare erstellen und ändern (SurveyJS)

## Inhaltsverzeichnis

- [Überblick](#überblick)
- [Formulare entwerfen (SurveyJS Creator)](#formulare-entwerfen-surveyjs-creator)
  - [Lizenzhinweis](#lizenzhinweis)
- [Workflow im Backend](#workflow-im-backend)
  - [Bestehendes Formular ändern](#bestehendes-formular-ändern)
  - [Was „Prüfen“ meldet](#was-prüfen-meldet)
  - [Neues Formular erstellen](#neues-formular-erstellen)
  - [Vorschau](#vorschau)
  - [Verlauf und Wiederherstellen](#verlauf-und-wiederherstellen)
- [Zusammenhang: Formular-Konfiguration ↔ Survey](#zusammenhang-formular-konfiguration--survey)
- [Surveys als Dateien (Fallback)](#surveys-als-dateien-fallback)

## Überblick

Ein Formular besteht aus zwei Teilen:

- der **Survey**: dem SurveyJS-JSON mit Seiten, Fragen und Texten (das, was Besucher sehen), und
- der **Formular-Konfiguration**: Empfänger der Benachrichtigung, PDF-Bestätigung, Vorausfüll-Links usw.

Beides pflegen Schul-Admins im Backend unter **Formulare** (ab Version 3.1). Surveys und Themes liegen in der Datenbank (pro Schule), das Frontend holt sie
zusammen mit der Konfiguration vom Backend.

![Liste der Formulare mit Schul-Logo und Farbe der PDF-Bestätigungen](../img/backend-formulare.png)

## Formulare entwerfen (SurveyJS Creator)

Zum Entwerfen dient der Drag-and-Drop-Designer von SurveyJS, erreichbar über
https://surveyjs.io/create-free-survey

Der Creator ist **nicht Teil von Ondisos**. Das Backend enthält nur einen Code-Editor, in den das JSON aus dem Creator eingefügt wird.

### Lizenzhinweis

Die SurveyJS-Bibliothek, die Ondisos zur Anzeige der Formulare nutzt, ist quelloffen (MIT). Der **Creator** ist dagegen proprietär lizenziert; deshalb wird er
weder mitgeliefert noch in das Backend eingebunden. Für seine Nutzung gelten die Bedingungen von SurveyJS (https://surveyjs.io/licensing).

## Workflow im Backend

```
Backend: Formulare → Survey bearbeiten
        │  „JSON kopieren"
        ▼
SurveyJS Creator (Designer ↔ JSON-Editor)
        │  fertiges JSON kopieren
        ▼
Backend: JSON einfügen → Prüfen → Entwurf speichern → Vorschau → Veröffentlichen
        │
        ▼
Frontend / WordPress zeigt die veröffentlichte Fassung
```

### Bestehendes Formular ändern

1. **Formulare** öffnen und beim Formular **Survey bearbeiten** wählen. Im Editor steht die veröffentlichte Fassung (oder, falls vorhanden, Ihr gespeicherter Entwurf).
2. **JSON kopieren** und im Creator unter *JSON Editor* einfügen.
3. Im **Designer** bearbeiten, danach das JSON aus dem *JSON Editor* des Creators kopieren.
4. Im Backend das JSON im Editor ersetzen (alternativ **Datei laden**: eine JSON-Datei von Ihrem Rechner in den Editor laden) und **Prüfen** wählen. Was die Prüfung meldet, steht [unten](#was-prüfen-meldet);
   gespeichert oder veröffentlicht wird dabei nichts.
5. **Entwurf speichern** (oder **Entwurf speichern & Vorschau**): Das veröffentlichte Formular bleibt unverändert. Pro Formular gibt es höchstens einen Entwurf; **Entwurf verwerfen** löscht ihn (nach Rückfrage).
6. **Vorschau** ansehen (siehe unten), danach **Veröffentlichen** (nach einer Rückfrage, denn die Änderung gilt sofort für alle Besucher). Unter dem Knopf lassen sich eine
   **Neue Formular-Version** (optional; leer lassen = unverändert) und eine **Anmerkung für den Verlauf** (optional) eintragen. Die Formular-Version wird mit jeder Anmeldung gespeichert,
   so lässt sich später nachvollziehen, nach welchem Stand ausgefüllt wurde.

![Survey-Editor mit JSON, Prüfen, Entwurf und Veröffentlichen](../img/backend-survey-editor.png)

Wurde die Survey zwischenzeitlich von jemand anderem veröffentlicht, bleibt Ihr Entwurf gespeichert, wird aber nicht veröffentlicht. Der Editor weist darauf hin und zeigt den Unterschied; nach der Prüfung lässt sich erneut veröffentlichen.

### Was „Prüfen“ meldet

| Bereich | Bedeutung |
|---|---|
| **Fehler** (blockieren Speichern und Veröffentlichen) | kein gültiges JSON (mit **Zeile und Spalte** und einem Link zur Stelle im Editor), zu groß, keine Fragen, Frage ohne `name`, doppelte oder reservierte Feldnamen, HTML außerhalb der erlaubten Auswahl, `javascript:`-Links |
| **Pflichtfelder für Ondisos** | Gespeicherte Anmeldungen brauchen einen **Namen** (Feld `Name` oder `name`) und eine **E-Mail-Adresse** (`email`, `email1`, `Email`, `E-mail` oder `E-Mail`); sie erscheinen in Übersicht, Excel-Export und Benachrichtigungen. Fehlt eines, gibt es eine **Warnung**, aber keinen Fehler. Beim Absenden würde die Anmeldung dann **abgelehnt** |
| **Hinweise** | Warnungen, die nichts blockieren, z. B. Feldnamen im PDF-Filter, in Vorausfüll-Links oder im Mailtext, die es in der Survey nicht gibt (meist ein Tippfehler) |
| **Änderungen an den Feldern** | neue sowie entfernte oder umbenannte Felder. Fehlende Felder fehlen künftig in neuen Anmeldungen, im Excel-Export, in Vorausfüll-Links und im PDF; bereits gespeicherte Anmeldungen behalten ihre Daten |
| **Unterschied zur veröffentlichten Fassung** | aufklappbar, mit Anzahl der hinzugefügten (+) und entfernten (−) Zeilen |

Wer Felder umbenennt (z. B. um für ASV passende Namen zu bekommen), sollte den Bericht zu entfernten und neuen Feldern lesen: Ein umbenanntes Feld ist für Excel-Export und PDF ein **neues** Feld.

### Neues Formular erstellen

1. **Formulare → Neues Formular anlegen**: **Schlüssel** eingeben (Kleinbuchstaben, Ziffern, `_` und `-`) und **Anlegen**. Der Schlüssel steht in der Adresse (`?form=<schlüssel>`) und im
   WordPress-Shortcode `[ondisos form="<schlüssel>"]` und lässt sich später nicht mehr ändern. Der Formular-Editor öffnet sich.
2. Im Reiter **Allgemein** die Konfiguration ausfüllen: **Benachrichtigung an** (Empfänger), **Anmeldungen im Backend speichern** (standardmäßig an), ggf. **Felder für Vorausfüll-Links**;
   weitere Reiter siehe unten. Die Version steht zunächst auf `1.0.0`; Survey- und Theme-Datei (`<schlüssel>.json`, `survey_theme.json`) betreffen nur Plattform-Admins.
3. **Survey bearbeiten** (Button in der Formularliste oder im Reiter **Info**): Das neue Formular hat noch keine Survey im Backend; der Editor weist darauf hin
   („Datei im Frontend … mit der ersten Veröffentlichung übernimmt das Backend“). Das im Creator entworfene JSON einfügen, prüfen, als Entwurf speichern und veröffentlichen.
4. Einbetten: Standalone `…/index.php?form=<schlüssel>`, WordPress `[ondisos form="<schlüssel>"]`.

Im Reiter **Info** finden Sie außerdem den Verlauf der Konfiguration und **Formular löschen** (nur möglich, solange keine Anmeldungen zum Formular vorliegen).

Eine neue Schule kann stattdessen mit den Formularen einer anderen Schule beginnen (*Formulare übernehmen von …* beim Anlegen der Schule durch den Betreiber, siehe [MULTI-TENANT.md](../betreiber/MULTI-TENANT.md)).
Empfänger-Adressen und Logo werden dabei nie kopiert.

### Vorschau

Die **Vorschau** (Button im Survey-Editor) zeigt Entwurf oder veröffentlichte Fassung so, wie Besucher das Formular sehen. Oben schalten Sie zwischen **Entwurf** und **Veröffentlicht** um
und wählen die Breite **Handy**, **Tablet** oder **Desktop**. Ein gelber Hinweis nennt, welche Fassung gezeigt wird („Gezeigt wird der Entwurf. Besucher sehen noch die veröffentlichte Fassung.“).
Eingaben werden nicht gespeichert, „Abschicken“ sendet nichts. Die Vorschau läuft in einem abgeschotteten Rahmen und ändert nichts am Live-Formular.

![Vorschau des Formulars im Backend](../img/backend-vorschau.png)

So sieht das Formular für Besucher aus:

![Das Anmeldeformular im Frontend](../img/frontend-formular.png)

### Verlauf und Wiederherstellen

Jede Veröffentlichung landet im **Verlauf** unterhalb des Editors (Tabelle mit *Wann*, *Anmerkung* und *Wer*; die letzten 50 Stände je Formular und Typ). Wird ein Formular zum ersten Mal im Backend geändert,
legt das System zuerst den bisherigen Stand als „Stand vor der Änderung“ ab. Mit **Wiederherstellen** (nach Rückfrage) wird ein früherer Stand zum aktuellen Formular; der Vorgang erscheint im Verlauf
als „Wiederhergestellt aus Revision #…“, nichts geht verloren. Die Konfiguration (Reiter **Info** im Formular-Editor) hat einen eigenen Verlauf.

## Zusammenhang: Formular-Konfiguration ↔ Survey

Der Schlüssel der Formular-Konfiguration (`form_key`) bestimmt den URL-Parameter (`?form=<key>`) und den WordPress-Shortcode (`[ondisos form="<key>"]`). Die Konfiguration
verweist mit *Survey-Datei* und *Theme-Datei* auf die Survey und das Design-Theme (Name, z. B. `bs.json` und `survey_theme.json`); beides liegt als Ressource im Backend.

Die Konfiguration wird im Formular-Editor (**Formulare → Bearbeiten**) in Reitern gepflegt: *Allgemein*, *Benachrichtigungs-E-Mail*, *PDF-Bestätigung*, *Kalender-Download (iCal)* und *Info*.

![Formular-Editor, Tab Allgemein](../img/backend-formular-editor.png)

Für jedes Formular lassen sich konfigurieren:

- Empfänger einer Benachrichtigungs-Mail (**Benachrichtigung an**, mehrere Adressen möglich)
- ob die Daten in der Datenbank gespeichert werden (**Anmeldungen im Backend speichern**). Es ist auch denkbar, Anmeldungen nur per interner E-Mail entgegenzunehmen, ohne sie zu speichern. Das ist *nicht* empfohlen,
  da E-Mail-Benachrichtigungen nicht so zuverlässig sind wie ein Abspeichern. Ohne Speichern ist eine gültige Empfänger-Adresse **Pflicht**: Ohne Empfänger gingen die Daten
  verloren, deshalb zeigt das Frontend so ein Formular nicht an.
- ob ein PDF-Download nach dem Absenden angezeigt wird (Logo und Farbe der Schule, optional ein angehängtes PDF, PDF-Vorschau): siehe [PDF-Bestätigung gestalten](pdf-bestaetigung.md)
- ob Teile des Formulars als Link vorausgefüllt weitergegeben werden können (**Felder für Vorausfüll-Links**; für Firmen, die regelmäßig Auszubildende anmelden)
- ein **Kalender-Download** (iCal) nach dem Absenden und der **Einleitungstext der Benachrichtigungs-E-Mail**

![Tab „PDF-Bestätigung“ mit Logo, PDF-Vorschau und angehängtem PDF](../img/backend-formular-pdf.png)

⚠️ **Wichtig:** Es ist kein E-Mail-Versand der Formulardaten an den Benutzer vorgesehen, da diese unverschlüsselt übermittelt würden. Soll der Benutzer eine Bestätigung
erhalten, muss die PDF-Bestätigung aktiviert werden: Sie wird TLS-verschlüsselt (über https) an das Endgerät übertragen.

Die Konfiguration lässt sich auch per Skript einspielen: `php backend/seed-forms.php [--tenant=<slug>]` (fügt neue Formulare hinzu, überschreibt vorhandene nie, prüft jeden
Eintrag; Vorlage: [frontend/config/forms-config-dist.php](../../frontend/config/forms-config-dist.php)), siehe [MIGRATION-3.0.md § 6](../betreiber/MIGRATION-3.0.md#6-danach-formular-konfiguration-ändern).

## Surveys als Dateien (Fallback)

Bis zur Version 3.0 lagen die Surveys als JSON-Dateien im Frontend (`frontend/surveys/`). Das funktioniert weiterhin als **Fallback**: Liefert das Backend für den Namen keine
Survey, nutzt das Frontend die Datei. Sobald eine Survey im Backend liegt, hat sie Vorrang; wer beides pflegt, sieht die Datenbank-Fassung.

Vorhandene Dateien lassen sich in die Datenbank übernehmen:

```bash
php backend/import-surveys.php [--tenant=<slug>] [--overwrite] [--dry-run] frontend/surveys

# Docker: der Container sieht frontend/ nicht, das Verzeichnis zuerst hineinkopieren
docker compose cp frontend/surveys backend:/tmp/surveys
docker compose exec backend php import-surveys.php /tmp/surveys
```

Details und Upgrade-Hinweise: [MIGRATION-3.1.md](../betreiber/MIGRATION-3.1.md). Der Datei-Fallback soll später entfallen (kein Termin); die Dateien bleiben dann nur Importquelle.
