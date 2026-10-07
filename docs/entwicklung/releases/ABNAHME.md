# Abnahmeprotokolle 3.0.0 und 3.1.0

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

- [ ] WordPress-Plugin: Verbindungsstatus-Seite (*Einstellungen → Ondisos*) im Browser mit den neuen Zeilen „Secret passt" / „0 Formulare"
- [ ] Breitenumschalter der Vorschau (Handy/Tablet) in einem echten Browser
- [ ] Docker-Image neu bauen und Vorschau gegen das frisch gebaute Image prüfen (in der Demo wurde die Apache-Konfiguration im laufenden Container ersetzt)
- [ ] Endpoint-Skripte `forms.php` und `form-config.php?with=survey` haben keine Unit-Tests (ihre Logik liegt in getesteten Services; strukturelle Tests prüfen Reihenfolge und Abhängigkeiten)
