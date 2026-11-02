# PLAN 3.2.0 – Grafischer Survey-Editor im Backend

**Status:** Entwurf (Stand 2026-10-12); AP0 läuft. Voraussetzung erfüllt: die SurveyJS-Library ist auf 3.2.0 aktualisiert (siehe [changelog.md](../changelog.md)), der Editor arbeitet gegen diese Version. Eigenes Release **3.2.0**, weitgehend unabhängig von den übrigen Vorhaben (Passkeys 3.1.3, WordPress-Plugin ohne Shell, SMTP).
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

**Empfehlung, offen:** schriftliche Anfrage an SurveyJS (Support/Sales): *„Ondisos ist eine freie Schulanmeldung. Wir planen einen eigenen, quelloffenen Formular-Editor, der ausschließlich das offene SurveyJS-JSON erzeugt, ohne Code des Creators. Ist das zulässig?"* Die Antwort hier eintragen. Bis dahin ist AP0 (Spike) unbedenklich, vor einer Veröffentlichung des Editors sollte sie vorliegen.

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
| **AP4** Komfort | Drag-and-drop, Verschachteln in Panels, Duplizieren, Undo/Redo | 2–3 Wochen | Reihenfolge per Maus und Tastatur; Undo stellt den Text exakt wieder her |
| **AP5** Einfache Bedingungen | `visibleIf`/`requiredIf` als „Feld – Operator – Wert" (ein oder mehrere Ausdrücke mit UND/ODER); alles Komplexere bleibt als Text sichtbar und **unverändert** (nur lesbar anzeigen) | 3–4 Wochen | Von den 55 Bedingungen der vorhandenen Surveys wird der Anteil, den die Oberfläche darstellen kann, gemessen und dokumentiert; der Rest bleibt unangetastet |
| **AP6** Vorlagen | Neues Formular aus Vorlage („Anmeldung", „Rückmeldung ohne E-Mail"), Bausteine für Standardfelder (Name, E-Mail, Datenschutz-Zustimmung `consent_…`, Unterschrift) | 1–2 Wochen | Neue Vorlage besteht *Prüfen* ohne Warnung |
| **AP7** Doku und Abschluss | Redaktions-Doku neu ordnen ([SURVEYJS.md](../../redaktion/SURVEYJS.md)), `HelpLinks`-Ziele, Meldungstexte (`messages.php`, auch `messages.example.php`), Changelog, Release Notes, manuelle Tests, Version 3.2.0 an den vier Stellen | 1 Woche | `DocsLinksTest` und `HelpLinksTest` grün; manuelle Testliste durchlaufen |

**Kern (MVP): AP0–AP3**, etwa **6–10 Wochen**. **AP4–AP7** machen daraus ein gut nutzbares Werkzeug (zusammen weitere **2–4 Monate**). AP5 ist der unsicherste Teil und kann bei Bedarf zuerst entfallen; Bedingungen bleiben dann im JSON-Editor.

## AP0-Ergebnis (2026-10-12)

**Lizenz:** geprüft, siehe [Lizenz und Clean-Room](#lizenz-und-clean-room); die schriftliche Bestätigung von SurveyJS steht aus.

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

**Offen:** Rückgängig/Wiederholen im Builder selbst und Drag-and-drop (AP4; bis dahin hilft Strg+Z im JSON-Tab), Bedingungen als einfache Auswahl (AP5), Vorlagen und Bausteine (AP6), Redaktions-Doku und Hilfe-Links (AP7).

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
| Lizenz: Nachbau eines Editors für eine proprietäre Software | Clean-Room, nichts aus dem Creator übernehmen; Lizenzseite in AP0 prüfen |
| Wartung bei neuen SurveyJS-Funktionen | Registry begrenzt den Umfang; unbekannte Eigenschaften bleiben erhalten; Funktionen, die die Oberfläche nicht kennt, bleiben im JSON-Editor erreichbar |
| Barrierefreiheit von Drag-and-drop | Tastatur-Alternative (Pfeile/Buttons) ab AP2; Drag-and-drop in AP4 nur als Ergänzung |
| Aufwand unterschätzt | AP0 als Durchstich mit Entscheidungspunkt: danach MVP **oder** Abbruch |

## Offene Fragen

- **Lizenz:** geprüft, siehe [Lizenz und Clean-Room](#lizenz-und-clean-room); offen ist die schriftliche Bestätigung durch SurveyJS.
- **JavaScript-Tests:** *entschieden.* Die CI ist GitLab (`.gitlab-ci.yml`, Image `php:8.2-cli`, kein Node, kein `package.json`). Modelltests laufen mit `node --test` ohne Abhängigkeiten, als eigener Job `test_js` mit `node:22-alpine` (`before_script: []`, wie `lint_php`). Kein zweites Modell in PHP.
- **Round-Trip:** Pflicht ist **Strukturgleichheit** (tiefer Vergleich) mit erhaltener Schlüsselreihenfolge. Ob auch der **Text** gleich bleibt (Einrückung, Escapes, Zeilenumbrüche), misst AP0; davon hängt ab, ob jede Änderung im Verlauf (`form_resources`) den ganzen Text umformatiert. Doppelte Schlüssel gehen beim Parsen verloren: der Editor muss sie erkennen und das Dokument ablehnen oder warnen.
- **Umfang der Eigenschaften:** Reichen die ca. 25 genutzten Eigenschaften, oder braucht die Redaktion mehr (z. B. `maxSize` bei Datei-Uploads, `rows` bei Textbereichen)? Mit der Redaktion abstimmen.
- **Mehrsprachige Texte:** Die Surveys tragen teils `{"de": …, "default": …}` (z. B. `completedHtml`, `html`). Wie zeigt der Editor das? Vorschlag: nur `default`/`de` bearbeiten, andere Sprachen unverändert lassen. Wichtig: In den vorhandenen Surveys (`bs`, `vabo`, `zq`) **unterscheiden** sich `de` und `default` inhaltlich. Angezeigt wird `default`, weil Ondisos `survey.locale` nicht setzt (3.2.0-Update: nur die Standardtexte von SurveyJS sind deutsch). Der Editor bearbeitet daher `default`, zeigt `de` schreibgeschützt und lässt es unverändert.
- **Vorschau im Editor:** Seitenweise Live-Vorschau neben dem Editor oder weiter die bestehende Vorschau-Seite? Die bestehende genügt für den MVP.

## Übergabe an die Umsetzungs-Sitzung

Zuerst lesen: [SURVEYJS.md](../../redaktion/SURVEYJS.md), `backend/public/form_survey.php`, `backend/public/assets/survey-editor.js`, `backend/src/Controllers/SurveyEditorController.php`, `backend/src/Forms/SurveyValidator.php`, `SurveyLinter.php`, `SurveyFieldExtractor.php`, `HtmlPolicy.php`, `frontend/surveys/*.json`.
Mit **AP0** beginnen (Durchstich, Lizenz, Round-Trip-Messung); danach über MVP oder Abbruch entscheiden, bevor AP1 beginnt. Nur explizit stagen (`git add <Datei>`), neue Dateien in `docs/README.md` eintragen.
