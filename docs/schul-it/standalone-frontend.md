# Standalone-Frontend einrichten

Das Standalone-Frontend ist die Alternative zum [WordPress-Plugin](../../wordpress-plugin/INSTALL.md): ein kleines PHP-Programm, das die Anmeldeformulare
auf einem eigenen Webserver anzeigt (z. B. `anmeldung.meineschule.de`). Es speichert selbst keine Anmeldungen, sondern übergibt sie signiert an das Backend
([Betriebsmodell](../betreiber/betriebsmodell.md)).

## Voraussetzungen

- Webserver (Apache oder Nginx) mit **PHP 8.1+** (Erweiterung `curl`), mit HTTPS
- Vom Webserver aus erreichbar: das **Backend** (URL, Tenant-Slug und Tenant-Secret erhalten Sie vom Betreiber)
- Schreibrecht des Webserver-Benutzers auf das Verzeichnis `frontend/cache`
- Kein Composer, keine Datenbank nötig
- **Richtige Uhrzeit:** Der Server synchronisiert seine Uhr (NTP). Die Anfragen ans Backend tragen einen Zeitstempel und werden bei mehr als 5 Minuten Abweichung abgelehnt.

## Installation

1. Repository bzw. Release auf den Server bringen. Nur der Ordner `frontend/` wird benötigt; er enthält `public/` (Webroot), `src/`, `config/`, `surveys/`.
2. Webserver auf `frontend/public` zeigen lassen, Beispiel Apache:

   ```apache
   <VirtualHost *:80>
       ServerName anmeldung.example.com
       DocumentRoot /var/www/ondisos/frontend/public
       <Directory /var/www/ondisos/frontend/public>
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```

   HTTPS einrichten (z. B. `sudo certbot --apache -d anmeldung.example.com`); den Redirect und die Security-Header liefert `public/.htaccess.example`
   (kopieren nach `.htaccess` und die auskommentierten Zeilen aktivieren). Hinweise: [betrieb.md](../betreiber/betrieb.md#https).
3. Konfiguration anlegen:

   ```bash
   cd frontend
   cp .env.example .env
   chmod 600 .env             # enthält das Tenant-Secret
   nano .env
   ```

   | Schlüssel | Bedeutung |
   |---|---|
   | `BACKEND_API_URL` | Adresse der Backend-API, z. B. `https://backend.example.org/api` |
   | `TENANT_SLUG` | Kennung Ihrer Schule im Backend |
   | `TENANT_API_SECRET` | Secret Ihrer Schule (nur serverseitig, nie weitergeben) |
   | `FROM_EMAIL` | Absender der Benachrichtigungs-E-Mails |
   | `UPLOAD_MAX_SIZE`, `UPLOAD_ALLOWED_TYPES` | Upload-Grenzen (höchstens so groß wie im Backend) |
   | `TRUSTED_PROXIES` | nur wenn ein Reverse-Proxy vor dem Frontend steht: dessen Adresse(n), damit die IP der Besucher richtig erkannt wird ([betrieb.md](../betreiber/betrieb.md#reverse-proxy-client-ip-und-rate-limit)) |
   | `CSP_MODE` | Content-Security-Policy der Formularseite: `enforce` (Standard), `report-only` (nur melden, zum Diagnostizieren), `off`. In der `.htaccess` bzw. im Webserver **keine zweite** Richtlinie setzen |
   | `API_SIGNATURE_VERSION` | nur als Notausstieg: `1` sendet das alte Signaturformat, solange das Backend noch älter als 3.1.2 ist (Standard `2`) |

4. Cache-Verzeichnis beschreibbar machen:

   ```bash
   mkdir -p cache && chown www-data:www-data cache
   ```

   Das Frontend legt dort die vom Backend gelieferte Formularfassung ab (`cache/forms/`, anderer Ort per `FORM_CACHE_DIR`). Damit erscheinen Formulare auch dann,
   wenn das Backend kurz nicht erreichbar ist (bis zu 7 Tage alte Fassung). Ohne Schreibrecht funktioniert alles weiter, nur ohne diesen Ausfallschutz.

Eine `forms-config.php` brauchen Sie nicht: Die Formular-Konfiguration kommt aus dem Backend und wird dort von der Redaktion gepflegt.

## Testen

1. `https://anmeldung.meineschule.de/index.php?form=<formular>` öffnen (den Formular-Schlüssel nennt die Redaktion, z. B. `bs`). Das Formular erscheint.
2. Eine Testanmeldung absenden. Sie erscheint im Backend mit Status *Neu*; die PDF-Bestätigung lässt sich herunterladen.
3. Die Testanmeldung vom Sekretariat im Backend wieder löschen lassen.

## Formular auf der Schulwebsite verlinken

Verlinken Sie von der Schulwebsite auf `index.php?form=<formular>`. Felder lassen sich per Link vorbelegen, z. B. `…?form=bs&Klasse=5a`;
übernommen werden nur Parameter, die als Feldname im Formular existieren.

## Unterschrift per Smartphone

Schaltet die Redaktion bei einem Formular „Unterschrift auf dem Smartphone anbieten“ ein (ab 3.2.0a), scannen Besucher am Rechner einen QR-Code und unterschreiben auf dem Smartphone.
Dafür kommen zwei Adressen des Frontends dazu, die wie das Formular **öffentlich** erreichbar sein müssen:

| Adresse | Aufgabe |
|---|---|
| `sign.php` | Seite auf dem Smartphone (der QR-Code zeigt dorthin) |
| `api/signature.php` | Vermittlung zwischen Browser und Backend (Formularseite und Smartphone) |

Die Adresse im QR-Code bildet das Frontend aus der Adresse, unter der Besucher das Formular aufrufen. Hinter einem Reverse-Proxy müssen deshalb der Host-Header und `X-Forwarded-Proto: https`
ankommen, sonst zeigt der QR-Code auf eine interne Adresse oder auf `http://`. Prüfen Sie das einmal mit einem Smartphone im Mobilfunknetz (nicht im WLAN der Schule).

Eine Web Application Firewall vor dem Frontend darf Anfragen an `api/signature.php` nicht blockieren: Die Formularseite fragt dort während des Unterschreibens alle 3 Sekunden nach,
und das Smartphone sendet die Unterschrift als Base64-Bild im JSON-Body (bis etwa 200 KB).

### Meldungen der Unterschrift per Smartphone

Im Browser erscheint jeder Backend-Fehler als „Der Dienst ist gerade nicht erreichbar“ (HTTP `502`). Das PHP-Fehlerlog des Frontends nennt die Ursache (im WordPress-Plugin: `wp-content/debug.log`):

| Meldung im Fehlerlog | Bedeutung und Abhilfe |
|---|---|
| `Signature handoff (…): backend HTTP 404 (no JSON answer)` | Den Endpunkt gibt es nicht: Backend älter als 3.2.0a, oder ein Reverse-Proxy bzw. eine Firewall vor dem Backend lässt nur bekannte API-Pfade durch; dann `api/signature-handoff.php` freigeben |
| `Signature handoff (…): backend HTTP 401 (unauthorized)` | Signatur abgelehnt: `TENANT_API_SECRET` passt nicht oder die Uhren weichen ab (im Fehlerlog des Backends: `API signature rejected (signature, …)`) |
| `Signature handoff (…): backend HTTP 500 (server)` | Fehler im Backend; dessen Fehlerlog nennt ihn (`Signature handoff (…) failed: …`), z. B. fehlende Composer-Pakete nach einem Update ([Betrieb](../betreiber/betrieb.md#weitere-fehlerbilder)) |
| `Signature handoff: backend unreachable: …` | Keine Verbindung (DNS, TLS, Zeitüberschreitung): `BACKEND_API_URL` und Erreichbarkeit prüfen |
| `Signature handoff needs API signature format 2 …` | `API_SIGNATURE_VERSION=1` ist gesetzt; die Funktion braucht Signaturformat 2 |

## Texte der Oberfläche anpassen

Meldungen und Beschriftungen des Frontends stehen in `config/messages.php`. Eigene Änderungen gehören in `config/messages.local.php`
(Vorlage: `config/messages.example.php`). Diese Datei wird von Git ignoriert und bleibt bei Updates erhalten.

## Aktualisieren

Neuen Stand ins Verzeichnis holen (`git pull` bzw. Release entpacken). `.env` und `config/messages.local.php` bleiben unberührt, ein Neustart ist nicht nötig.
Das Backend muss **zuerst** aktualisiert werden (siehe [Betriebsmodell](../betreiber/betriebsmodell.md#betrieb-im-alltag)): Das Frontend 3.1.2 signiert im neuen Format, das erst ein Backend ab 3.1.2 versteht.

## Fehlersuche

| Beobachtung | Ursache und Abhilfe |
|---|---|
| **Wartungsseite (503)** | Backend nicht erreichbar oder Tenant abgelehnt. Die Ursache steht im PHP-Fehlerlog (`FormConfigLoader: form "…" not loaded (unreachable\|unauthorized\|error)`). `BACKEND_API_URL` und `TENANT_SLUG` prüfen; vom Server aus `curl "$BACKEND_API_URL/health.php"` |
| **404 „Formular nicht gefunden"** | Das Backend kennt das Formular für Ihre Schule nicht: Schlüssel richtig geschrieben? Wurde es angelegt? (Redaktion bzw. Betreiber fragen) |
| **Absenden: „Unauthorized" / 401** | `TENANT_API_SECRET` passt nicht zum Tenant, oder das Secret wurde im Backend neu erzeugt (neues Secret beim Betreiber erfragen). Ab 3.1.2 auch: **Uhr des Servers geht falsch** (mehr als 5 Minuten Abweichung zum Backend; im Fehlerlog des Backends steht `timestamp_skew`) oder das Frontend ist neuer als das Backend (Backend zuerst aktualisieren) |
| **Besucher sehen „Erfolg“, aber im Backend fehlt die Anmeldung** | Der Spamschutz (unsichtbares Feld `website_url`) hat ausgelöst: Im PHP-Fehlerlog steht `Honeypot triggered`. Ursachen: ein Bot, ein Browser-Plugin, das alle Felder automatisch ausfüllt, oder eine Survey-Frage mit dem Namen `website_url` (die Redaktion muss sie umbenennen; neue Surveys lassen den Namen nicht mehr zu) |
| **Absenden scheitert bei vielen Besuchern, im Fehlerlog `Backend API returned HTTP 429`** | Das Limit je Besucher im Backend ist erreicht. Steht ein Reverse-Proxy vor dem Frontend, muss `TRUSTED_PROXIES` stimmen, sonst gelten alle Besucher als dieselbe Adresse |
| **Formular erscheint nicht, obwohl das Backend läuft** | Formular ohne Speicherung im Backend und ohne gültige Empfänger-Adresse wird nicht angezeigt; Redaktion trägt unter *Formulare* eine Empfänger-Adresse ein |
| **Kein Button „Auf dem Smartphone unterschreiben“** | Die Option ist beim Formular aus (Redaktion), das Feld nutzt nicht das Format PNG, oder das Gerät hat einen Touchscreen als Haupteingabe (dort wird direkt im Feld unterschrieben) |
| **Smartphone: „Der Dienst ist gerade nicht erreichbar“** bzw. Dialog am Rechner zeigt dasselbe (Browser: `502` von `api/signature.php`) | Das Frontend kommt mit `signature-handoff.php` im Backend nicht zurecht. Die Ursache steht im PHP-Fehlerlog des Frontends, siehe [Meldungen der Unterschrift per Smartphone](#meldungen-der-unterschrift-per-smartphone) |
| **Dialog am Rechner: „Die Seite ist veraltet …“** | Die PHP-Session ist abgelaufen (wie beim Absenden mit „Ungültiges Sicherheitstoken“): Seite neu laden. Kommt das oft vor, `session.gc_maxlifetime` erhöhen |
| **QR-Code führt auf eine falsche oder interne Adresse** | Reverse-Proxy reicht Host bzw. `X-Forwarded-Proto` nicht durch (siehe [Unterschrift per Smartphone](#unterschrift-per-smartphone)) |
| **Upload scheitert** | Dateityp bzw. -größe gegen `UPLOAD_ALLOWED_TYPES` / `UPLOAD_MAX_SIZE` im Frontend **und** Backend prüfen; Virenfund wird abgelehnt |
