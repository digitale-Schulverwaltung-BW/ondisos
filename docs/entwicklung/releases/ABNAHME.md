# Abnahmeprotokolle 3.0.0, 3.1.0, 3.1.2 und 3.2.0a

Was vor den Releases in einer Demo-Umgebung (WordPress, Standalone-Frontend und Backend im Multi-Tenant-Modus) und im Browser geprüft wurde.
Die zum jeweiligen Zeitpunkt noch offenen manuellen Prüfungen stehen unverändert dabei; was davon heute noch aussteht, führt [TODO.md](../TODO.md).
Aktueller Teststand und Pipeline: [tests.md](../tests.md), [CI_CD.md](../CI_CD.md).

## v3.0.0

In einer Demo-Umgebung (WordPress + Standalone-Frontend + Backend, Multi-Tenant-Modus) verifiziert:

- [x] Frontend → Backend Submit mit HMAC; falsches Secret, unbekannter Slug und fehlender Slug → 401
- [x] Upload mit HMAC; getarnte Datei abgelehnt; Upload auf fremden/unbekannten Eintrag → 404 + `idor_attempt`
- [x] PDF-Download im Multi-Tenant-Modus (Token-Endpoint setzt den Tenant-Kontext)
- [x] Formular-Konfiguration vom Backend (Standalone `index/save/ical`, WordPress-Shortcode)
- [x] Migration `migrate.php` inkl. Ersatz des Platzhalter-Secrets; Abbruch in Production bei Standard-Secret

Im Browser gegen die Demo geprüft (Platform-Admin und Tenant-Admin):

- [x] Tenant-Switcher: Wechsel auf einen Tenant, Default und „Alle Tenants"; der Redirect bereinigt die URL, die Kopfzeile zeigt den Kontext, Liste und Zähler passen (Tenant B: 1, Default: 8, alle: 9)
- [x] Tenant-Verwaltung (`tenants.php`): Tenant anlegen (Slug aus dem Namen, API-Schlüssel nur einmal sichtbar), Tenant-Admin anlegen (Passwort nur einmal sichtbar), Schlüssel erneuern (alter sofort ungültig, neuer gültig), Tenant deaktivieren (API 401, Admin-Login abgelehnt)
- [x] Zugriffsgrenzen: fremder Eintrag → 404 + `idor_attempt`; Tenant-Admin sieht nur den eigenen Tenant (Liste, Dashboard, Papierkorb, Excel-Export), `tenants.php` → 403, `switch_tenant` wirkungslos

Zum Zeitpunkt der Abnahme noch offen (nur manuell prüfbar):

- [x] Backend-Oberfläche: Bootstrap 5.3.8 lokal unter `backend/public/assets/bootstrap/` (kein CDN mehr; DataTables war ungenutzt und entfällt)
- [ ] Upload im Browser-Formular (Datei auswählen; der Server-Teil ist verifiziert)
- [ ] Endpoint-Skripte (`submit.php`, `upload.php`, `form-config.php`, `pdf/download.php`) haben keine Unit-Tests

## v3.1.0

Automatisiert: 924 Unit- und 182 Integration-Tests (`composer test`; Integration braucht MySQL mit `database/schema.sql`, siehe `backend/UNITTESTS.md`).

In einer Demo-Umgebung (WordPress + Standalone-Frontend + Backend, Multi-Tenant) verifiziert:

- [x] Surveys aus der Datenbank gewinnen gegen die Datei; ETag/304; Backend gestoppt ⇒ gecachte Fassung (Standalone und WordPress), unbekanntes Formular 404, ohne Cache 503
- [x] Manipulierte Survey in der Datenbank (`</script>`, `<img onerror>`) bricht nicht aus und wird nicht ausgeliefert (Frontend nimmt die Datei, Audit `form_delivery_rejected`)
- [x] Formular-Editor als Plattform-Admin und Tenant-Admin: Validierung je Feld, Speichern, Wiederherstellen; Tenant-Admin ohne Logo/Dateinamen (Eingabe gesperrt, gebauter POST abgelehnt), fremdes Formular 404, `tenants.php` 403, POST ohne CSRF wirkungslos
- [x] Survey-Editor: Fehler mit Zeile/Spalte, Feldbericht und Diff, Entwurf ändert das öffentliche Formular nicht, Veröffentlichen ändert es sofort und erhöht die Version, Wiederherstellen; Code-Editor (CodeMirror) synchron mit dem Formular
- [x] Vorschau in echtem Chrome (DevTools-Protokoll): sieht aus wie das Frontend, Entwurf zeigt Änderung, schmales Fenster; ungültig gespeicherte Survey wird abgelehnt; ohne Sitzung Redirect
- [x] Neuer Tenant mit „Formulare übernehmen von Default": API 200, ohne `notify_email`; signierter Endpunkt `forms.php` (richtige/falsche/fremde Signatur, POST 405) und die echte Client-Klasse
- [x] Rate-Limit: 60 Schreibaktionen/Minute, danach 429 mit `Retry-After`, GET unberührt
- [x] `import-surveys.php` (Trockenlauf, Wiederholung, `--overwrite` mit Verlauf), `seed-forms.php --tenant` mit ungültigem Eintrag

Zum Zeitpunkt der Abnahme noch offen (nur manuell prüfbar):

- [x] WordPress-Plugin: Verbindungsstatus-Seite (*Einstellungen → Ondisos*) im Browser mit den neuen Zeilen „Secret passt" / „0 Formulare"
- [x] Breitenumschalter der Vorschau (Handy/Tablet) in einem echten Browser
- [x] Docker-Image neu bauen und Vorschau gegen das frisch gebaute Image prüfen (in der Demo wurde die Apache-Konfiguration im laufenden Container ersetzt)
- [ ] Endpoint-Skripte `forms.php` und `form-config.php?with=survey` haben keine Unit-Tests (ihre Logik liegt in getesteten Services; strukturelle Tests prüfen Reihenfolge und Abhängigkeiten)

## v3.1.2

Automatisiert: 1194 Unit-Tests (`vendor/bin/phpunit --testsuite=Unit`), dazu die neuen Integrationstests `TwoFactorRepositoryTest` (10) und `TwoFactorLoginTest` (7) gegen MySQL 8. Release Notes: [RELEASE-NOTES-3.1.2.md](RELEASE-NOTES-3.1.2.md).

**Neuinstallation nach [installation.md](../../betreiber/installation.md)** (frischer Klon, Produktions-Overlay, Mehrschul-Betrieb, Port 9180; Schritte 1 bis 6) und danach im Ablauf geprüft:

- [x] Image baut mit den neuen Composer-Paketen; Migration legt alle 9 Tabellen an (inkl. `admin_two_factor`, `admin_recovery_codes`, Spalte `admin_allowed_networks`); zweiter Lauf ohne Änderung; Health, `401` ohne Signatur, `form-config`, `302` auf `login.php`, CSP-Header
- [x] Admin-Hash aus `generate-password-hash.php` kommt mit 60 Zeichen im Container an
- [x] **Fund:** Nach `import-surveys.php` als `root` war `logs/audit.log` für den Webserver nicht beschreibbar, jede Anmeldung endete mit Fehler 500. Behoben (`AuditLogger`), danach mit einem als `root` ausgelösten Audit-Eintrag wiederholt: Datei gehört `www-data`, Anmeldung funktioniert
- [x] Schonfrist: Logins 1 bis 10 ohne Zwang, Login 11 führt in den Assistenten; `index.php`, `tenants.php`, `account.php` bleiben im Zwischenzustand geschlossen (`302` auf `login.php`)
- [x] Assistent (Sitzungs- und Zwischenzustand): falscher Code abgelehnt, richtiger Code, 10 Recovery-Codes, „Fertig“ ohne Haken abgelehnt; Hinweis erscheint einmal je Anmeldung mit der Restzahl
- [x] Anmeldung mit Code, falscher Code, Wiederverwendung desselben Codes abgelehnt, Recovery-Code einmalig (zweite Verwendung abgelehnt, Hinweis nach der Anmeldung), fünf falsche Codes beenden den Zwischenzustand
- [x] „Mein Konto“: Entfernen bei Pflicht verweigert, Recovery-Codes erneuern mit falschem/richtigem Passwort
- [x] Tenant anlegen, Schul-Admin anlegen: keine Pflicht für Schul-Admins; freiwillige Einrichtung, Entfernen, erneut einrichten; Anmeldung verlangt danach den Code; Zurücksetzen durch den Platform-Admin (Spalte „2FA“, Audit)
- [x] Netz-Allowlist je Tenant: ungültiger Eintrag abgelehnt; fremdes Netz ⇒ gleiche Meldung wie bei falschem Passwort (auch mit gefälschtem `X-Forwarded-For`); eigenes Netz ⇒ Anmeldung; laufende Sitzung endet nach Änderung der Liste (`login.php?network=1`)
- [x] `PLATFORM_ADMIN_ALLOWED_NETWORKS` mit fremdem Netz: Platform-Admin abgewiesen, Schul-Admin unberührt; Audit `login_network_denied`
- [x] `TWO_FACTOR_KEY` fehlt: in der Schonfrist Anmeldung mit Hinweis ohne Verbrauch, danach klare Ablehnung; `TWO_FACTOR_REQUIRED=off` hebt auf
- [x] Signierte API: Anmeldung mit Format 2 und mit Format 1 (`compat`, Audit `api_legacy_signature`), falsches Secret `401`, `forms.php`

**Auf einer bestehenden Instanz zu prüfen** (Upgrade von 3.1.1, im Browser; abhaken):

- [ ] Update nach den Release Notes: `Migration complete`, vorhandene Daten und Anmeldungen unverändert, bestehende Sitzungen/Zugänge funktionieren
- [x] Platform-Admin im Mehrschul-Betrieb: Hinweis, Einrichtung mit **echter Authenticator-App** (QR-Code scannen), Anmeldung mit Code; Recovery-Code und `reset-2fa.php`
- [ ] Netz-Allowlist hinter dem **echten Reverse-Proxy** (mit und ohne `TRUSTED_PROXIES`): erlaubtes und gesperrtes Netz; Hinweis in *Tenants*, wenn `X-Forwarded-For` ignoriert wird
- [ ] Betrieb ohne Login (`AUTH_ENABLED=false`) mit `PLATFORM_ADMIN_ALLOWED_NETWORKS`: Zugriff aus Verwaltungsnetz und von außen
- [x] Layout und Bedienbarkeit der neuen Seiten im Browser (Assistent, „Mein Konto“, Karte in *Tenants*), auch auf dem Handy; Druck der Recovery-Codes
- [x] Content-Security-Policy: keine Verstöße in der Browser-Konsole auf den Backend-Seiten und der Formularseite (sonst `CSP_MODE=report-only`)
- [ ] Frontend/Plugin 3.1.2 gegen Backend 3.1.2: Anmeldung absenden, Upload, PDF-Download; danach `api_legacy_signature` im Audit-Log prüfen
- [ ] WordPress-Plugin: Verbindungsstatus-Seite, Formular mit Honeypot, Absenden

## v3.2.0a (Unterschrift per Smartphone)

Automatisiert: 1274 Unit-Tests, dazu `SignatureHandoffRepositoryTest` (6, Tenant-Isolation und Einmaligkeit) gegen MySQL 8. Plan: [PLAN-3.2.0a.md](../plans/PLAN-3.2.0a.md).

**In der Entwicklungsumgebung geprüft** (Docker-Stack, Standalone-Frontend, WordPress mit Plugin aus `build-zip.sh`, Chrome mit Handy-Größe als „Smartphone“; 2026-10-21):

- [x] Migration legt `signature_handoffs` an und ergänzt die Farbspalten in einer vorhandenen Tabelle; zweiter Lauf ohne Änderung
- [x] Option „Unterschrift auf dem Smartphone anbieten“ im Formular-Editor (*Allgemein*), ausgeliefert über `form-config.php`; Formular ohne Option: `create` mit `403 disabled`
- [x] Backend-Endpunkt: Format 1 abgelehnt (`401`), SVG statt PNG `422`, zweites `submit` `409`, Abholung genau einmal, Fehlversuche als `signature_handoff_rejected`
- [x] Standalone: Button an beiden Unterschriftsfeldern des Demo-Formulars; Dialog mit QR-Code und Countdown; „Smartphone verbunden“ nach dem Öffnen; Unterschrift, Vorschau in der Strichfarbe des Themes, *Übernehmen*, Absenden; PNG in der Anmeldung
- [x] Zweites Feld: eigene Sitzung mit eigener Größe (400 × 150) und Strichfarbe; *Verwerfen* erzeugt einen neuen Code und übernimmt nichts
- [x] Handy-Seite: Hoch- und Querformat (Fläche passt in die Höhe, Dreh-Hinweis nur hochkant), Drehen behält die Striche, ungültiger bzw. verbrauchter Link mit Meldung, keine Konsolenfehler unter der CSP
- [x] Relay: ohne CSRF-Token `403 csrf`, `sign_url` aus dem Browser wird ignoriert
- [x] WordPress-Plugin: Button, Dialog und Handy-Seite über `admin-ajax.php`; Anmeldung mit Unterschrift; als anonymer Besucher: ohne Nonce `csrf`, mit Nonce `create`/`poll`
- [x] Vorschau im Backend ohne Button (`PreviewAssetsTest`)

**Noch zu prüfen** (abhaken):

- [x] iPhone (Safari) im WLAN per QR-Code: Ablauf funktioniert. **Funde:** (1) Im Querformat wurde die Fläche kleiner (Safaris Leisten nehmen Höhe, iOS vergrößert die Schrift); (2) Striche, die knapp außerhalb der Fläche beginnen, markierten Text der Seite (wie bei DocuSeal). Behoben: Fläche richtet sich nur nach der eigenen Höhe (Seite darf scrollen, Countdown bleibt sichtbar), `text-size-adjust: 100%`, keine Textauswahl und kein Kontextmenü auf der Handy-Seite, Rahmen der Fläche mit `touch-action: none`
- [x] Zweiter Durchgang iPhone: Brave (Chromium) im Querformat gut; **Safari zoomte die Seite** (Fläche beim 300 × 200-Feld klein, 400 × 150-Feld verschoben). Behoben: kein Zoom auf der Handy-Seite (`maximum-scale=1`, `touch-action: manipulation`); im Querformat stehen Countdown und ein kompakter ✓-Button neben der Fläche, die damit die volle Höhe bekommt (Vorschlag aus dem Test)
- [x] Nach den Korrekturen erneut auf dem iPhone (Safari): passt (Querformat, ✓-Button)
- [ ] Android (Chrome) per QR-Code im **Mobilfunknetz**; Zeichnen mit dem Finger, Drehen während des Unterschreibens, Querformat-Button
- [ ] Rechner mit Touchscreen und Tablet: Button erscheint bzw. fehlt wie beschrieben
- [ ] Code läuft ab (10 Minuten): Meldung auf dem Smartphone vor dem Unterschreiben und im Dialog, „Neuen Code erzeugen“
- [ ] Hinter dem **echten Reverse-Proxy**: Adresse im QR-Code (https, öffentlicher Host); Rate-Limit je Besucher
- [ ] WordPress mit Wordfence (oder anderer WAF): Polling und Base64-Body werden nicht blockiert
- [ ] Detailansicht und PDF-Bestätigung einer per Smartphone unterschriebenen Anmeldung
- [ ] Upgrade einer bestehenden Instanz: Backend zuerst, dann Frontend/Plugin; Formulare ohne Option unverändert

