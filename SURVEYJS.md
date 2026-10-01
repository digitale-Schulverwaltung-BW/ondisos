# 🎨 SurveyJS-basierte Formular-Erstellung und -Anpassung

## 📋 Inhaltsverzeichnis

- [Aufruf des Formular-Designers](#aufruf-des-formular-designers-von-surveyjs)
- [Workflow](#workflow)
  - [Workflow als Video](#workflow-als-video)
  - [Neues Formular erstellen](#neues-formular-erstellen)
- [Zusammenhang: Formular-Konfiguration ↔ surveys/](#zusammenhang-formular-konfiguration--surveys)

## Aufruf des Formular-Designers von SurveyJS

Der SurveyJS-Editor ist erreichbar über
https://surveyjs.io/create-free-survey

![SurveyJS Editor](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/-/wikis/uploads/0f3bdfcf9e5b4f71db8f8875ac7dd595/Bildschirmfoto_2026-02-27_um_09.56.58.png)

## Workflow
Der Workflow sieht folgendermaßen aus:

(Formular-JSON-Daten kopieren &rarr; im Editor unter **JSON Editor** einfügen)
&rarr; mit dem **Designer** bearbeiten &rarr; die JSON-Daten unter **JSON-Editor** kopieren
&rarr; in ondisos ablegen, entweder als neues Formular unter frontend/surveys oder
das bestehende Formular überschreiben.

```
┌────────────────────────────────────────────────────────────────────┐
│                    SurveyJS Bearbeitungs-Workflow                  │
└────────────────────────────────────────────────────────────────────┘

  BESTEHENDES FORMULAR                NEUES FORMULAR
  ─────────────────────               ──────────────
  frontend/surveys/*.json             (Vorlage leer)
            │                               │
            └───────────────┬───────────────┘
                    JSON kopieren (oder neu starten)
                             │
                             ▼
               ┌─────────────────────────────┐
               │   surveyjs.io/              │
               │   create-free-survey        │
               │                             │
               │   ┌─────────────────────┐   │
               │   │      Designer       │   │  ← Felder per Drag & Drop
               │   └──────────┬──────────┘   │
               │              ↕ live-sync    │
               │   ┌─────────────────────┐   │
               │   │    JSON Editor      │   │  ← JSON einfügen / kopieren
               │   └─────────────────────┘   │
               └─────────────┬───────────────┘
                             │
                      JSON kopieren
                             │
             ┌───────────────┴────────────────┐
             │                                │
     Formular vorhanden?               Neues Formular
             │                                │
             ▼                                ▼
    frontend/surveys/               ① frontend/surveys/neu.json anlegen
    *.json überschreiben            ② Formular-Konfiguration anlegen (Backend)
                                    ③ Shortcode einbetten:
                                       [ondisos form="neu"]
```

### Workflow als Video
![ondisos-editor-workflow-3](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/-/wikis/uploads/02b75dfede029d8dc511ed466734d678/ondisos-editor-workflow-3.mp4){width=1280 height=720}

### Neues Formular erstellen
Wenn ein neues Formular mit neuem Einbettungs-Code (in Wordpress: Shortcode ```[ondisos form="neu"]```) erstellt werden soll, muss dies noch als Formular-Konfiguration im Backend angelegt werden: Sie liegt in der Datenbank (Tabelle `form_configs`). Die Vorlage [frontend/config/forms-config-dist.php](frontend/config/forms-config-dist.php) zeigt alle Optionen; einspielen mit `php backend/seed-forms.php [--tenant=<slug>]` (fügt neue Formulare hinzu, überschreibt vorhandene nie, prüft jeden Eintrag) oder per SQL — siehe [MIGRATION-3.0.md § 6](MIGRATION-3.0.md#6-danach-formular-konfiguration-ändern). Die `.json`-Datei selbst (`frontend/surveys/neu.json`) liegt weiterhin im Frontend; alternativ lässt sie sich mit `php backend/import-surveys.php [--tenant=<slug>] <verzeichnis>` in die Datenbank des Backends übernehmen (siehe [MIGRATION-3.1.md](MIGRATION-3.1.md)) — dann braucht das Frontend die Datei nicht mehr.

---

## Zusammenhang: Formular-Konfiguration ↔ surveys/

Der Schlüssel der Formular-Konfiguration (`form_key`) bestimmt den URL-Parameter (`?form=<key>`) und den
WordPress-Shortcode (`[ondisos form="<key>"]`). Jeder Eintrag verweist auf eine
JSON-Datei in `frontend/surveys/`.

Für jedes Formular lassen sich konfigurieren:
- Empfänger einer Benachrichtungs-Mail
- sollen die Daten in der Datenbank abgespeichert werden (es ist auch denbar, Anmeldungen
nur per interner E-Mail entgegenzunehmen, ohne diese abzuspeichern. *Nicht* empfohlen, da
E-Mail Benachrichtungen nicht so zuverlässig sind wie ein Abspeichern. Mit `db: false` ist eine gültige `notify_email` **Pflicht**: Ohne Empfänger gingen die Daten verloren, deshalb zeigt das Frontend so ein Formular nicht an)
- Ob ein PDF-Download nach dem Absenden angezeigt werden soll
- Ob Teile des Formulars vor-ausgefüllt als Bookmark beim Benutzer abgespeichert werden sollen
(für Firmen, die regelmäßig Auszubildende anmelden)

⚠️ **wichtig**: es ist kein E-Mail-Versand der Formulardaten an den Benutzer vorgesehen, da diese
unverschlüsselt übermittelt würden! Wenn der Benutzer eine Bestätigung erhalten soll, muss die
PDF-Bestätigung aktiviert werden, da diese TLS-verschlüsselt (über https) an das Endgerät des Benutzers 
übertragen wird.

```
┌────────────────────────────────────────────────────────────────────┐
│        Zusammenhang: Formular-Konfiguration ↔ surveys/             │
└────────────────────────────────────────────────────────────────────┘

  URL ?form=<key>  /  Shortcode [ondisos form="<key>"]
                          │
                          ▼
  Formular-Konfiguration (DB)                frontend/surveys/
  ═══════════════════════════                ══════════════════

  Schlüssel          Config-Optionen         Formular-Datei
  ─────────────────────────────────         ───────────────
  'bs'          db, notify_email,      ──▶  bs.json              ✅
                prefill_fields,
                pdf: { enabled: true }

  'ausbilder-   db: false,            ──▶  ausbildernachmittag   ✅
   nachmittag'  notify_email               .json

  'prefill_     db: false,            ──▶  prefill.json          ✅
   demo'        prefill_fields

  'pdf_down-    db: true,             ──▶  pdf.json              ✅
   load_demo'   pdf: { enabled: true }

  Alle Einträge:  theme ───────────────▶   survey_theme.json     ✅
                                           (geteiltes Design-Theme)

  ─────────────────────────────────────────────────────────────────
  Die Formulare zq und bk sind Beispiele und nur teilweise definiert
  (zq nur als json, ohne Formular-Konfiguration, daher nicht abrufbar;
  bk nur als Konfiguration ohne json-Datei)
  ─────────────────────────────────────────────────────────────────
```