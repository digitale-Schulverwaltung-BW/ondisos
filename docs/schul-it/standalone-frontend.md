# Standalone-Frontend einrichten

Das Standalone-Frontend ist die Alternative zum [WordPress-Plugin](../../wordpress-plugin/INSTALL.md): ein kleines PHP-Programm, das die Anmeldeformulare
auf einem eigenen Webserver anzeigt (z. B. `anmeldung.meineschule.de`). Es speichert selbst keine Anmeldungen, sondern übergibt sie signiert an das Backend
([Betriebsmodell](../betreiber/betriebsmodell.md)).

## Voraussetzungen

- Webserver (Apache oder Nginx) mit **PHP 8.0+** (Erweiterung `curl`), mit HTTPS
- Vom Webserver aus erreichbar: das **Backend** (URL, Tenant-Slug und Tenant-Secret erhalten Sie vom Betreiber)
- Schreibrecht des Webserver-Benutzers auf das Verzeichnis `frontend/cache`
- Kein Composer, keine Datenbank nötig

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

## Texte der Oberfläche anpassen

Meldungen und Beschriftungen des Frontends stehen in `config/messages.php`. Eigene Änderungen gehören in `config/messages.local.php`
(Vorlage: `config/messages.example.php`). Diese Datei wird von Git ignoriert und bleibt bei Updates erhalten.

## Aktualisieren

Neuen Stand ins Verzeichnis holen (`git pull` bzw. Release entpacken). `.env` und `config/messages.local.php` bleiben unberührt, ein Neustart ist nicht nötig.
Das Backend sollte zuerst aktualisiert werden (siehe [Betriebsmodell](../betreiber/betriebsmodell.md#betrieb-im-alltag)).

## Fehlersuche

| Beobachtung | Ursache und Abhilfe |
|---|---|
| **Wartungsseite (503)** | Backend nicht erreichbar oder Tenant abgelehnt. Die Ursache steht im PHP-Fehlerlog (`FormConfigLoader: form "…" not loaded (unreachable\|unauthorized\|error)`). `BACKEND_API_URL` und `TENANT_SLUG` prüfen; vom Server aus `curl "$BACKEND_API_URL/health.php"` |
| **404 „Formular nicht gefunden"** | Das Backend kennt das Formular für Ihre Schule nicht: Schlüssel richtig geschrieben? Wurde es angelegt? (Redaktion bzw. Betreiber fragen) |
| **Absenden: „Unauthorized" / 401** | `TENANT_API_SECRET` passt nicht zum Tenant, oder das Secret wurde im Backend neu erzeugt. Neues Secret beim Betreiber erfragen |
| **Formular erscheint nicht, obwohl das Backend läuft** | Formular ohne Speicherung im Backend und ohne gültige Empfänger-Adresse wird nicht angezeigt; Redaktion trägt unter *Formulare* eine Empfänger-Adresse ein |
| **Upload scheitert** | Dateityp bzw. -größe gegen `UPLOAD_ALLOWED_TYPES` / `UPLOAD_MAX_SIZE` im Frontend **und** Backend prüfen; Virenfund wird abgelehnt |
