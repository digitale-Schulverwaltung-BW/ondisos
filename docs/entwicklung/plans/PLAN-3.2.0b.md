# PLAN 3.2.0b – Theme-Editor im Backend

**Status:** in Arbeit (Stand 2026-11-12); [AP0](#ap0-ergebnis-2026-11-12) und [AP1](#ap1-ergebnis-2026-11-12) erledigt, als Nächstes AP2. Gehört zum Formular-Release 3.2.0 ([PLAN-3.2.0.md](PLAN-3.2.0.md)), ist davon aber unabhängig umsetzbar; es nutzt nur die Live-Vorschau aus AP4a.
Die Aufwände sind Schätzungen für eine Person; **AP0** soll sie belegen oder korrigieren.

## Ausgangslage

- Das Theme (Farben, Schrift, Kartenstil) liegt seit 3.1 pro Tenant in `form_resources` (`kind = 'theme'`). Die Formular-Konfiguration verweist per Name darauf (`"theme": "survey_theme.json"`); neue Formulare bekommen diesen Namen automatisch (`FormPublishService::DEFAULT_THEME`).
  Ein Theme gilt also für **alle Formulare einer Schule, die diesen Namen nutzen**.
- Ins Backend kommt ein Theme heute nur per Kommandozeile: `import-surveys.php` (ganzes Verzeichnis; geänderte Dateien nur mit `--overwrite`, sonst still übersprungen; im Docker-Betrieb erst `docker compose cp`) oder `copy-forms.php` von einem anderen Tenant.
  Die Anleitung dazu steht nur in [MIGRATION-3.1.md](../../betreiber/MIGRATION-3.1.md) und im Fallback-Abschnitt von [SURVEYJS.md](../../redaktion/SURVEYJS.md#surveys-als-dateien-fallback). Eine Schule kann ihre Farben nicht selbst ändern.
- Liegt kein Theme in der Datenbank, nimmt das Frontend die Datei `frontend/surveys/<name>` (Fallback); die Backend-Vorschau zeigt dann einen Hinweis und kein Theme.
- **Vorhanden und wiederverwendbar:**
  - `ThemeValidator` (JSON-Objekt, höchstens 256 KB, kein `<`, keine `javascript:`/`data:`-URLs, Warnung bei externen Ressourcen),
  - `FormResourceRepository::save()` mit optimistischer Sperre (`$expectedSha`),
  - `form_revisions` kennt die Art `theme` bereits, wird dafür aber nirgends beschrieben (die Historie ist pro `form_key` angelegt),
  - `FormDeliveryService::bundle()` nimmt den SHA-256 des Themes in den ETag auf: Eine Änderung erreicht das Frontend ohne weiteres Zutun,
  - Vorschau und Live-Vorschau (AP4a) mit Sandbox-Frame; der Frame lädt das veröffentlichte Theme heute serverseitig (`SurveyPreviewController::loadTheme()`).
- **SurveyJS 3.2:** Das Theme-Format hat sich geändert (`--sjs2-*`, rund 1700 Variablen im CSS). `applyTheme` übersetzt alte `--sjs-*`-Variablen (z. B. `--sjs-primary-backcolor` → `--sjs2-color-project-brand-600` u. a.), Titel, Kartenstil und Hintergrund folgen aber den neuen Standardwerten ([TODO.md](../TODO.md)).

## Ziel

Schul-Admins und Plattform-Admins ändern das Theme ihrer Schule **im Backend ohne Kommandozeile**: übliche Einstellungen als Formularfelder, alles andere als JSON; prüfen, in der Vorschau mit einem echten Formular ansehen, veröffentlichen, bei Bedarf aus dem Verlauf wiederherstellen.
Danach ist `import-surveys.php` für Themes nur noch Migrationswerkzeug, und die Doku beschreibt den Weg im Backend.

**Nicht Ziel:** ein Nachbau des SurveyJS Theme Editors (proprietär, siehe [SURVEYJS.md](../../redaktion/SURVEYJS.md#lizenzhinweis)); alle 1700 Variablen als Felder; Zuordnung Formular → Theme ändern (bleibt Konfiguration, nur Plattform-Admins); Hochladen von Schriften oder Bildern; Löschen von Themes.

## Entscheidungen

1. **Felder plus JSON (entschieden 2026-11-12).** Oben Felder für das Übliche, darunter das vollständige JSON. Das JSON ist die einzige Quelle: Die Felder lesen daraus und schreiben hinein; Schlüssel, die die Felder nicht kennen, bleiben unverändert (gleiche Regel wie beim Survey-Editor: unbekannte Teile nicht anfassen).
   So lässt sich auch ein Theme aus dem SurveyJS Theme Editor (Website) einfügen und danach mit den Feldern nachjustieren.
2. **Auch Schul-Admins dürfen den Inhalt ändern (entschieden 2026-11-12).** Ein Theme enthält keine Logik, der `ThemeValidator` blockiert HTML und Skript-URLs. Welches Theme ein Formular nutzt (`theme` in der Konfiguration), bleibt `editableBy => platform`.
3. **Kein gespeicherter Entwurf (entschieden 2026-11-12).** Ablauf: bearbeiten → *Prüfen* → *Vorschau* mit dem ungespeicherten Stand → *Veröffentlichen*. Den Rückweg sichert der Verlauf. Keine neue Tabelle, kein `form_drafts`-Umbau.
   Ungespeicherte Änderungen schützt die Seite wie der Survey-Editor (Warnung beim Verlassen).
4. **Bearbeitet wird ein Theme, nicht ein Formular.** Eigene Seite `themes.php` (Liste) und `theme_edit.php?name=…`, erreichbar von *Formulare*. Die Liste zeigt die Theme-Namen des Tenants (aus `form_resources` und aus den Konfigurationen) und je Theme die Formulare, die es nutzen. Vor *Veröffentlichen* nennt die Seite diese Formulare („Die Änderung betrifft: bs, vabo, zq“).
5. **Neue Themes entstehen über die Konfiguration.** Ein Plattform-Admin trägt einen neuen Namen bei einem Formular ein; das Theme erscheint dann in der Liste als „noch nicht angelegt“ und startet beim Bearbeiten mit der eingebauten Vorgabe (6). Kein eigener Anlege-Dialog.
6. **Eingebaute Vorgabe im Backend.** Der Inhalt des heutigen `frontend/surveys/survey_theme.json` kommt als Vorgabe ins Backend (der Container sieht `frontend/` nicht). Sie dient als Startpunkt für noch nicht angelegte Themes und für *Auf Vorgabe zurücksetzen*.
   Die Vorgabe nutzt das SurveyJS-3-Format und gibt das Aussehen von 2.3.15 wieder ([AP0-Ergebnis](#ap0-ergebnis-2026-11-12)); sie liegt in `backend/templates/theme/default.json` (AP1).
7. **Verlauf in `form_revisions` mit Pseudo-Schlüssel.** Theme-Revisionen bekommen `form_key = '@theme'`, `kind = 'theme'`, `name = <Theme-Name>`. `@` ist in Formular-Schlüsseln nicht erlaubt (`Identifiers::isValidFormKey()`), es gibt also keine Kollision und keine Migration.
   `latest()`, `list()` und `pruneOldest()` bekommen einen optionalen Namensfilter, damit die 50 Revisionen je Theme gelten, nicht für alle Themes zusammen. Wie bei Surveys wird ein Altbestand (z. B. importiert) vor der ersten Änderung als „Stand vor der Änderung“ gesichert.
8. **Optimistische Sperre.** Die Seite merkt sich den SHA-256 des geladenen Themes; *Veröffentlichen* übergibt ihn an `FormResourceRepository::save($expectedSha)`. Hat jemand anderes inzwischen veröffentlicht, gibt es eine Konfliktmeldung statt Überschreiben (wie `ConflictException` im Survey-Editor).
9. **Vorschau nur mit vom Server geprüftem Inhalt.** Wie bei AP4a: Die Live-Vorschau schickt den Theme-Text an einen Prüf-Endpunkt, erst nach `ok` geht er per `postMessage` an den Sandbox-Frame, der ihn mit `survey.applyTheme()` anwendet. Angezeigt wird die veröffentlichte Survey eines Formulars, das dieses Theme nutzt (Auswahl, wenn es mehrere sind).
   Der Frame bleibt `sandbox allow-scripts` ohne eigene Origin.
10. **Logik im Service, nicht in der Seite.** Neuer `ThemeService` (oder Abschnitt in `FormPublishService`): `check`, `publish`, `restore`, `usage` (welche Formulare), alles tenant-gefiltert über die vorhandenen Repositories und unit-testbar. Die Feld-Zuordnung (Theme-JSON ↔ Felder) ist reine Logik in JavaScript mit `node --test`.
11. **Audit:** `theme_published`, `theme_rolled_back`, `theme_rejected` (abgelehnter Inhalt: nur Anzahl und Pfade der Fehler, nie der Inhalt, wie `form_survey_rejected`).
12. **Texte über den `MessageService`**, Hilfe-Zuordnung in `HelpLinks` für die neuen Seiten.

## Felder (festgelegt in AP0)

Die Felder schreiben nur `--sjs2-*`-Variablen. Steht im JSON noch die alte Variable (`--sjs-*`), zeigt das Feld deren Wert und ersetzt sie beim Ändern durch die neue (alte Schlüssel entfernen, neue setzen).

| Feld | Theme-Eigenschaft | Bemerkung |
|---|---|---|
| Markenfarbe | `--sjs2-color-project-brand-600` | Farbwähler plus Textfeld. Alle Brand-Töne (Buttons, Fokus, Auswahl, Hover) leitet SurveyJS daraus ab (`hsl(from …)`) |
| Titel in Markenfarbe | `--sjs2-color-component-survey-header-default-title` = `var(--sjs2-color-project-brand-600)` | Schalter; aus ⇒ Schlüssel entfernen (Standard von 3.2: dunkelgrau) |
| Schrift | `--sjs2-typography-font-family-text` | Auswahl *Open Sans (mitgeliefert)* oder *Systemschrift* (`system-ui, sans-serif`), dazu eigene Angabe mit Hinweis. Ein Theme kann keine Schrift laden (kein `@font-face`), und die Frontend-CSP erlaubt nur `font-src 'self' data:` |
| Schriftgröße | `--sjs2-base-unit-font-size` und `--sjs2-base-unit-line-height` | Je **halbe** Schriftgröße (16 px ⇒ `8px`), so übersetzt auch `applyTheme` das alte `--sjs-font-size`. Auswahl 14–20 px |
| Hintergrund | `--sjs2-color-utility-surface-survey` | Farbe hinter den Karten. Ohne Angabe ist er in 3.2 fest türkis (`#EDF9F7`), unabhängig von der Markenfarbe; die Vorgabe setzt `#F3F3F3` |
| Hintergrundbild | `backgroundImage`, `backgroundOpacity`, `backgroundImageFit` | Nur URL; externe URL ⇒ vorhandene Datenschutz-Warnung. In 3.2 geprüft: wirkt |
| Ecken | `--sjs2-base-unit-radius`, `--sjs2-radius-component-panel`, `…-action`, `…-formbox` | Ein Wert für alle vier (Vorgabe `4px`, Standard von 3.2: Karten 12 px) |
| Fragen als Karten | `isPanelless` und Kartenschatten | Aus ⇒ `isPanelless: true` **und** die Schatten-Variablen entfernen: Der Vorgabe-Schatten auf `…-panel-simple-default` würde sonst auch ohne Karten stehen bleiben (in AP0 beobachtet) |

**Entfällt:** *Hell/Dunkel* (`colorPalette`). In 3.2 ändert `colorPalette: "dark"` allein nichts; Dunkel-Themes bringt SurveyJS nur als eigenes Paket mit vollständigem Variablensatz. Wer sie will, fügt ein solches Theme als JSON ein.

Felder, deren Wert im JSON in einer Form steht, die die Oberfläche nicht darstellen kann (z. B. `var(…)` bei der Markenfarbe), werden schreibgeschützt mit Hinweis „im JSON bearbeiten“ angezeigt.

## Arbeitspakete

| AP | Inhalt | Aufwand | Abnahme |
|---|---|---|---|
| **AP0** Klärung | Welche `--sjs2-*`-Variablen entsprechen Markenfarbe, Schrift, Größe, Hintergrund, Titelfarbe, Kartenradius; wie mischt `applyTheme` alte und neue Variablen; Vorgabe-Theme im 3.2-Format, das dem bisherigen Aussehen nahekommt; welche Schriften die Frontend-CSP zulässt. Felderliste festschreiben. | 2–3 Tage | Felderliste mit Variablen; Vorgabe-Theme sieht in der Vorschau aus wie das heutige Formular (Screenshot-Vergleich). **Erfüllt**, siehe [AP0-Ergebnis](#ap0-ergebnis-2026-11-12) |
| **AP1** Service und Verlauf | `ThemeService` (check, publish mit Sperre, restore, usage), Namensfilter in `FormRevisionRepository`, Vorgabe-Theme, Audit-Ereignisse | 3–4 Tage | Unit-Tests: Veröffentlichen schreibt Ressource und Revision, Konflikt bei veraltetem SHA, ungültiger Inhalt schreibt nichts, Restore prüft erneut, fremder Tenant ⇒ nicht gefunden, Prune je Name. **Erfüllt**, siehe [AP1-Ergebnis](#ap1-ergebnis-2026-11-12) |
| **AP2** Seiten (JSON) | `themes.php` (Liste mit Nutzung, Status „noch nicht angelegt“), `theme_edit.php` mit JSON-Feld, *Prüfen*, *Veröffentlichen*, Verlauf mit *Wiederherstellen*, Hinweis auf betroffene Formulare, Link von *Formulare*; Rechte für beide Rollen | 1 Woche | Theme im Backend geändert ⇒ Frontend zeigt die neue Farbe (ETag); Schul-Admin sieht nur Themes seiner Schule |
| **AP3** Vorschau | Prüf-Endpunkt für Theme-Text, Nachricht `theme` im Frame-Protokoll (`survey-preview.js`), Formularauswahl; `sync-preview-assets.sh` berücksichtigen | 3–4 Tage | Ungespeicherte Änderung erscheint in der Vorschau; ungültiger Text erreicht den Frame nicht; `check.mjs`-Abschnitt grün |
| **AP4** Felder | Feld-Zuordnung in JavaScript (lesen, schreiben, unbekannte Schlüssel behalten), Felder über dem JSON, synchron in beide Richtungen | 1 Woche | `node --test`: Round-Trip aller Themes in `frontend/surveys/` und eines Themes aus dem SurveyJS Theme Editor unverändert; Feldänderung ändert nur die zugehörigen Schlüssel |
| **AP5** Doku und Abschluss | [SURVEYJS.md](../../redaktion/SURVEYJS.md): neuer Abschnitt „Design (Theme) ändern“; MIGRATION-3.1 und Fallback-Abschnitt verweisen für Themes darauf (Import nur noch für die Übernahme); TODO-Eintrag „Theme im SurveyJS-3-Format“ aktualisieren; `HelpLinks`, Meldungstexte (`messages.php`, `messages.example.php`), Changelog, manuelle Tests | 2–3 Tage | `DocsLinksTest` und `HelpLinksTest` grün; manuelle Testliste durchlaufen |

**Kern: AP0–AP2** (etwa 2 Wochen; AP0 und AP1 erledigt) beseitigt die Kommandozeile. **AP3–AP4** machen es für die Redaktion bequem (weitere 1,5–2 Wochen). Die Doku (AP5) wird erst am Ende geschrieben, damit sie nur einmal angefasst wird.

## AP0-Ergebnis (2026-11-12)

Gemessen an `bs.json` mit dem heutigen `survey_theme.json`, gerendert einmal mit SurveyJS 2.3.15 (Stand vor `c908ded`) und einmal mit 3.2.0, jeweils mit lokalem Open Sans wie im Frontend; verglichen per Screenshot und berechneten Stilen (`getComputedStyle`).

**Wie `applyTheme` alte Themes übersetzt:** SurveyJS 3.2 hat eine feste Tabelle `--sjs-*` → `--sjs2-*` (z. B. `--sjs-primary-backcolor` → `--sjs2-color-project-brand-600` und einige Komponentenfarben, `--sjs-font-family` → `--sjs2-typography-font-family-text`, `--sjs-corner-radius` → `--sjs2-base-unit-radius`).
`--sjs-font-size` wird halbiert zu `--sjs2-base-unit-font-size`/`-line-height`. Die alten Schlüssel entfernt `applyTheme`; steht dieselbe `--sjs2-*`-Variable auch direkt im Theme, gewinnt die direkte Angabe. Mischen ist also möglich, die Felder schreiben trotzdem nur `--sjs2-*`.

**Unterschiede 2.3.15 → 3.2.0 mit dem heutigen Theme:**

| | 2.3.15 | 3.2.0 ohne Anpassung |
|---|---|---|
| Titel | Markenfarbe, 32 px, fett (700) | dunkelgrau, 28 px, 600 |
| Hintergrund hinter den Karten | `#F3F3F3` | `#EDF9F7` (türkis, fest) |
| Karten | Radius 4 px, weicher Schatten `0 1px 2px rgba(0,0,0,.15)` | Radius 12 px, grauer 1-px-Rahmen |
| Start-Button | Radius 4 px, weicher Schatten | Radius 8 px, grauer Rahmen |
| Eingabefelder | Innenschatten ohne Rahmen | sichtbarer Rahmen (besser erkennbar; bleibt so) |

**Vorgabe-Theme im 3.2-Format** (gleicht alles außer den Eingabefeldern aus; `ThemeValidator` ohne Fehler und Warnungen; mit anderer Markenfarbe geprüft: Titel und Linie unter dem Kopf folgen):

```json
{
    "themeName": "default",
    "colorPalette": "light",
    "isPanelless": false,
    "backgroundImage": "",
    "backgroundImageFit": "cover",
    "backgroundImageAttachment": "scroll",
    "backgroundOpacity": 1,
    "cssVariables": {
        "--sjs2-color-project-brand-600": "rgba(0, 163, 215, 1)",
        "--sjs2-typography-font-family-text": "Open Sans",
        "--sjs2-base-unit-font-size": "8px",
        "--sjs2-color-utility-surface-survey": "#F3F3F3",
        "--sjs2-color-component-survey-header-default-title": "var(--sjs2-color-project-brand-600)",
        "--sjs2-typography-font-size-component-survey-header-title": "32px",
        "--sjs2-typography-font-weight-component-survey-header-title": "700",
        "--sjs2-base-unit-radius": "4px",
        "--sjs2-radius-component-panel": "4px",
        "--sjs2-radius-component-action": "4px",
        "--sjs2-radius-component-formbox": "4px",
        "--sjs2-border-effect-component-panel-default": "0px 1px 2px 0px rgba(0, 0, 0, 0.15)",
        "--sjs2-border-effect-component-panel-simple-default": "0px 1px 2px 0px rgba(0, 0, 0, 0.15)",
        "--sjs2-border-effect-component-action-brand-tertiary-surface-default": "0px 1px 2px 0px rgba(0, 0, 0, 0.15)"
    },
    "headerView": "basic"
}
```

Titelgröße, Titelgewicht und Schatten haben bewusst kein eigenes Feld (bleiben im JSON). `frontend/surveys/survey_theme.json` bleibt vorerst im alten Format (Fallback für Frontends ohne Backend-Theme); mit AP5 kann es durch die Vorgabe ersetzt werden.

**Schriften:** Frontend (`ContentSecurityPolicy`: `font-src 'self' data:`) und Plugin (`class-assets.php`) binden nur Open Sans 400 und 700 lokal ein. Externe Webfonts sind über ein Theme also gar nicht möglich; das Feld bietet deshalb nur Open Sans, Systemschrift und eine eigene Angabe (installierte Schrift).

**Aufwand:** AP0 bestätigt die Schätzungen. AP4 wird eher kleiner (acht Felder, kein Hell/Dunkel); dafür braucht AP4 die Sonderregeln oben (Halbierung der Schriftgröße, Schatten bei „ohne Karten“, alte Schlüssel ersetzen).

## AP1-Ergebnis (2026-11-12)

**Umgesetzt:**
- `App\Services\ThemeService`: `themes()` (gespeicherte und von Formularen genannte Namen, je mit den nutzenden Formularen), `load()` (nicht gespeicherte Themes mit der Vorgabe), `usage()`, `check()`, `publish()`, `revisions()`, `restoreRevision()`, `defaultTheme()`.
  Namen, die weder gespeichert noch von einem Formular genannt sind, gelten als nicht gefunden (auch beim Veröffentlichen): Neue Themes entstehen weiter über die Konfiguration.
- **Sperre:** `publish()` bekommt den SHA-256 vom Laden, `null` heißt „gab es noch nicht“. Weicht der Stand ab (auch: inzwischen von jemand anderem angelegt), folgt `ConflictException`; das Ganze läuft in einer Transaktion mit `SELECT … FOR UPDATE`. Unveränderter Inhalt schreibt keine Revision.
- **Verlauf:** `FormRevisionRepository::THEME_KEY = '@theme'`, `latest()`, `list()` und `pruneOldest()` mit optionalem Namen, `keepThemeState()` (alten Stand sichern, wenn er nicht schon die neueste Revision ist). 50 Revisionen je Theme.
- **Audit:** `AuditLogger::themeEvent()` mit `theme_published`, `theme_rolled_back`, `theme_rejected` (nur Anzahl und Pfade der Fehler).
- **Vorgabe-Theme** aus AP0 in `backend/templates/theme/default.json` (das Docker-Image kopiert `backend/` vollständig).
- **Import und Kopieren:** `import-surveys.php --overwrite` und `copy-forms.php --overwrite` ersetzten ein Theme bisher ohne Spur. Jetzt sichern beide den alten Stand mit `keepThemeState()` („Stand vor dem Import“ bzw. „Stand vor dem Kopieren“), damit eine im Editor gepflegte Fassung wiederherstellbar bleibt.

**Tests:** `tests/Integration/Forms/ThemeServiceTest.php` (16 Tests: Liste, Laden mit Vorgabe, unbekannte Namen, Anlegen, Altbestand im Verlauf, Konflikt bei veraltetem und bei fehlendem SHA, Ablehnung ohne Inhalt im Audit, unveränderter Inhalt, Restore, fremde Revisionen, Prune je Name, Tenant-Trennung), je ein Test in `SurveyImportServiceTest` und `FormCopyServiceTest`, `tests/Unit/Forms/DefaultThemeTest.php`.
Gegenprobe: Ohne Konfliktprüfung schlagen die beiden Konflikttests fehl. Unit-Suite (1371) und Integration-Suite (228) grün.

**Lokal ausführen:** Die eingecheckte `backend/.env.test` meldet sich als `root` an; gegen die Docker-MySQL des Entwicklungsstacks klappt das nur mit dem Benutzer `test`. Seit AP1 lädt der Test-Bootstrap dafür eine optionale `backend/.env.test.local` ([tests.md](../tests.md)).

## Tests

- **Unit (PHP):** `ThemeService` wie in AP1; `ThemeValidator` bleibt die einzige Instanz für Gültigkeit, die Seite hat keine eigene Prüflogik.
- **JavaScript:** Feld-Zuordnung mit Round-Trip (Strukturgleichheit, Schlüsselreihenfolge erhalten).
- **Browser:** `check.mjs` um den Theme-Editor erweitern (Vorschau folgt einer Farbänderung).
- **Manuell:** zwei Admins veröffentlichen gleichzeitig (Konflikt); Schul-Admin ohne Zugriff auf andere Tenants; Theme mit externer Schrift (Warnung); WordPress-Plugin zeigt die neue Farbe.

## Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| Eine Änderung trifft unbemerkt mehrere Formulare | Liste der betroffenen Formulare vor *Veröffentlichen*; Verlauf mit *Wiederherstellen* |
| SurveyJS-3-Variablen ändern sich mit Updates | Wenige Felder; alles andere im JSON; AP0 dokumentiert die Zuordnung; `sync-preview-assets.sh` nach Updates |
| Felder zerstören ein importiertes Theme | JSON ist die Quelle, unbekannte Schlüssel bleiben; Round-Trip-Test in AP4 |
| Externe Schriften oder Bilder (Datenschutz, CSP) | Vorhandene Warnung des `ThemeValidator`; Doku empfiehlt lokale Schriften |
| Unleserliche Farben (Kontrast) | Optional: Kontrasthinweis bei Markenfarbe auf Weiß (WCAG AA), nur Warnung |

## Offene Fragen

- **Kontrastprüfung:** Warnung bei zu schwachem Kontrast der Markenfarbe? Vorschlag: ja, nur als Hinweis (in AP4).
- **Pseudo-Schlüssel `@theme` oder eigene Spalte:** Der Pseudo-Schlüssel spart eine Migration. Falls später auch Surveys unabhängig vom Formular versioniert werden sollen, wäre eine Spalte `resource_name` sauberer.
- **Ungenutzte Themes:** Löschen erlauben, wenn kein Formular sie nutzt? Vorerst nein.
- **`copy-forms.php`:** *geklärt (AP1).* Ohne `--overwrite` bleibt ein abweichendes Theme der Zielschule erhalten (`kept`), mit `--overwrite` wird es ersetzt; der alte Stand kommt seit AP1 in den Theme-Verlauf. Für die Betreiber-Doku in AP5 vormerken.

## Übergabe an die Umsetzungs-Sitzung

Zuerst lesen: `backend/src/Forms/ThemeValidator.php`, `backend/src/Services/FormPublishService.php` (Muster für Veröffentlichen, Verlauf, Sperre), `FormResourceRepository.php`, `FormRevisionRepository.php`, `SurveyPreviewController.php`, `backend/public/form_survey.php`, `form_preview_live.php`, `form_preview_check.php`, `form_preview_frame.php`, `assets/preview/survey-preview.js`, `frontend/surveys/survey_theme.json`.
Mit **AP0** beginnen. Nur explizit stagen (`git add <Datei>`), neue Dateien in `docs/README.md` eintragen.
