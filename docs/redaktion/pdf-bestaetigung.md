# PDF-Bestätigung gestalten

Nach dem Absenden können Anmeldende eine **PDF-Bestätigung** herunterladen, und das Sekretariat kann sie in der Detailansicht abrufen und ausdrucken.
Logo, Farbe und Texte pflegen Sie als Schul-Admin im Backend unter **Formulare**. Gestaltet wird pro Schule (Logo und Farbe) bzw. pro Formular (Texte, Felder, Anhang).

![Formulare: Logo der Schule und Farbe der PDF-Bestätigungen](../img/backend-formulare.png)

## Logo und Farbe der Schule

Auf der Seite **Formulare** weiter unten:

- **Logo der Schule:** erscheint oben in den PDF-Bestätigungen **aller** Formulare Ihrer Schule. PNG oder JPEG, höchstens 2 MB; ein transparenter Hintergrund bleibt bei PNG erhalten.
  Das Logo wird automatisch auf etwa 150 Pixel Breite verkleinert. Mit **Logo hochladen** bzw. **Logo ändern** ersetzen Sie es, mit **Logo entfernen** löschen Sie es (nach Rückfrage).
- **Farbe der PDF-Bestätigungen:** der Balken am linken Rand von Einleitung und Abschnitten. Auswahl per Farbfeld oder als Hex-Wert (`#RRGGBB`, Standard `#3498db`), dann **Farbe speichern**.
  Eine Vorschau („So sieht der Balken aus“) zeigt das Ergebnis.

## Texte, Felder und Anhang eines Formulars

Unter **Formulare → Bearbeiten** (Formular wählen) im Reiter **PDF-Bestätigung**:

| Einstellung | Wirkung |
|---|---|
| **PDF-Bestätigung anbieten** | schaltet die Bestätigung für dieses Formular ein (setzt „Anmeldungen im Backend speichern“ voraus) |
| **Download vor dem Abschluss verlangen** | Anmeldende müssen das PDF herunterladen, bevor sie fortfahren können |
| **Titel**, **Beschriftung des Download-Buttons** | Texte der Download-Karte nach dem Absenden |
| **Gültigkeit des Download-Links** | in Sekunden, 60 bis 86400; üblich 1800 (30 Minuten). Danach lässt sich das PDF nur noch im Backend abrufen |
| **Überschrift im PDF**, **Einleitungstext**, **Fußzeile** | Texte im PDF |
| **Felder im PDF**, **Felder, die nicht im PDF erscheinen** | alle Felder, nur ausgewählte oder alle außer bestimmten; ein Feldname pro Zeile (Zustimmungsfelder gehören meist in die Ausschlussliste) |
| **Abschnitte vor / nach den Angaben** | eigene Textblöcke (z. B. Hinweise zum weiteren Vorgehen) |
| **Zusätzliches PDF anhängen** | z. B. ein Informationsblatt; wird hinter jede Bestätigung dieses Formulars gesetzt, auch für bereits eingegangene Anmeldungen. Höchstens 5 MB und 20 Seiten |

Die Reihenfolge der Felder im PDF entspricht der Reihenfolge im Formular. Das Dokument für Anmeldende enthält nur die Angaben aus dem Formular.

**Angehängte PDFs:** Nicht verwendbar sind PDFs mit komprimierter Querverweistabelle (PDF 1.5 oder neuer, aus manchen Office-Programmen) und verschlüsselte PDFs; sie werden beim Hochladen mit einem Hinweis abgelehnt.
Dann hilft häufig, das Dokument mit einem anderen Programm neu zu erzeugen, z. B. über „Drucken → Als PDF speichern“.

**Vorschau:** Im Reiter finden Sie eine **PDF-Vorschau** mit Beispielangaben (Feldname, 1.1.2000, 1) und Ihren aktuellen, noch nicht gespeicherten Eingaben; sie trägt das Wasserzeichen „VORSCHAU“ und zeigt auch das angehängte PDF.

![PDF-Bestätigung mit Schul-Logo](../img/pdf-bestaetigung.png)

## Hinweise für Betreiber

- Welches Logo gilt, entscheidet diese Reihenfolge: `logo: false` in der Formular-Konfiguration (kein Logo) → Pfad in der Konfiguration (nur Plattform-Admin) → **Schul-Logo (Upload)** → `PDF_LOGO_<FORMULAR>` → `PDF_LOGO_PATH`.
- Die letzten beiden sind ein **Fallback für Installationen ohne Upload**, z. B. ein gemeinsames Logo für alle Schulen. Die Datei liegt im Backend unter `backend/templates/pdf/assets/` (nicht versioniert, `.gitignore`),
  der Pfad steht in der `backend/.env`; im Docker-Container ist es `/var/www/html/templates/pdf/assets/<datei>`:

  ```bash
  PDF_LOGO_PATH=/var/www/html/templates/pdf/assets/logo.png          # für alle Formulare
  PDF_LOGO_BS=/var/www/html/templates/pdf/assets/logo-bs.png         # nur für das Formular „bs“ (hat Vorrang)
  ```

  Danach `docker compose up -d backend`. Ohne Logo erscheint kein Logo im PDF (kein Fehler).
- Logo, Farbe und angehängte PDFs liegen unter `uploads/tenant-<id>/…` im Backend und gehören in die [Sicherung](../betreiber/betrieb.md#sicherung-und-wiederherstellung). Sie werden beim Kopieren von Formularen auf eine andere Schule **nicht** übernommen.
- Technische Hintergründe (Token, Templates, mPDF): [backend/PDF_SETUP.md](../../backend/PDF_SETUP.md).
