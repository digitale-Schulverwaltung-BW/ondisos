# 🎨 SurveyJS-basierte Formular-Erstellung und -Anpassung

## 📋 Inhaltsverzeichnis

- [Features](#-features)

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
    *.json überschreiben            ② forms-config.php: Eintrag ergänzen
                                    ③ Shortcode einbetten:
                                       [ondisos form="neu"]
```

### Workflow als Video
![ondisos-editor-workflow-3](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/-/wikis/uploads/02b75dfede029d8dc511ed466734d678/ondisos-editor-workflow-3.mp4){width=1280 height=720}

### Neues Formular erstellen
Wenn ein neues Formular mit neuem Einbettungs-Code (in Wordpress: Shortcode ```[ondisos form="neu"]```) erstellt werden soll, muss dies noch in [frontend/config/forms-config.php](frontend/config/forms-config.php) definiert werden.

---

## Zusammenhang: forms-config.php ↔ surveys/

Der Schlüssel in `forms-config.php` bestimmt den URL-Parameter (`?form=<key>`) und den
WordPress-Shortcode (`[ondisos form="<key>"]`). Jeder Eintrag verweist auf eine
JSON-Datei in `frontend/surveys/`.

Für jedes Formular lassen sich konfigurieren:
- Empfänger einer Benachrichtungs-Mail
- sollen die Daten in der Datenbank abgespeichert werden (es ist auch denbar, Anmeldungen
nur per interner E-Mail entgegenzunehmen, ohne diese abzuspeichern. *Nicht* empfohlen, da
E-Mail Benachrichtungen nicht so zuverlässig sind wie ein Abspeichern)
- Ob ein PDF-Download nach dem Absenden angezeigt werden soll
- Ob Teile des Formulars vor-ausgefüllt als Bookmark beim Benutzer abgespeichert werden sollen
(für Firmen, die regelmäßig Auszubildende anmelden)

⚠️ **wichtig**: es ist kein E-Mail-Versand der Formulardaten an den Benutzer vorgesehen, da diese
unverschlüsselt übermittelt würden! Wenn der Benutzer eine Bestätigung erhalten soll, muss die
PDF-Bestätigung aktiviert werden, da diese TLS-verschlüsselt (über https) an das Endgerät des Benutzers 
übertragen wird.

```
┌────────────────────────────────────────────────────────────────────┐
│           Zusammenhang: forms-config.php ↔ surveys/                │
└────────────────────────────────────────────────────────────────────┘

  URL ?form=<key>  /  Shortcode [ondisos form="<key>"]
                          │
                          ▼
  forms-config.php                           frontend/surveys/
  ════════════════                           ══════════════════

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
  (zq nur als json, ohne Eintrag in der forms-config.php, daher nicht
  abrufbar; bk nur in der forms-config.php ohne json-Datei)
  ─────────────────────────────────────────────────────────────────
```