# Migration 3.1 → 3.2

3.2.0 ist das erste Release nach 3.1.2. Es enthält drei Vorhaben, die bisher nur auf `main` lagen:

- **Grafischer Survey-Editor** im Backend mit Live-Vorschau, Hilfe bei Bedingungen sowie Vorlagen und Bausteinen ([PLAN-3.2.0.md](../entwicklung/plans/PLAN-3.2.0.md));
- **Unterschrift per Smartphone** per QR-Code, Schalter je Formular, Standard aus (3.2.0a, [PLAN-3.2.0a.md](../entwicklung/plans/PLAN-3.2.0a.md));
- **Theme-Editor** im Backend (*Formulare → Design*, 3.2.0b, [PLAN-3.2.0b.md](../entwicklung/plans/PLAN-3.2.0b.md)).

Dazu kommen die **SurveyJS Form Library 3.2.0** (statt 2.3.15) und der Signaturmodus **`strict`** als Standard. Alle Änderungen im Einzelnen stehen im [Changelog](../entwicklung/changelog.md).

Die Reihenfolge bleibt: **Backend zuerst**, danach Frontends und WordPress-Plugin. Wer von 3.0 kommt, liest zuerst [MIGRATION-3.1.md](MIGRATION-3.1.md) und die
[Hinweise zu 3.1.2](../entwicklung/releases/RELEASE-NOTES-3.1.2.md#upgrade-von-311).

## Vor dem Update

1. **Sichern**, vor allem die Datenbank ([betrieb.md](betrieb.md#sicherung-und-wiederherstellung)).

2. **Signaturformat prüfen.** Das Backend nimmt ab 3.2.0 nur noch Signaturformat 2 an (`API_SIGNATURE_MODE=strict`). Frontends und Plugins ab 3.1.2 senden es von sich aus.
   Sehen Sie vorher im Audit-Log nach, wann zuletzt eine Anfrage im alten Format kam (die letzten fünf Einträge):

   ```bash
   docker compose exec -T backend sh -c 'grep api_legacy_signature logs/audit.log | tail -n 5'
   ```

   Ohne Docker: `grep api_legacy_signature backend/logs/audit.log | tail -n 5`.

   **Keine Ausgabe:** Alle Frontends senden das neue Format, weiter mit Schritt 3. Sonst ist jede Zeile ein Eintrag; die Zeit steht vorn unter `"ts"`, z. B.
   `{"ts":"2026-11-13T10:10:03+01:00","event":"api_legacy_signature",…,"details":{"purpose":"submit"}}`. Das Backend schreibt ihn höchstens einmal pro Stunde.
   Ältere Einträge (aus der Zeit vor dem Update der Frontends auf 3.1.2) können Sie übergehen.

   Ist der neueste Eintrag von den letzten Tagen, sendet noch ein Frontend das alte Format: ein Frontend oder Plugin älter als 3.1.2, oder ein Frontend mit `API_SIGNATURE_VERSION=1` in seiner Umgebung
   (der Notausstieg aus 3.1.2, [Standalone-Frontend](../schul-it/standalone-frontend.md)). Aktualisieren Sie dieses Frontend bzw. entfernen Sie die Einstellung.
   Geht das nicht sofort, setzen Sie im Backend übergangsweise `API_SIGNATURE_MODE=compat` (Docker: Root-`.env`, die Zeile bleibt beim Neuschreiben von `backend/.env` erhalten).
   Andernfalls scheitern Anfragen dieses Frontends nach dem Update mit `401`.

3. **Firewall oder Proxy vor dem Backend:** Wer dort nur bekannte API-Pfade durchlässt, gibt zusätzlich `api/signature-handoff.php` frei (Unterschrift per Smartphone).

4. **Docker ohne Internet:** Der Backend-Container installiert nach diesem Update beim ersten Start einmal die Composer-Pakete (bisher fehlte die Prüfung, siehe [betrieb.md](betrieb.md#docker)).
   Dafür braucht er Zugang zu Packagist und GitHub. Ohne Internet steht im Log `⚠️ composer install FAILED`; das Backend läuft dann mit dem vorhandenen `vendor/` weiter.
   3.2.0 braucht keine neuen Pakete, der Fehler ist hier also harmlos.

## Backend

1. Update wie gewohnt ([betrieb.md](betrieb.md#updates)):

   ```bash
   git fetch --tags && git checkout v3.2.0
   docker compose build --pull backend
   docker compose up -d backend
   docker compose logs backend | grep -E "Migration complete|API_SIGNATURE_MODE"
   ```

   Ohne Docker: `composer install --no-dev --optimize-autoloader` und `php migrate.php` im Verzeichnis `backend/`, danach Apache bzw. PHP-FPM neu laden.

2. **Migration:** Sie legt die Tabelle `signature_handoffs` an (offene Sitzungen der Unterschrift per Smartphone, nach spätestens 10 Minuten verbraucht oder abgelaufen).
   Sonst ändert sie nichts am Schema; vorhandene Daten bleiben unberührt.

3. **Signaturmodus:** Der Docker-Start meldet den wirksamen Modus (`ℹ️ API_SIGNATURE_MODE=strict …` bzw. eine Warnung bei `compat`). `compat` ist nur noch eine Übergangslösung,
   siehe [sicherheit.md](../entwicklung/sicherheit.md#signierte-api-signaturformat-2-312).

4. **Neue, optionale Einstellung:** `EDITOR_PREVIEW_RATE_LIMIT_MAX` (Standard 300 je Minute) begrenzt die Prüfungen der Live-Vorschau im Survey-Editor getrennt vom Limit für Speichern und Veröffentlichen.
   Nur ändern, wenn Redakteure bei der Live-Vorschau `429` sehen.

## Frontend und WordPress-Plugin

1. **Standalone-Frontend:** `git checkout v3.2.0` (bzw. `git pull`), sonst nichts. Das Frontend bringt die SurveyJS-Library 3.2.0 mit; wer die Seitenvorlage angepasst hat, ersetzt
   `defaultV2.fontless.min.css` durch `survey-core.min.css` (die alte Datei gibt es nicht mehr).
2. **WordPress:** Plugin-ZIP 3.2.0 installieren ([Plugin-Anleitung](../../wordpress-plugin/INSTALL.md#aktualisieren)).
3. **Unterschrift per Smartphone** (nur, wenn eine Schule sie einschaltet): Die Adresse im QR-Code bildet das Frontend aus der Adresse, unter der Besucher das Formular aufrufen.
   Hinter einem Reverse-Proxy vor dem Frontend müssen Host-Header und `X-Forwarded-Proto: https` durchgereicht werden, und `TRUSTED_PROXIES` (im Plugin: *Vertrauenswürdige Proxys*)
   muss den Proxy nennen. Sonst teilen sich alle Besucher einer Schule das Limit von 10 QR-Codes in 10 Minuten. Details: [Standalone-Frontend](../schul-it/standalone-frontend.md#unterschrift-per-smartphone).

Ein 3.2-Frontend an einem 3.1.2-Backend zeigt die Formulare wie bisher; die Unterschrift per Smartphone fehlt dann, weil das Backend den Schalter nicht kennt.

## Was sich für Schulen sichtbar ändert

- **Aussehen der Formulare (SurveyJS 3):** Themes aus SurveyJS 2 (`--sjs-*`) funktionieren weiter: Markenfarbe, Schrift und Ecken bleiben. Titel, Karten und Hintergrund folgen aber den
  neuen Standardwerten (dunkler Titel, abgerundete Karten mit Rahmen, getönter Hintergrund). Das alte Aussehen stellen Schul-Admins unter *Formulare → Design* mit **Auf Vorgabe zurücksetzen**
  und der eigenen Markenfarbe wieder her ([SURVEYJS.md](../redaktion/SURVEYJS.md#ältere-themes-und-eigene-schriften-oder-bilder)). Weisen Sie die Schulen vor dem Update darauf hin.
- **Deutsche Standardtexte:** Knöpfe und Meldungen von SurveyJS (z. B. „Weiter“, „Bitte beantworten Sie diese Frage.“) erscheinen jetzt deutsch.
- **PDF-Bestätigung gegliedert:** Neue Anmeldungen bekommen im PDF die Seitentitel des Formulars als Überschriften und Fragetitel statt Feldnamen. Abschalten: Formular-Editor,
  Reiter *PDF-Bestätigung*, „Seitentitel der Survey als Überschriften“. Ältere Anmeldungen bleiben wie bisher.
- **Survey-Editor:** Neben dem JSON gibt es den Reiter *Grafisch*, die *Live-Vorschau* (eigenes Fenster; der Browser darf es für die Verwaltungsoberfläche nicht blockieren) und für neue Formulare
  eine Auswahl an Vorlagen. Den SurveyJS Creator braucht niemand mehr; eingefügtes JSON aus dem Creator funktioniert weiter ([SURVEYJS.md](../redaktion/SURVEYJS.md)).
- **Anmeldeliste:** Felder der Survey lassen sich als zusätzliche Spalten einblenden ([Sekretariat](../sekretariat/README.md#2-sehen-was-neu-ist)).

## Korrekturen an den Beispiel-Surveys

Zwei der mitgelieferten Survey-Dateien hatten Verweise auf Felder, die es nicht gibt; behoben sind sie nur in den **Dateien** unter `frontend/surveys/`:

- `zq.json`: neun Pflicht-Bedingungen verwiesen auf `{Alter}` statt `{age}`; die Angaben der Erziehungsberechtigten wurden dadurch nie Pflicht.
- `vabo.json`: der berechnete Wert von `Klasse` (`setValueExpression`) verwies auf `{_Ausbildungsberuf}`, das es dort nicht gibt; der Ausdruck ist entfernt, `Klasse` steht fest auf `VABO`.

Hat eine Schule diese Surveys in die Datenbank übernommen (`import-surveys.php` oder erste Veröffentlichung im Editor), steckt der Fehler dort weiter. Im Survey-Editor meldet
**Prüfen** solche Verweise jetzt als Warnung; die Schule korrigiert sie dort. `import-surveys.php --overwrite` würde eigene Änderungen der Schule überschreiben und ist dafür nicht gedacht.

## Neuinstallationen

Neue Formulare entstehen im Backend (*Formulare → Neues Formular anlegen*, dann im leeren Survey-Editor eine Vorlage wählen); ein Seed ist nicht mehr nötig ([Installation](installation.md#6-erstes-formular-anlegen)).
`seed-forms.php` und `import-surveys.php` bleiben für die Übernahme einer vorhandenen `forms-config.php` und eigener Survey-Dateien.

## Zurückrollen

`git checkout v3.1.2` und neu bauen (Docker: `docker compose up -d --build backend`), danach Frontends und Plugin auf 3.1.2. Die Migration ist rein additiv; die Tabelle `signature_handoffs`
stört den alten Code nicht, die Datenbank muss nicht zurückgespielt werden. Zwei Einschränkungen:

- Themes, die Schulen im neuen Theme-Editor geändert haben, nutzen teils Variablen von SurveyJS 3 (`--sjs2-*`); ein 3.1-Frontend mit SurveyJS 2 kennt sie nicht und zeigt dort dessen Standardwerte.
  Der vorherige Stand liegt im Verlauf des Themes.
- Surveys, die mit dem grafischen Editor erstellt wurden, sind gewöhnliches SurveyJS-JSON und laufen auch unter 3.1.2; nur der Editor selbst fehlt dann.
