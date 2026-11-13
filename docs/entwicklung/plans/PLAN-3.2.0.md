# PLAN 3.2.0 – Grafischer Survey-Editor im Backend

**Status:** in Arbeit (Stand 2026-11-11); AP0 bis AP2, AP4 und [AP4a Live-Vorschau](#ap4a-ergebnis-2026-11-11) umgesetzt, AP3 größtenteils, dazu [Fragen in Auswahloptionen](#fragen-in-auswahloptionen-2026-11-11). Als Nächstes: AP5, danach AP6, AP7. Voraussetzung erfüllt: die SurveyJS-Library ist auf 3.2.0 aktualisiert (siehe [changelog.md](../changelog.md)), der Editor arbeitet gegen diese Version. Eigenes Release **3.2.0**, weitgehend unabhängig von den übrigen Vorhaben (Passkeys 3.1.3, WordPress-Plugin ohne Shell, SMTP).
Die Aufwände sind Schätzungen für eine Person und nicht an einem Prototyp gemessen; **AP0** soll sie belegen oder korrigieren.

## Ausgangslage

- Der Survey-Editor im Backend (`backend/public/form_survey.php`, `App\Controllers\SurveyEditorController`) arbeitet **textbasiert**: Das SurveyJS-JSON steht in einer Textarea (`#survey-text`, Feld `survey_text`), optional mit CodeMirror (`public/assets/codemirror/survey-editor-cm.js`). Die Aktionen *Prüfen*, *Entwurf speichern*, *Vorschau*, *Veröffentlichen*, *Verlauf* und *Wiederherstellen* senden diesen Text.
- Entworfen wird bisher mit dem SurveyJS Creator außerhalb von Ondisos. Er ist proprietär lizenziert und deshalb **nicht Teil** von Ondisos ([SURVEYJS.md](../../redaktion/SURVEYJS.md)). Die Bibliothek (Anzeige der Formulare) ist MIT.
- Es gibt bereits die gesamte Prüfkette: `SurveyValidator` (Fehler: JSON, Größe, Namen, reservierte Namen, HTML-Allowlist, `javascript:`), `SurveyLinter` (Pflichtfelder Name oder E-Mail, Konfigurationsnamen), `SurveyFieldExtractor`, `SurveyDiff`, `HtmlPolicy`, die Vorschau mit eigenen SurveyJS-Kopien (`public/assets/preview/`) und den Verlauf in `form_resources`.

### Was die vorhandenen Formulare tatsächlich nutzen

Auswertung von `frontend/surveys/*.json` (ohne Theme; Stand 3.1.2):

| | |
|---|---|
| Elementtypen | `text` 138, `dropdown` 30, `html` 23, `panel` 22, `radiogroup` 12, `checkbox` 8, `boolean` 6, `file` 3, `comment` 3, `tagbox` 1, `expression` 1 |
| Häufige Eigenschaften | `title` 199, `titleLocation` 107, `isRequired` 105, `startWithNewLine` 63, `choices` 51, `showNumber` 47, `inputType` 33, `placeholder` 32, `description` 11, `colCount`, `defaultValue`, `labelTrue`/`labelFalse` |
| Logik | `requiredIf` 39, `visibleIf` 16, dazu wenige `expression` und `setValueExpression` |
| Seiten | 1 bis 6 je Formular |

Nicht vorhanden: Matrizen, dynamische Panels, Bewertung, Ranking. Neu dazu kommt `signaturepad` (seit 3.1.2 unterstützt; **PNG** verwenden, siehe [SURVEYJS.md](../../redaktion/SURVEYJS.md#unterschriftsfelder)).

## Ziel

Schul-Admins und Redaktion bauen und ändern **einfache Formulare grafisch im Backend**, ohne SurveyJS-Creator und ohne JSON von Hand: Seiten und Fragen anlegen, Eigenschaften bearbeiten, Auswahllisten pflegen, Reihenfolge ändern, Vorschau ansehen, veröffentlichen.

**Nicht Ziel:** Parität mit dem SurveyJS Creator. Kein Ausdruckseditor für beliebige Logik, keine Matrizen oder dynamischen Panels, keine Übersetzungs- oder Theme-Oberfläche, keine Änderung an der Formular-Konfiguration (`form_configs`).
Was die Oberfläche nicht kann, bleibt im JSON-Editor erreichbar (Expertenmodus) und geht dort **nie verloren**.

## Entscheidungen

1. **Das JSON bleibt die einzige Wahrheit.** Die grafische Oberfläche ist eine zweite Ansicht auf denselben Text (`survey_text`); der Server bekommt weiterhin nur JSON und prüft wie bisher. Kein neues Datenformat, keine Migration, `form_resources` bleibt unverändert.
2. **Verlustfreier Round-Trip.** Der Editor ändert nur, was der Benutzer anfasst. Unbekannte Eigenschaften, unbekannte Elementtypen und Logik, die er nicht darstellen kann, bleiben unverändert im Dokument (Registry-Prinzip: *unterstützt* heißt „bearbeitbar", nicht „erlaubt"). Das ist die wichtigste Anforderung; ohne sie zerstört die Oberfläche handgeschriebene Surveys.
3. **Expertenmodus bleibt.** Umschalter *Grafisch* / *JSON*; beide arbeiten auf demselben Text. Der JSON-Editor ist der Rückfall für alles Unterstützte-nicht.
4. **Tabellengesteuert statt Handarbeit.** Eine **Element-Registry** beschreibt je Typ die bearbeitbaren Eigenschaften (Name, Typ des Eingabefelds, Vorgabewert, Hilfetext). Das Eigenschaften-Panel wird daraus erzeugt; ein neuer Typ ist ein Registry-Eintrag.
5. **Kein Code, keine Texte, keine Icons aus dem Creator.** Clean-Room-Nachbau gegen das offene JSON-Format und die MIT-Bibliothek. Lizenzbefund und Regeln: Abschnitt [Lizenz und Clean-Room](#lizenz-und-clean-room).
6. **Stack bleibt:** Vanilla JavaScript, Bootstrap 5, keine Build-Kette als Pflicht. Für Drag-and-drop höchstens eine kleine MIT-Bibliothek (z. B. SortableJS), Lizenz und Größe vorher prüfen und in `LICENSES.txt` eintragen (wie bei CodeMirror).
7. **Standard bleibt streng:** Der grafische Editor schlägt für neue Formulare Name **und** E-Mail als Pflichtfelder vor (3.1.2: mindestens eines genügt serverseitig, die Standard-Anmeldung soll beides abfragen). Verzicht auf die E-Mail ist eine bewusste Handlung (Datensparsamkeit).

## Lizenz und Clean-Room

Befund (Lizenzseite und Developer License Agreement von SurveyJS, gelesen am 2026-10-12; keine Rechtsberatung):

- Das Agreement gilt für die **kommerziellen Pakete** (Creator, PDF Generator, Dashboard). Die Form Library (`survey-core`, `survey-js-ui`) steht unter MIT.
- Kernklausel: *„At no time may licensee create any tool, redistributable, or product that directly or indirectly competes with the software development product(s) by utilizing all or any portion of the Devsoft Baltic software development product(s)."* Sie richtet sich an den **Lizenznehmer** und verbietet das Konkurrenzprodukt **unter Verwendung** der Software.
- Ein Editor, der nur das offene SurveyJS-JSON erzeugt und nichts vom Creator nutzt, fällt nach Wortlaut wohl nicht darunter. Der Begriff „indirectly competes" ist unscharf; ob SurveyJS einen Ondisos-Editor als Wettbewerb sieht, ist offen.

Regeln für die Umsetzung (gelten auch für KI-Assistenten):

1. Kein Blick in Creator-Quelltext oder -Bundles (auch nicht in `survey-creator-*` aus npm), keine Übernahme von Code, Texten, Icons oder Layout-Details.
2. Keine Creator-Lizenz und keine Testversion in der Entwicklung: wer lizenziert ist, ist „Licensee", dann greift die Klausel unmittelbar.
3. Der Editor trägt keinen Namen und keine Marken von SurveyJS („Creator", „Survey Creator" o. ä.).
4. Grundlage sind allein das JSON-Format (dokumentiert, von der MIT-Library gelesen) und die MIT-Library selbst.

### Antwort von SurveyJS (2026-11-13)

Auf die schriftliche Anfrage (eigener, quelloffener Editor, der nur das offene SurveyJS-JSON erzeugt, ohne Code des Creators) hat Devsoft Baltic OÜ per Mail (CEO) **zugestimmt**. Zwei Bedingungen:

1. Der **MIT-Copyright-Hinweis** der Form Library bleibt erhalten. Umgesetzt: `frontend/public/assets/LICENSES.txt` und ab jetzt dieselbe Datei neben den Vorschau-Kopien (`backend/public/assets/preview/LICENSES.txt`; `sync-preview-assets.sh` kopiert sie mit, `PreviewAssetsTest` prüft die Gleichheit). Die Bundles tragen den Hinweis außerdem im Kopf.
2. Der Editor wird **nicht als SurveyJS benannt oder gestaltet** (eingetragene Marke). Das ist Regel 3 oben; sie gilt auch für Menüpunkte, Seitentitel, Meldungen (`messages.php`) und die Redaktions-Doku (AP7). Beschreibende Nennung („erzeugt SurveyJS-JSON", „nutzt die SurveyJS Form Library") bleibt zulässig.

Damit ist die Veröffentlichung des Editors freigegeben. Die Clean-Room-Regeln 1, 3 und 4 gelten weiter (das Urheberrecht am Creator-Code berührt die Zustimmung nicht); Regel 2 bleibt für die Arbeit am Editor bestehen.

**Weitere Angebote aus der Antwort** (nicht Teil von 3.2.0, schließen den eigenen Editor nicht aus; Einschätzung, keine Entscheidung):

| Angebot | Einschätzung |
|---|---|
| **Monaco** als JSON-Editor mit JSON-Schema (Autovervollständigung, Prüfung) | Das Backend hat mit CodeMirror 6 schon einen JSON-Editor; Monaco wäre deutlich größer und braucht Web-Worker (CSP). Den Nutzen erreicht man eher mit einem Schema in CodeMirror: `survey-core` bringt `Serializer.generateSchema()` mit (im Bundle vorhanden, ungeprüft). Idee für später. |
| **Creator-Seite auf der Ondisos-Website** (eine Entwicklerlizenz beim Projekt; lädt und speichert über die eigene Instanz) | Passt schlecht zum [Betriebsmodell](../../betreiber/betriebsmodell.md): Die Verwaltungsoberfläche liegt im Intranet, eine fremde Seite müsste mit Admin-Sitzung und CORS darauf zugreifen; fremder Code im Admin-Kontext widerspricht der digitalen Souveränität. Nicht verfolgen. |
| **Optionaler eingebauter Creator** (Betreiber kauft Lizenz, domaingebundener Schlüssel in `.env`; ohne Schlüssel greift der eigene Editor) | Denkbar als spätere Erweiterung: Creator-Dateien werden nicht mitgeliefert, sondern vom Betreiber installiert. Kollidiert mit Regel 2, solange am eigenen Editor gearbeitet wird; daher frühestens nach 3.2.0 und als eigener Plan. SurveyJS bietet dafür Partnerseite und Hinweise im Blog an. |

## Randbedingungen aus dem bestehenden Code

- **CSP:** Skripte nur mit Nonce, keine Inline-Handler; Muster wie `survey-editor.js` und `admin-ui.js` übernehmen.
- **UI-Texte** über den `MessageService`; für JavaScript per `data-*`-Attribute oder eingebettetes JSON (Muster `survey-diagnostics`). Keine Texte im Code.
- **HTML-Elemente (`html`)** unterliegen `HtmlPolicy`; der Editor darf nur die erlaubte Auswahl anbieten, die Prüfung am Server bleibt maßgeblich (nie dem Client vertrauen).
- **Feldnamen sind Datenschlüssel:** Eine Umbenennung ist für Excel-Export und PDF ein **neues** Feld (`SurveyDiff`). Der Editor erzeugt Namen stabil (nicht aus dem Titel nachführen), warnt beim Umbenennen und kennt die **reservierten Namen** (`_fieldTypes`, `website_url`).
- **Zugriff:** unverändert über `EditorAccess` und den Rate-Limit der Editor-Aktionen; Mandantenkontext wie bisher.
- **Größe:** `SurveyValidator::MAX_BYTES` (512 KB) und `MAX_ELEMENTS` (2000) gelten weiter; die Oberfläche soll sie vor dem Senden anzeigen.

## Architektur (Vorschlag)

```
backend/public/assets/survey-builder/
  json-doc.js         verlustfreie JSON-Schicht: Parser mit Textpositionen, Änderungen als Textersetzung (AP0, fertig)
  model.js            Dokumentmodell auf dieser Schicht (Seiten, Elemente, Pfade, Titel); reine Logik, ohne DOM
  registry.js         Elementtypen und Eigenschaften (Tabelle)
  conditions.js       einfache Bedingungen ⇄ Ausdruck (visibleIf / requiredIf); erkennt „zu komplex"
  ui.js               Seitenliste, Elementliste, Palette, Eigenschaften-Panel
  history.js          Undo/Redo (Schnappschüsse des JSON)
backend/public/form_survey.php      Umschalter Grafisch/JSON, Einbindung
backend/tests/js/                   JS-Tests (`node --test`, Job `test_js`); PHP-Tests für serverseitige Teile unter backend/tests/
backend/tools/survey-builder-harness/ Browserprüfung und Harness (render.php, validate.php, check.mjs); löste den AP0-Durchstich ab
```

`model.js` ist von der Oberfläche getrennt, damit der Round-Trip ohne Browser testbar ist. Der Server ändert sich kaum: höchstens Registry-Daten (z. B. erlaubte HTML-Tags) als JSON-Einbettung und neue Meldungstexte.

## Arbeitspakete

| AP | Inhalt | Aufwand | Abnahme |
|---|---|---|---|
| **AP0** Klärung und Durchstich | Lizenzseite von SurveyJS prüfen. Spike: JSON laden, Seiten und Felder anzeigen, einen Titel ändern, Text zurückschreiben. Round-Trip an **allen** Surveys in `frontend/surveys/` messen. | 1 Woche | Alle Dateien ergeben nach Laden und Zurückschreiben ohne Änderung **dieselbe** JSON-Struktur; Aufwandsschätzung bestätigt oder korrigiert |
| **AP1** Modell und Registry | `model.js`, `registry.js` für die elf genutzten Typen plus `signaturepad`; verlustfreier Round-Trip, stabile Namensvergabe, reservierte Namen | 1–2 Wochen | Modelltests (Round-Trip, unbekannte Eigenschaften bleiben, Namenskollisionen). **Erfüllt**, siehe [AP1-Ergebnis](#ap1-ergebnis-2026-10-12) |
| **AP2** Oberfläche (MVP) | Seiten anlegen, umbenennen, löschen; Elemente hinzufügen, löschen, nach oben/unten; Eigenschaften-Panel; Auswahlliste bearbeiten; Pflichtfeld, Titelposition, Platzhalter; Fehleranzeige aus dem Prüfbericht | 3–4 Wochen | Ein bestehendes Formular (z. B. `ausbildernachmittag.json`) lässt sich komplett grafisch ändern; Tastaturbedienung möglich. **Erfüllt**, siehe [AP2-Ergebnis](#ap2-ergebnis-2026-10-12) |
| **AP3** Integration | Umschalter *Grafisch*/*JSON* in `form_survey.php`, gemeinsamer Text, Dirty-Zustand, *Prüfen*/*Vorschau*/*Speichern*/*Veröffentlichen* unverändert; Warnung beim Wechsel, wenn der Text nicht lesbar ist | 1 Woche | Beide Ansichten zeigen nach jedem Wechsel denselben Stand; JSON-Fehler blockieren den Wechsel mit klarer Meldung. **Größtenteils mit AP2 umgesetzt** (Tab statt Umschalter), siehe AP2-Ergebnis |
| **AP4** Komfort | Drag-and-drop, Verschachteln in Panels, Duplizieren, Undo/Redo | 2–3 Wochen | Reihenfolge per Maus und Tastatur; Undo stellt den Text exakt wieder her. **Erfüllt**, siehe [AP4-Ergebnis](#ap4-ergebnis-2026-11-02) |
| **AP4a** Live-Vorschau | Vorschau in eigenem Fenster, folgt jeder Änderung ohne Speichern; Klick in der Vorschau wählt das Element im Baum, Auswahl im Baum zeigt es in der Vorschau; ausgeblendete Elemente zuschaltbar. Details: [AP4a](#ap4a-live-vorschau-geplant-2026-11-11) | 1–2 Wochen | Jede Änderung erscheint ohne Speichern in der Vorschau, Seite und Eingaben bleiben; Klick wählt in beide Richtungen; nur vom Server geprüfte Texte werden gerendert; `check.mjs`-Abschnitt grün. **Erfüllt**, siehe [AP4a-Ergebnis](#ap4a-ergebnis-2026-11-11) |
| **AP5** Einfache Bedingungen | `visibleIf`/`requiredIf` als „Feld – Operator – Wert" (ein oder mehrere Ausdrücke mit UND/ODER); alles Komplexere bleibt als Text sichtbar und **unverändert** (nur lesbar anzeigen) | 3–4 Wochen | Von den 55 Bedingungen der vorhandenen Surveys wird der Anteil, den die Oberfläche darstellen kann, gemessen und dokumentiert; der Rest bleibt unangetastet |
| **AP6** Vorlagen | Neues Formular aus Vorlage („Anmeldung", „Rückmeldung ohne E-Mail"), Bausteine für Standardfelder (Name, E-Mail, Datenschutz-Zustimmung `consent_…`, Unterschrift) | 1–2 Wochen | Neue Vorlage besteht *Prüfen* ohne Warnung |
| **AP7** Doku und Abschluss | Redaktions-Doku neu ordnen ([SURVEYJS.md](../../redaktion/SURVEYJS.md)), `HelpLinks`-Ziele, Meldungstexte (`messages.php`, auch `messages.example.php`), Changelog, Release Notes, manuelle Tests, Version 3.2.0 an den vier Stellen | 1 Woche | `DocsLinksTest` und `HelpLinksTest` grün; manuelle Testliste durchlaufen |

**Kern (MVP): AP0–AP3**, etwa **6–10 Wochen**. **AP4–AP7** machen daraus ein gut nutzbares Werkzeug (zusammen weitere **2–4 Monate**). AP5 ist der unsicherste Teil und kann bei Bedarf zuerst entfallen; Bedingungen bleiben dann im JSON-Editor.

## AP0-Ergebnis (2026-10-12)

**Lizenz:** geprüft, siehe [Lizenz und Clean-Room](#lizenz-und-clean-room); die schriftliche Bestätigung von SurveyJS liegt seit 2026-11-13 vor ([Antwort](#antwort-von-surveyjs-2026-11-13)).

**Messung an den vorhandenen Surveys** (7 Surveys, zusammen 4003 Zeichenketten, 22 Seiten, 247 Elemente einschließlich Panels; keine doppelten Schlüssel):

| Befund | Folge |
|---|---|
| `ausbildernachmittag`, `gs`, `pdf`, `prefill`, `zq` sind byte-identisch mit `JSON.stringify(…, null, 2)` (Creator-Export) | Neu serialisieren wäre dort verlustfrei |
| `bs` ist von Hand formatiert (kompakte Auswahllisten): neu serialisiert wüchse der Text von 1440 auf 2817 Zeilen | Jede Änderung würde den Verlauf (`form_resources`) mit Rauschen füllen |
| `vabo` mischt Tabs und Leerzeichen in zwei Zeilen | dito, im Kleinen |
| Parsen und Ändern: 2 ms bei 54 KB, 41–46 ms bei 1,3 MB (Limit des Servers: 512 KB) | Vollständiges Neu-Parsen nach jeder Änderung genügt |

**Entscheidung:** Der Editor schreibt **nie** das ganze Dokument neu, sondern ersetzt nur die betroffene Textstelle (`json-doc.js`). Damit bleibt alles andere Byte für Byte erhalten: Einrückung, Tabs, CRLF, kompakte Listen, Schlüsselreihenfolge. Das erfüllt die Anforderung „verlustfreier Round-Trip" auf Textebene, nicht nur strukturell.

**Nachgewiesen** (`backend/tests/js`, 25 Tests, alle grün):
- Der Parser stimmt mit `JSON.parse` überein (alle Surveys, dazu gültige und ungültige Grenzfälle, BOM, Tiefe); doppelte Schlüssel werden gemeldet, `__proto__` bleibt ein gewöhnlicher Schlüssel.
- Jede der 4003 Zeichenketten lässt sich einzeln ersetzen, ohne dass sich außerhalb der Stelle ein Byte ändert; zurückschreiben stellt den Originaltext her (Stichprobe jede 7.).
- Titel aller 22 Seiten und 247 Elemente: ändern und zurücksetzen ergibt exakt den Originaltext; andere Sprachen lokalisierter Titel bleiben.
- Ein Titel in `bs.json` ändert genau **eine** Zeile.
- Das Outline liefert dieselben Fragen in derselben Reihenfolge wie `survey-core` (die ausgelieferte Version 3.2.0) beim Aufbau des Formulars.
- Einfügen und Entfernen von Eigenschaften und Listeneinträgen übernimmt Einrückung, Zeilenende und kompakte Schreibweise der Umgebung.

**Durchstich im Browser** (eine Entwicklungsseite, mit AP2 durch die echte Oberfläche ersetzt): lädt ein Survey, zeigt Seiten, Panels und Fragen, ändert einen Titel und schreibt den Text zurück; bei ungültigem JSON ist die Ansicht gesperrt und nennt Zeile und Spalte.

**Aufwand:** Die Schätzung für AP0–AP3 (6–10 Wochen) wird nicht widerlegt. Das größte Risiko, der verlustfreie Round-Trip, ist mit der Textschicht entschärft. Ungeprüft bleiben AP5 (Bedingungen) und die Registry der Eigenschaften (AP1). Verschieben innerhalb einer Seite oder eines Panels ist seit AP1 umgesetzt (die Texte tauschen die Plätze, das Layout bleibt); zwischen Containern bleibt AP4. **Empfehlung: MVP (AP1–AP3).**

## AP1-Ergebnis (2026-10-12)

**Neu:** `registry.js` (12 Typen: die elf genutzten plus `signaturepad`, je mit Eigenschaften, Art, Standardwert und erlaubten Werten; Palette; Vorlage für neue Elemente), `names.js` (Namen erzeugen, prüfen, Verweise finden), im Modell die Operationen `setProp`, `renameElement`, `addElement`, `removeElement`, `moveElement`, `addPage`, `removePage`, `movePage`. Auf der PHP-Seite `App\Forms\SurveyBuilderRules`: die Regeln des Servers (reservierte Namen, Anzeige-Typen, HTML-Tags, Grenzen) an einer Stelle; `SurveyBuilderRulesTest` hält die für die JS-Tests eingecheckte Kopie (`backend/tests/js/fixtures/survey-rules.json`) gleich. Die Seite bettet dieselben Regeln ab AP3 als JSON ein, der Editor erfindet nichts.

**Die Registry beruht auf der ausgelieferten Library, nicht auf Annahmen** (`registry.test.mjs`): jede Eigenschaft existiert in SurveyJS 3.2.0 für den Typ, mit passender Art; die Standardwerte sind die der Library; angebotene Werte sind gültig; ein neues Element jedes Typs baut SurveyJS als diesen Typ. Sie deckt jede in den vorhandenen Surveys genutzte Eigenschaft ab, bis auf `boolean.validators` (1×; bleibt unberührt im Text).

**Befunde, die den Entwurf bestimmen:**

| Befund | Folge |
|---|---|
| Der Server verlangt eindeutige Feldnamen im ganzen Survey (nicht für `html`, `image`, Panels) und reserviert `_fieldTypes` und `website_url`; zwei vorhandene Namen enthalten Leerzeichen | Namen werden nur beim **Setzen** geprüft, nie bestehende; die Regeln des Servers stehen in `SurveyBuilderRules` |
| Ein leerer Titel ist für SurveyJS dasselbe wie keiner, ein leerer Platzhalter nicht (fehlt er, erscheint „Select…") | Registry-Flag `emptyIsAbsent`, nur für Titel und Beschreibung, gegen die Library geprüft |
| `showNumber` hat den Standardwert `true` | Standardwerte kommen aus der Library, nicht aus der Erinnerung |
| Standardwerte werden nicht geschrieben, bestehende entfernt | kürzerer Text; ein ausdrückliches `"allowClear": true` verschwindet bei Änderung (semantisch gleich) |
| Wird das Element mit der höchsten Nummer gelöscht, kehrt die Nummer zurück | `generateName(…, taken)`: die Seite übergibt Namen der veröffentlichten Version und des Verlaufs (AP3) |
| `signaturepad`: JPEG gibt schwarzen Hintergrund, SVG lehnt der Server ab | die Registry bietet nur `png` an |

**Umbenennen** meldet `changesDataKey` (die Antworten der Frage liegen unter dem Namen: ein neuer Name ist ein neues Feld) und listet alle Verweise auf den alten Namen in Ausdrücken und Texten (`{name}`, `{name.x}`, `{name[0]}`; exakt, nicht `{name2}`); auf Wunsch (`updateReferences`) werden sie nachgeführt. Die Konfiguration der Schule (Vorbelegung, PDF-Felder, Mail-Platzhalter) liegt außerhalb des Surveys: das meldet nach dem Speichern der `SurveyLinter`.

**Nachgewiesen** (JS-Tests: 68, alle grün):
- Unbekannte Typen und Eigenschaften überstehen jede Operation Byte für Byte; `setProp` verweigert sie, statt zu raten.
- Jeder Typ in jedes Survey: SurveyJS baut es, Namen bleiben nach der Server-Regel eindeutig, Hinzufügen plus Entfernen stellt den Text exakt wieder her.
- **Differenztest:** 7 Surveys × 120 zufällige Operationen (hinzufügen, entfernen, verschieben, Titel, Pflichtfeld, umbenennen) laufen parallel auf dem Textmodell und auf einem einfachen Objekt; nach jedem Schritt sind beide gleich, der Text ist gültiges JSON, die Namensregel des Servers gilt, SurveyJS baut das Formular.
- **Mutationsprüfung:** vier absichtlich eingebaute Fehler (falscher Trenner beim Entfernen, Einfügen eine Position zu spät, Lücken füllen, Standardwerte schreiben) schlagen alle fehl.
- Ein Fehler, den die Tests aufdeckten und der behoben ist: `validateName` verglich Elemente über die Objekt-Identität und hätte ein Element mit seinem eigenen Namen kollidieren lassen.

**Offen für AP2/AP3:** Meldungstexte (`builder.type.*`, `builder.prop.*`, Fehlercodes wie `duplicate`, `reserved`) liegen noch nicht in `messages.php` (AP7); `rules` einbetten und `taken` füllen (AP3); Bedingungen als Text (AP5); Elemente zwischen Containern verschieben (AP4); die HTML-Auswahl im Editor aus `htmlTags` der Regeln (AP2).

## AP2-Ergebnis (2026-10-12)

**Entscheidung:** Der Editor ist direkt in `form_survey.php` ein Tab **„Grafisch"** neben **„JSON"** (Partial `inc/survey_builder_tabs.php`, im selben Formular). Der Tab „Grafisch" nutzt die volle Seitenbreite, „JSON" die gewohnte. Die Seite startet im JSON-Tab, wenn der Prüfbericht Fehler hat (sie verweisen auf Zeilen), sonst im grafischen. Damit sind die wesentlichen Teile von AP3 schon geliefert: gemeinsamer Text, Wechsel in beide Richtungen, Sperre mit Meldung bei unlesbarem JSON, Warnung vor ungespeicherten Änderungen (die bestehende).

**Umfang (alles aus AP2):**
- **Aufbau:** Baum aus Seiten, Gruppen und Elementen mit Typ, Titel und Anzahl; Auf- und Zuklappen; Markierung von Elementen mit Prüfmeldungen.
- **Werkzeuge:** Element hinzufügen (Palette nach Gruppen), Seite hinzufügen, nach oben/unten, löschen (mit Rückfrage und Hinweis auf Verweise), Größenanzeige gegen die Grenzen des Servers.
- **Eigenschaften-Panel** aus der Registry: Name, Titel, Beschreibung, Pflichtfeld, Titelposition, Platzhalter, Eingabeart, Spalten, Zeilen, Auswahlliste, Texte für Ja/Nein, HTML-Text, Upload-Hinweis und -Größe, Vorgabewert, Bedingungen als Text. „Darstellung" und „Bedingungen" klappen auf, wenn der Eintrag dort etwas stehen hat.
- **Tastatur:** Pfeile wählen aus, Alt+Pfeil verschiebt, Entf löscht, Enter springt in die Eigenschaften, Esc zurück zur Liste, Links/Rechts klappt Seiten und Gruppen. Meldungen über eine Live-Region.
- **Prüfbericht:** Meldungen stehen am Element (Markierung, Liste im Panel) und, wenn sie keinem Element gehören, oben; nach einer Änderung sind sie als veraltet gekennzeichnet.

**Entwurfsentscheidungen:**
- Eine Änderung wird bei **change** (Feld verlassen oder Enter) übernommen, nie bei jedem Tastendruck: eine Bearbeitung ist eine Änderung des Textes. Der Builder sitzt im Formular der Seite, deshalb sendet Enter in einem Feld **nie** das Formular ab; alle Buttons sind `type="button"` (ein Test sichert das).
- Der Code-Editor bekommt jede Änderung als kleine Textänderung: Strg+Z im JSON-Tab nimmt sie zurück (geprüft). Ohne Code-Editor schreibt der Builder direkt in die Textarea (geprüft).
- Umbenennen eines Feldes fragt vorher nach (ein neuer Name ist ein neues Feld; Excel, PDF und Vorausfüll-Links kennen den alten nicht mehr) und führt die Verweise nach. Fehler erscheinen am Feld, das Feld springt auf den gespeicherten Wert zurück.
- Auswahllisten sind eine Zeile je Antwort, „Wert | Text" für Paare. Unveränderte Zeilen geben ihren ursprünglichen Eintrag zurück (Zahlenwerte und Schreibweise bleiben); in einer Liste mit einzeiligen Einträgen (wie `bs.json`) ist ein neuer Eintrag eine neue einzeilige Zeile, ein geänderter Eintrag eine geänderte Zeile (1 von 262 Antworten ändern = 1 Zeile). Antworten mit weiteren Einstellungen (z. B. Bedingungen) sind schreibgeschützt und verweisen auf den JSON-Tab.
- Was der Builder nicht bearbeitet, wird **benannt**: unbekannte Typen stehen mit ihrem Namen im Baum (verschieb- und löschbar, ohne Felder), unbekannte Eigenschaften stehen als Liste unter dem Panel, weitere Sprachen eines Titels werden genannt und bleiben unverändert.
- Alle Texte kommen aus `config/messages.php` (Gruppe `builder`, per `messages.local.php` überschreibbar) und werden als JSON in die Seite eingebettet; der Code verwendet nirgends `innerHTML` (ein Test sichert das).

**Nachgewiesen:**
- **JS-Tests:** 83 (`node --test`), darunter: jeder Text, den der Code anfragt, existiert; jeder Typ, jede Eigenschaft, jeder Enum-Wert und jeder Fehlercode hat einen Text; die Hilfsfunktionen der Auswahlliste.
- **PHP-Tests:** 1232 Unit-Tests; neu: Pfade der Prüfmeldungen (auch gegen echte Meldungen des Validators), die eingebettete Konfiguration (das JSON kann sein Script-Element nicht verlassen), Struktur der Seite (Textarea im JSON-Tab, keine Inline-Skripte, alle Dateien vorhanden), Drift-Test der Meldungen.
- **Browserprüfung** (`backend/tools/survey-builder-harness/check.mjs`, Chromium, nicht in der Pipeline; 69 Prüfungen): gerendert aus demselben Partial und mit denselben Skripten wie die Seite, an `gs`, `bs`, `vabo`, `ausbildernachmittag` und kleinen Sonderfällen (Survey ohne Seiten, unbekannter Typ, leerer Text, kaputtes JSON, Prüfbericht). Eine **vollständige Sitzung** (jeden Titel ändern, jedes Pflichtfeld umschalten, alle 12 Typen hinzufügen, Seite anlegen, verschieben, löschen) ergibt ein Survey, das der **PHP-Validator des Servers** akzeptiert; ebenso das Ergebnis der `gs`-Sitzung mit Umbenennen, Verweisen und Auswahlliste. Dazu der Lauf unter der CSP des Backends (keine Verletzung), ohne Code-Editor und auf einem schmalen Bildschirm (eine Spalte, kein Querscrollen). Die Prüfung erkennt eingebaute Fehler (Enter sendet das Formular, Rückfrage fehlt, Verweise nicht nachgeführt: alle gefunden).

**Nicht geprüft:**
- Die **echte Seite** mit Datenbank und Login wurde nicht gestartet (die Umgebung hat weder Docker noch MySQL). Geprüft ist das Markup aus demselben Partial mit denselben Skripten in derselben Reihenfolge. Vor dem Release einmal im Docker-Stack durchklicken (manuelle Testliste, AP7).
- Screenreader und andere Browser als Chromium. Umgesetzt sind die ARIA-Muster (Baum mit Rollen, Live-Region, Beschriftungen); getestet ist nur die Tastatur.

**Offen:** ~~Rückgängig/Wiederholen im Builder selbst und Drag-and-drop (AP4)~~ (umgesetzt, siehe AP4-Ergebnis), Bedingungen als einfache Auswahl (AP5), Vorlagen und Bausteine (AP6), Redaktions-Doku und Hilfe-Links (AP7).

## AP4-Ergebnis (2026-11-02)

**Umfang:**
- **Verschieben über Container-Grenzen:** `moveElementTo` im Modell (Seite ↔ Seite, in eine Gruppe, aus einer Gruppe, Survey ohne Seiten). Der Text des Elements wird **wörtlich** umgesetzt und nur neu eingerückt (`sourceAt` + `insertItem(…, source)` in `json-doc.js`), nicht neu serialisiert: kompakte Auswahllisten und Schreibweisen bleiben. Abgelehnt werden: in sich selbst oder ein eigenes Kind (`intoItself`), in eine Frage (`notContainer`), zu tiefe Verschachtelung (`tooDeep`, wie beim Hinzufügen).
- **Duplizieren:** `duplicateElement` setzt eine Kopie direkt hinter das Original. Jeder Name in der Kopie ist neu (`name_2`, `name_3`, …; frei gegenüber allen Namen im Survey, berechneten Werten, reservierten und veröffentlichten Namen), auch Fragen in Auswahloptionen (`choices[].elements`). Verweise innerhalb der Kopie (`{name}` in Bedingungen und Texten) zeigen auf die Kopien, Verweise nach außen bleiben. Unbekannte Typen (z. B. `paneldynamic`) und Gruppen, die einen enthalten, werden nicht kopiert: ihre inneren Namen kennt der Editor nicht.
- **Rückgängig/Wiederholen:** `history.js`, Schnappschüsse des Textes (höchstens 100 Schritte bzw. 8 Mio. Zeichen, die ältesten gehen zuerst). Rückgängig stellt den Text exakt wieder her und wählt das Element, das sich geändert hat. Eine Änderung im JSON-Tab ist ein eigener Schritt. In Textfeldern gilt deren eigenes Rückgängig.
- **Oberfläche:** Knöpfe *Duplizieren*, *Rückgängig*, *Wiederholen*. Tastatur: Alt + ↑/↓ verschiebt, am Rand einer Gruppe aus ihr heraus, am Rand einer Seite auf die vorige/nächste; Alt + → in die Gruppe darüber, Alt + ← aus der Gruppe; Strg/⌘ + D dupliziert; Strg/⌘ + Z, Strg/⌘ + Umschalt + Z (oder Strg + Y). Maus: natives Drag-and-drop im Aufbau (keine Bibliothek, Entscheidung 6): Element auf obere/untere Hälfte einer Frage = davor/dahinter, auf eine Gruppe = hinein, auf eine Seite = an deren Anfang; Seiten auf Seiten = davor/dahinter. Eine Linie bzw. ein Rahmen zeigt das Ziel.

**Nachgewiesen:**
- **JS-Tests:** 100 (`node --test`; neu `move-copy.test.mjs`, `history.test.mjs`): jedes dritte Element jedes Surveys in jeden zweiten Container verschoben stimmt mit derselben Operation auf einem einfachen Objekt überein; bei Surveys im Creator-Format ist der Text danach exakt `JSON.stringify(…, null, 2)` (Einrückung in jeder Tiefe richtig); Hin- und Zurückverschieben ergibt den Originaltext; eine kompakte Liste in `bs.json` behält ihre Zeilen. Jedes Element jedes Surveys dupliziert: Namensregel des Servers erfüllt, SurveyJS baut es, Entfernen der Kopie ergibt den Originaltext. Eine Sitzung (verschieben, duplizieren, Pflichtfeld, löschen) lässt sich Schritt für Schritt exakt zurück- und vorwärtsnehmen.
- **Mutationsprüfung:** fünf eingebaute Fehler (Zielpfad nach dem Entfernen nicht verschoben, Einfügestelle im selben Container um eins versetzt, Verweise in der Kopie nicht nachgeführt, keine Neueinrückung, Namen ohne Abstreifen von `_N`) schlagen alle fehl.
- **Browserprüfung** (`check.mjs`, jetzt 88 Prüfungen, auf macOS mit Chrome): Tastaturwege über Seiten- und Gruppengrenzen (am Ende wieder exakt der Originaltext), Duplizieren, vier Drag-and-drop-Fälle (auf Seite, Seite vor Seite, in Gruppe, aus einer Gruppe hinter eine Frage), verbotene Ziele ändern nichts, Ergebnis vom PHP-Validator akzeptiert, Rückgängig über alle 9 Schritte exakt bis zum Original, Wiederholen bis zum Ende, Tastenkürzel, Rückgängig im Textfeld bleibt beim Feld, Tab-Wechsel ohne Änderung erzeugt keinen Schritt, keine Konsolenfehler.

**Nicht geprüft:** Drag-and-drop auf Touch-Geräten (natives HTML-Drag-and-drop gibt es dort nicht; Tastatur und Knöpfe bleiben), automatisches Scrollen des Aufbaus beim Ziehen über den sichtbaren Bereich hinaus (übernimmt der Browser; im Harness wurde ohne Scrollen geprüft), Screenreader. Die echte Seite mit Login ist weiterhin nicht durchgeklickt (siehe AP2).

**Nebenbefunde:**
- ~~Die Builder-Module werden ohne Versionsparameter geladen (`app.js` und seine `import`s). Nach einem Update kann ein Browser eine alte und eine neue Datei mischen, wie es 3.2.0a bei der Vorschau passierte (`?v=<filemtime>`). Vor dem Release lösen (AP7), z. B. per Import-Map mit Versionen.~~ Gelöst 2026-11-13: `App\Utils\AssetVersion`, Import-Map mit `?v=<filemtime>` je Modul (Survey-Editor, Theme-Editor, Live-Vorschau).
- `meister.json` nutzt Eigenschaften, die die Registry nicht anbietet (`text.min/max/step`, `expression.displayStyle`, `maximumFractionDigits`, `visibleIf` am Ausdruck, `signaturepad.signatureWidth/placeholder`) und `paneldynamic`. Sie bleiben unverändert im Text; `registry.test.mjs` führt sie als Lücken. Kandidaten für die Registry.

## Fragen in Auswahloptionen (2026-11-11)

SurveyJS (ab 2) erlaubt Fragen in einer Antwort (`choices[i].elements`, beliebig tief); das Formular zeigt sie, sobald die Antwort gewählt ist. Server (`SurveyValidator`, `SurveyFieldExtractor`) und Frontend kannten sie seit 3.2.0a, der grafische Editor zeigte sie nicht und zählte sie nicht mit.

**Umfang:**
- **Modell:** `elementsOf` (`names.js`) liefert sie hinter ihrer Frage, wie der Server sie zählt: Namensprüfung (doppelte Namen), Namensvergabe und Elementzahl berücksichtigen sie. `outline` gibt je Element die Antworten mit Fragen (`choices`: Pfad, Text, Kinder), `flatten` erreicht sie. Eine Antwort, die ein Objekt mit `elements` ist, ist ein Container wie eine Gruppe (`isChoicePath`, `addElement`, `moveElementTo`); einfache Antworten (Text oder Objekt ohne `elements`) bleiben es nicht, der Editor macht aus keiner Antwort eine mit Fragen. Die Verschachtelungstiefe (`tooDeep`) zählt Antwort-Ebenen mit, wie der Server.
- **Aufbau:** Unter der Frage je Antwort mit Fragen eine Zeile „Antwort“ (Text, sonst Wert; Anzahl der Elemente), darunter ihre Fragen. Die Zeile ist wählbar (Erklärung und *Zur Frage*), nicht zieh- oder löschbar. Hinzufügen, Hineinziehen, Verschieben innerhalb der Antwort, Alt + → in eine Gruppe darin und Alt + ← (aus der Antwort hinter ihre Frage) wie bei Gruppen; Alt + ↑/↓ hält am Rand der Antwort. Löschen und Duplizieren einer Frage nehmen die Fragen in ihren Antworten mit (Bestätigung nennt ihre Zahl).
- **Antwortliste der Frage:** bearbeitbar wie jede Antwortliste. `matchChoices` (`ui-props.js`) ordnet jede geänderte Zeile der Antwort zu, aus der sie stammt (gleicher Wert, sonst die nächste freie Antwort zwischen ihren unveränderten Nachbarn); die Zeile ändert Wert und Text, alles andere bleibt (Fragen, Bedingungen, übersetzter Text, Zahlenwerte). `setChoices` schreibt nur die geänderten Schlüssel, die Fragen bleiben Byte für Byte. Verschwindet eine Antwort mit Fragen, fragt der Editor nach (`confirm.choices_nested`, mit Zahl der Elemente); Rückgängig stellt sie wieder her. Dasselbe gilt für Antworten mit weiteren Einstellungen (z. B. `visibleIf`), deren Liste bisher ebenfalls schreibgeschützt war.

**Nachgewiesen:** `choice-elements.test.mjs` (10 Tests: Zählung und Reihenfolge wie der Server, Namensprüfung, Eigenschaften, Hinzufügen nur in Antworten mit Fragen, Verschieben hinein, heraus und zwischen Antworten mit Bau durch SurveyJS, `intoItself`, `tooDeep`, Duplizieren und Löschen samt inneren Fragen); alle 110 JS-Tests grün. Im Browser (Harness-Seite mit dem ersten Entwurf von `meister.json`, zwei Ebenen): Anzeige, Auswahl einer Antwort, Alt + ←, Rückgängig, Ziehen auf eine Antwort. `check.mjs` lief zunächst nicht (Playwright fehlte), siehe Nachtrag unten.

Antwortliste (Nachtrag): `choice-elements.test.mjs` +3 Tests (Wert, Text, Position, Umsortieren, Löschen; Bedingungen und übersetzter Text bleiben; Fragen Byte für Byte), alle 113 JS-Tests grün. Im Browser: Text einer Antwort geändert (Fragen bleiben), Zeile gelöscht mit Rückfrage (Abbrechen stellt die Liste wieder her, Bestätigen löscht samt Fragen), Rückgängig; keine Konsolenfehler.

- **Fragen in eine einfache Antwort setzen:** Bei Einfach- und Mehrfachauswahl (`radiogroup`, `checkbox`; nur sie zeigen Fragen in Antworten, SurveyJS `supportElementsInChoice`) bietet das Eigenschaftenfeld unter der Antwortliste „Fragen zu einer Antwort“: Antwort wählen, *Fragen hinzufügen*. `openChoice` (`model.js`) macht aus `"b"` bzw. `2` ein `{"value": …, "elements": []}` und hängt an ein Objekt `"elements": []`; die Antwort erscheint im Aufbau und ist gewählt, neue Elemente aus der Werkzeugleiste kommen hinein, vorhandene zieht man auf ihre Zeile. Eine Antwort ohne Elemente zeigt *Keine Fragen in dieser Antwort*: `closeChoice` entfernt `elements` und macht aus `{"value": …}` wieder den einfachen Wert (Text danach wie vorher). Leert man eine Antwort durch Verschieben oder Löschen, bleibt sie als leere Antwort-Zeile stehen (Ziel zum Hineinziehen), bis man sie so zurücksetzt.

Fragen in einfache Antworten (Nachtrag): `choice-elements.test.mjs` +2 Tests (Text, Zahl und Objekt mit Bedingung werden Antworten mit Fragen, Dropdown nicht; SurveyJS kennt eine hineingesetzte Frage; Zurücksetzen ergibt den alten Text; mit Fragen `notEmpty`), alle 115 JS-Tests grün. Im Browser: Antwort gewählt und umgewandelt (Zeile im Aufbau, gewählt), Textfeld aus der Werkzeugleiste landet darin, Rückgängig, Zurücksetzen; bei einem Dropdown fehlt die Bedienung.

Browserprüfung (Nachtrag): `check.mjs` hat einen Abschnitt `nested` (eigene kleine Survey: Einfachauswahl mit einer Antwort mit Frage, Mehrfachauswahl, Dropdown) mit 16 Prüfungen: Antwort-Zeile im Aufbau, Erklärung und *Zur Frage*, Antwortliste bearbeiten (eine Zeile, die Frage bleibt), Antwort mit Frage löschen (Rückfrage, Rückgängig exakt), Fragen in eine einfache Antwort setzen, Element aus der Werkzeugleiste hinein, Alt + ← heraus, leere Antwort zurücksetzen, Ziehen auf eine Antwort, Bedienung bei Mehrfachauswahl ja und bei Dropdown nein, Ergebnis vom PHP-Validator akzeptiert, keine Konsolenfehler. Die alte `odd`-Prüfung „Antworten mit Bedingungen schreibgeschützt“ prüft jetzt das Gegenteil: bearbeitbar, die Bedingung bleibt. Lauf auf macOS (Playwright 1.x mit Chromium Headless Shell): 105/105.

**Offen:** nichts.

## AP4a Live-Vorschau (geplant, 2026-11-11)

**Anlass:** Beim Arbeiten mit dem Editor fehlt der Blick auf das echte Formular. Die bestehende Vorschau zeigt nur gespeicherte Entwürfe (*Entwurf speichern & Vorschau*). Der SurveyJS Creator ist auch kein WYSIWYG: Um die Elemente liegen Bedienelemente. Eine Vorschau, die das Formular **so zeigt, wie es Eltern und Schüler sehen**, und trotzdem zum Bearbeiten führt, wäre näher am Ergebnis als das Original.

**Ziel:**
- **Live:** Ein Knopf *Live-Vorschau* im Editor öffnet die Vorschau in einem eigenen Fenster. Sie folgt jeder Änderung ohne Speichern. Mit großem Monitor liegt sie daneben, mit kleinem holt man sie nach vorn.
- **Vorschau → Baum:** Ein Klick auf ein Element in der Vorschau wählt es im Baum des Editors. Das gilt für Fragen, Gruppen, Seiten und Antworten mit Fragen.
- **Baum → Vorschau:** Wählt man im Baum ein Element, springt die Vorschau auf seine Seite, scrollt hin und hebt es hervor.
- **Ausgeblendete zeigen:** Ein Schalter blendet Elemente, deren Bedingung gerade nicht erfüllt ist, blass ein (SurveyJS `showInvisibleElements`). Ohne den Schalter testet man Bedingungen durch echtes Ausfüllen. Die Vorschau ist damit auch das Werkzeug für AP5.

**Entwurf:**
- **Fenster und Rahmen:** `window.open` mit festem Fensternamen je Formular, damit ein zweiter Klick dasselbe Fenster nach vorn holt. Darin liegt derselbe abgeschottete Rahmen wie heute: eine Variante von `form_preview_frame.php` mit `CSP: sandbox allow-scripts`, also undurchsichtiger Herkunft, keine Admin-Sitzung, Scripts nur per Nonce. Die Seitenleiste (‹ Seitenwahl ›) bleibt.
- **Übertragung:** Der Editor schickt bei jeder Änderung des Texts kurz verzögert (etwa 300 ms) den ganzen Text per `postMessage`. Er beobachtet dafür nicht den DOM, er kennt jede Änderung ja selbst (`text-bridge.js`).
  - Die Vorschau baut die Survey neu auf und behält dabei Seite und Eingaben (`currentPageNo`, `data`).
  - Unlesbares JSON aus dem Tab „JSON“ und Texte, die die Prüfung nicht bestehen: Der letzte gute Stand bleibt stehen, darüber ein Hinweis.
  - Absender und Ziel werden geprüft: `event.source` ist das bekannte Fenster bzw. der bekannte Rahmen. Nachrichten haben ein festes Format mit Typ und Version.
  - Nach einem Neuladen des Editors meldet sich das Fenster wieder an (Handshake `hello`).
- **Sicherheit:** Die Vorschau rendert weiterhin **nur Texte, die `SurveyValidator` akzeptiert**. Neu ist ein kleiner JSON-Endpunkt „prüfen ohne Speichern“ mit CSRF-Token und `EditorRateLimit`. Bisher ist *Prüfen* ein Formular-POST. Der Editor ruft den Endpunkt verzögert auf und gibt den Text erst danach weiter. Das kostet etwa einige hundert Millisekunden, die Schutzschichten bleiben vollständig. Zu entscheiden ist, ob der geprüfte Text vom Server in den Rahmen geht oder ob der Editor ihn nach dem Ja des Servers schickt.
- **Klick-Zuordnung:** SurveyJS schreibt Name bzw. ID des Elements ins DOM (`data-name`, `id`). Die Vorschau sucht vom Klickziel aufwärts das nächste Element und meldet dem Editor **Art und Name**, also Frage, Gruppe, Seite oder Antwort (Frage plus Wert). Pfade meldet sie nicht. Der Editor findet daraus den Pfad (`elementsOf`, Namen sind eindeutig). Ausfüllen und Auswählen schließen sich aus:
  - ein Umschalter *Auswählen* in der Vorschau-Leiste (Rahmen beim Überfahren, Klicks gehen nicht an das Formular);
  - zusätzlich Alt + Klick im Ausfüll-Modus.
- **Theme und Platzhalter:** wie in der bisherigen Vorschau, über `survey-handler-base.js` und das veröffentlichte Theme. Die Theme-Datei wird nicht live übertragen.
- **Breite Bildschirme (später, optional):** die Vorschau als dritte Spalte im Tab „Grafisch“. Das Fenster bleibt der Normalfall.

**Nicht Ziel:** Bearbeiten in der Vorschau (Texte anklicken und ändern, Ziehen in der Vorschau). Die Vorschau zeigt und wählt, bearbeitet wird im Baum und im Eigenschaftenfeld.

**Risiken:**
- Neuaufbau bei großen Surveys: messen. Bei Bedarf länger verzögern oder nur die aktuelle Seite neu aufbauen.
- Popup-Blocker: Das Fenster öffnet nur auf einen Klick.
- Rückgängig muss in der Vorschau ankommen wie jede andere Änderung. Das geht über dieselbe Textänderung.
- Fokus-Wechsel zwischen den Fenstern beim Wählen: Der Editor holt sich den Fokus nicht ungefragt.

**Abnahme:**
- Titel, Antworten, Reihenfolge, Hinzufügen, Löschen und Rückgängig erscheinen ohne Speichern in der Vorschau. Seite und Eingaben bleiben.
- Klick in der Vorschau wählt das richtige Element im Baum, auch in Gruppen und in Antworten. Auswahl im Baum zeigt es in der Vorschau.
- Ein Text, den der Server ablehnt, wird nicht gerendert.
- `check.mjs` hat einen eigenen Abschnitt; im Browser ohne Konsolenfehler und ohne CSP-Verletzung.

## AP4a-Ergebnis (2026-11-11)

**Entscheidung (offene Frage oben):** Das **Vorschaufenster** prüft. Der Editor schickt jeden Text an das Fenster (`form_preview_live.php`, gleiche Herkunft, Admin-Sitzung); das Fenster lässt ihn vom Server prüfen (`form_preview_check.php`) und reicht nach dem Ja **genau diesen Text** an den Rahmen weiter. So liegt die Prüfung an einer Stelle direkt vor dem Rendern, der Editor braucht keinen Zustand dafür, und der Text geht nur einmal an den Server (kein Echo von bis zu 512 KB).

**Umfang:**
- **Server:** `form_preview_check.php` (POST, CSRF, JSON; speichert nichts, gibt die Session-Sperre sofort frei), `SurveyPreviewController::checkText()`/`verdict()` (`SurveyValidator`, höchstens 5 Fehler mit Pfad und Text), `loadTheme()` für den Rahmen. Eigenes Rate-Limit: `EditorRateLimit` kennt Bereiche (`editor`, `preview`), die Prüfungen der Vorschau zählen getrennt (`EDITOR_PREVIEW_RATE_LIMIT_MAX`, Standard 300 je Minute) und verbrauchen nicht das Kontingent für Speichern und Veröffentlichen; 429 als JSON mit `retry_after`.
- **Rahmen:** `form_preview_frame.php?live=1` mit derselben Sandbox-Richtlinie, das Markup für beide Fälle in `inc/preview_frame.php`. `survey-preview.js` baut im Live-Modus bei jedem Text neu auf und übernimmt Antworten, Seite (nach Name, sonst Position) und Scrollposition; der Neuaufbau von `meister.json` dauert etwa 60 ms. Die Seitenleiste bleibt.
- **Fenster:** `form_preview_live.php` mit `inc/live_preview_window.php` und `live-window.js`: Status (*Verbunden*, *Keine Verbindung*), *Auswählen*, *Ausgeblendete zeigen* (`showInvisibleElements`, dazu die Fragen in nicht gewählten Antworten, siehe Nachtrag), Breite. Unlesbares JSON geht gar nicht erst an den Server; abgelehnte Texte nennen die Fehler; der letzte gute Stand bleibt stehen. Geprüfte Texte merkt sich das Fenster (die letzten 12): Rückgängig und Wiederholen kosten keine Prüfung.
- **Editor:** Knopf *Live-Vorschau ↗* neben den Tabs (`live-link.js`). `window.open` mit festem Namen je Tenant und Formular; ein bestehendes Fenster kommt ohne Neuladen nach vorn. Erkennung von Änderungen: Solange ein Fenster verbunden ist, vergleicht der Editor die Textarea alle 150 ms und sendet nach 300 ms Ruhe. Die Textarea hält immer den aktuellen Text, auch der Code-Editor schreibt sie (ohne Ereignis); so kommen Grafisch, JSON-Tab und Rückgängig auf einem Weg an, ohne Eingriff in den vorgebauten CodeMirror-Bundle.
- **Zuordnung:** `live-targets.js` (reine Logik): `targetOf(doc, path)` und `pathOf(doc, target)` mit `{kind: page|question|panel|choice, name, value|index}`. Der Rahmen bestimmt das Element über die DOM-IDs der Fragen und Gruppen von SurveyJS (`question.id`, `panel.id`), Antworten über `.sd-item` und den Wert des Eingabefelds; ein Klick auf eine einfache Antwort wählt ihre Frage. Auswahl im Baum → Rahmen: Seite wechseln, warten, bis SurveyJS sie gezeichnet hat (bis zu 20 Frames), scrollen, Rahmen. Ausgeblendete und noch nicht gezeigte Elemente (Fragen einer nicht gewählten Antwort) meldet die Vorschau als *ausgeblendet*.
- **Verbindung:** Das Fenster meldet sich alle 1,5 s beim Editor (`hello` mit einer ID je Seitenaufruf); ein neu geladener Editor findet es so wieder, ein neu geladenes Fenster bekommt den ganzen Stand. Geprüft werden Protokollname und Version, Herkunft und Absender (Editor: nur ein Fenster, dessen `opener` er ist; Fenster: nur `window.opener` und der eigene Rahmen; Rahmen: nur `window.parent`). Der Editor holt sich nie den Fokus.

**Nachgewiesen:**
- **PHP:** Unit-Tests 1369 (neu `SurveyPreviewVerdictTest`; `EditorRateLimitTest` für getrennte Bereiche; `EditorPagesSecurityTest`: Endpunkt nur POST, Token vor der Prüfung, speichert nichts, eigener Bereich vor dem Bootstrap; `PreviewAssetsTest`: Nonce an jedem Script des Rahmens, kein `allow-same-origin`, Rahmen hört nur auf `window.parent`). Integration 210 (neu: `loadTheme`, `checkText`, fremder Tenant, nichts gespeichert), im Docker-Stack gegen `anmeldung_test`.
- **JS:** 122 Tests (neu `live-targets.test.mjs`: Zuordnung in beide Richtungen, Antworten mit und ohne Fragen, Zahlwerte als Text aus dem DOM; jedes benannte Element aller Surveys hin und zurück, SurveyJS kennt es unter diesem Namen; Seiten zählen wie im Text; Protokoll).
- **Browserprüfung** (`check.mjs`, neuer Abschnitt `live`, 18 Prüfungen, insgesamt 123/123; mit dem Nachtrag 21 bzw. 126/126): Fenster öffnet und verbindet, Rahmen ohne eigene Herkunft, Titeländerung ohne Speichern bei erhaltener Seite und Eingabe, Auswahl im Baum (in Gruppe, Antwort), Hinzufügen, Verschieben, Rückgängig, Auswählen von Frage, Gruppe, Antwort mit Fragen und Seite, Alt + Klick, ausgeblendetes Element mit und ohne Schalter, abgelehnter Text nicht gerendert, unlesbarer Text ohne Serveranfrage, Rückkehr ohne neue Prüfung, Editor neu geladen, kein zweites Fenster, wenige Prüfungen, keine Konsolenfehler und keine CSP-Verletzung (Admin-Richtlinie im Fenster, Sandbox-Richtlinie im Rahmen). **Mutationsprüfung:** drei eingebaute Fehler (Rendern ohne Ja des Servers, Neuaufbau ohne Antworten und Seite, Klick auf Antwort wählt immer die Frage) schlagen alle fehl; beim ersten blockiert zusätzlich die Sandbox-Richtlinie den eingeschleusten Handler.
- **Echte Seite im Docker-Stack** (Login mit zweitem Faktor, Tenant 1, Chrome über Playwright): `ausbildernachmittag` (16 Prüfungen) und die Datenbank-Fassung von `meister` mit Antworten mit Fragen über zwei Ebenen (10 Prüfungen), dazu die bisherige Vorschau-Seite unverändert, Endpunkt antwortet auf GET mit 405 und ohne Token mit 403. Einzige Konsolenmeldung: fehlendes `favicon.ico` (betrifft das ganze Backend).

**Nicht geprüft:** andere Browser als Chrome/Chromium; Popup-Blocker mit strengen Einstellungen (das Fenster öffnet nur auf Klick; blockiert bleibt der Knopf bedienbar); Screenreader; sehr große Surveys über `meister` hinaus (Neuaufbau gemessen ~60 ms); zwei Editor-Tabs desselben Formulars gleichzeitig (gebaut ist: das Fenster bleibt bei dem Tab, der es geöffnet hat; der Knopf im zweiten Tab holt es nur nach vorn).

**Nachtrag (2026-11-12): Fragen in nicht gewählten Antworten.** *Ausgeblendete zeigen* erreichte sie nicht: SurveyJS zeigt das Panel einer Antwort nur, solange sie gewählt ist (`ItemValue.setIsPanelShowing`, gesetzt von `updateItemIsPanelShowing`), `showInvisibleElements` wirkt dort nicht. Mit dem Schalter setzt der Rahmen jetzt jede Antwort mit Fragen auf sichtbar (`_showChoicePanels` in `survey-preview.js`), nach jedem Neuaufbau und jeder Antwort erneut; ohne Schalter gilt wieder die Regel von SurveyJS. Das Panel muss dafür seine Zeilen bauen (`panelFirstRendering()`, danach `renderedIsPanelShowing = true`), sonst bleibt es leer. Die Panels nicht gewählter Antworten sind blass wie andere ausgeblendete Elemente (`.sd-item:not(.sd-item--checked) + .sd-panel`, SurveyJS setzt das Panel direkt hinter die Antwort). Damit lassen sich solche Fragen auch aus dem Baum anzeigen und in der Vorschau auswählen. Gegen SurveyJS 3.2.0 geprüft (interne API): `check.mjs`, Abschnitt `live`, +3 Prüfungen (126/126: eingeblendet und blass, aus dem Baum anzeigen, gewählte Antwort nicht blass, ausgeschaltet wieder wie SurveyJS); zwei Ebenen und Neuaufbau mit dem Rahmen-Skript direkt im Browser geprüft. Nach einem SurveyJS-Update zeigt `check.mjs`, ob das noch gilt.

**Offen (optional):** Vorschau als dritte Spalte auf breiten Bildschirmen (siehe Entwurf). ~~Die Builder-Module ohne Versionsparameter (Nebenbefund AP4) betreffen auch `live-*.js`; Lösung in AP7.~~ Gelöst 2026-11-13, siehe Nebenbefunde AP4.

## Einstellungen des Formulars (2026-11-13)

Die oberste Ebene der Survey (`title`, `completedHtml`, Knopftexte, `firstPageIsStartPage` …) war im grafischen Editor nicht erreichbar; kein Arbeitspaket sah sie vor. Alle sechs Surveys nutzen sie.

- **Aufbau:** Die erste Zeile heißt **Formular** (Pfad `[]`, Titel der Survey als Text). Bei einer Survey ohne Seiten ist sie zugleich der Container der Elemente (vorher die „implizite Seite“); mit Seiten nimmt sie keine Elemente auf (kein Drop, *Element hinzufügen* geht ans Ende der ersten Seite, *Seite hinzufügen* setzt die neue Seite an den Anfang). Verschieben, Duplizieren und Löschen gibt es für sie nicht. Prüfmeldungen zu Einstellungen der Survey (Pfad außerhalb von `pages`) hängen an dieser Zeile statt oben.
- **Registry:** `SURVEY` mit *Allgemein* (Titel, Beschreibung, `completedHtml`, `completeText`, `pageNextText`, `pagePrevText`, `startSurveyText`), *Ablauf* (`firstPageIsStartPage`, `autoFocusFirstQuestion`, `clearInvisibleValues`) und *Darstellung* (`logoPosition`, `widthMode`, `headerView`, `loadingHtml`). Typen, Standardwerte und Auswahlwerte prüft `registry.test.mjs` gegen die Library. Nicht im Panel: `calculatedValues` (als Lücke geführt, bleibt im JSON) und Theme-Reste (*Formulare → Design*).
- **Leere Knopftexte:** In SurveyJS zeigt `"completeText": ""` einen leeren Knopf, erst das Fehlen den (deutschen) Standardtext. Das neue Flag `emptyMeansDefault` lässt den Editor die Eigenschaft beim Leeren entfernen; `emptyIsAbsent` bleibt der durch die Library belegten Gleichheit vorbehalten.
- **Stelle im Text:** Neue Einstellungen stehen vor `"pages"` (bzw. `"elements"`), wo die Exporte sie führen (`insertMember(…, { before })`); vorhandene ändern sich an ihrer Stelle, auch wenn ein Creator-Export sie hinter die Seiten geschrieben hat.
- **Erläuterungen:** `help_survey.<name>` überschreibt `help.<name>`, wo eine Eigenschaft für das Formular anderes bedeutet (Titel). Das HTML-Feld nennt jetzt auch weitere Sprachen (`completedHtml` mit `de` und `default`).
- **Geprüft:** JS-Tests (176), Unit-Tests; im Browser an `meister` (Dev-Stack): Einfügen vor `pages`, Titel im Baum folgt, geleerter Knopftext verschwindet, Rückgängig stellt her, Survey ohne Seiten. `check.mjs` angepasst (Zeile „Formular“ statt impliziter Seite, neue Prüfung an `bs`), aber hier nicht gelaufen (Playwright fehlt).

## Tests

- **Round-Trip** (Pflicht, wichtigster Test): jede Datei aus `frontend/surveys/*.json` nach Laden und Zurückschreiben unverändert; zusätzlich ein Survey mit unbekannten Typen und Eigenschaften.
- **Modell:** Namenserzeugung, Kollisionen, reservierte Namen, Verschieben und Löschen mit verschachtelten Panels.
- **Server:** Die Prüfkette (`SurveyValidator`, `SurveyLinter`) bleibt die Instanz für Gültigkeit; der Editor bekommt keine eigene Gültigkeitslogik, die davon abweicht.
- **Manuell:** Tastaturbedienung, Zoom und kleine Bildschirme, Umschalten zwischen Grafisch und JSON mit ungültigem Text, Verhalten bei sehr großen Surveys.

## Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| Der Editor zerstört handgeschriebene oder fremde Surveys | Verlustfreier Round-Trip als harte Anforderung; AP0 misst ihn an allen vorhandenen Dateien; unbekannte Teile bleiben unangetastet |
| Bedingungen zu komplex für die Oberfläche | Einfacher Editor nur für das Darstellbare; sonst Text anzeigen und nicht verändern; Rückfall auf den JSON-Editor |
| Umbenannte Felder zerreißen Excel-Export und PDF | Namen stabil; Warnung beim Umbenennen; `SurveyDiff`-Bericht bleibt |
| Lizenz: Nachbau eines Editors für eine proprietäre Software | Clean-Room, nichts aus dem Creator übernehmen; Lizenzseite in AP0 geprüft; SurveyJS hat am 2026-11-13 schriftlich zugestimmt |
| Wartung bei neuen SurveyJS-Funktionen | Registry begrenzt den Umfang; unbekannte Eigenschaften bleiben erhalten; Funktionen, die die Oberfläche nicht kennt, bleiben im JSON-Editor erreichbar |
| Barrierefreiheit von Drag-and-drop | Tastatur-Alternative (Pfeile/Buttons) ab AP2; Drag-and-drop in AP4 nur als Ergänzung |
| Aufwand unterschätzt | AP0 als Durchstich mit Entscheidungspunkt: danach MVP **oder** Abbruch |

## Offene Fragen

- ~~**Lizenz:** schriftliche Bestätigung durch SurveyJS~~ erledigt: Zustimmung am 2026-11-13 unter zwei Bedingungen (MIT-Hinweis, keine SurveyJS-Marke), siehe [Antwort von SurveyJS](#antwort-von-surveyjs-2026-11-13).
- **JavaScript-Tests:** *entschieden.* Die CI ist GitLab (`.gitlab-ci.yml`, Image `php:8.2-cli`, kein Node, kein `package.json`). Modelltests laufen mit `node --test` ohne Abhängigkeiten, als eigener Job `test_js` mit `node:22-alpine` (`before_script: []`, wie `lint_php`). Kein zweites Modell in PHP.
- **Round-Trip:** Pflicht ist **Strukturgleichheit** (tiefer Vergleich) mit erhaltener Schlüsselreihenfolge. Ob auch der **Text** gleich bleibt (Einrückung, Escapes, Zeilenumbrüche), misst AP0; davon hängt ab, ob jede Änderung im Verlauf (`form_resources`) den ganzen Text umformatiert. Doppelte Schlüssel gehen beim Parsen verloren: der Editor muss sie erkennen und das Dokument ablehnen oder warnen.
- **Umfang der Eigenschaften:** Reichen die ca. 25 genutzten Eigenschaften, oder braucht die Redaktion mehr (z. B. `maxSize` bei Datei-Uploads, `rows` bei Textbereichen)? Mit der Redaktion abstimmen.
- **Mehrsprachige Texte:** Die Surveys tragen teils `{"de": …, "default": …}` (z. B. `completedHtml`, `html`). Wie zeigt der Editor das? Vorschlag: nur `default`/`de` bearbeiten, andere Sprachen unverändert lassen. Wichtig: In den vorhandenen Surveys (`bs`, `vabo`, `zq`) **unterscheiden** sich `de` und `default` inhaltlich. Angezeigt wird `default`, weil Ondisos `survey.locale` nicht setzt (3.2.0-Update: nur die Standardtexte von SurveyJS sind deutsch). Der Editor bearbeitet daher `default`, zeigt `de` schreibgeschützt und lässt es unverändert.
- **Vorschau im Editor:** *entschieden (2026-11-11).* Die bestehende Vorschau-Seite genügte für den MVP; dazu kommt eine Live-Vorschau in eigenem Fenster, siehe [AP4a](#ap4a-live-vorschau-geplant-2026-11-11).

## Übergabe an die Umsetzungs-Sitzung

Zuerst lesen: [SURVEYJS.md](../../redaktion/SURVEYJS.md), `backend/public/form_survey.php`, `backend/public/assets/survey-editor.js`, `backend/src/Controllers/SurveyEditorController.php`, `backend/src/Forms/SurveyValidator.php`, `SurveyLinter.php`, `SurveyFieldExtractor.php`, `HtmlPolicy.php`, `frontend/surveys/*.json`.
Mit **AP0** beginnen (Durchstich, Lizenz, Round-Trip-Messung); danach über MVP oder Abbruch entscheiden, bevor AP1 beginnt. Nur explizit stagen (`git add <Datei>`), neue Dateien in `docs/README.md` eintragen.
