# PLAN 3.1.1 – WordPress-Plugin ohne Shell: ZIP, Verbindungscode, Update-Prüfung

**Status:** Entwurf, nicht dringend. Ergebnis einer Beratung, noch nicht umgesetzt.

## Ausgangslage

Schulen betreiben WordPress teils in einem Hosting ohne Shell-Zugang. Das Backend wird zentral
(z. B. vom Stadtmedienzentrum Karlsruhe) für mehrere Schulen gehostet und bleibt shell-pflichtig
(Docker, `migrate.php`, CLI-Skripte): Diese Rolle setzt Wissen voraus und wird nicht vereinfacht.

Das Plugin braucht bereits weder Composer noch CLI (eigener Autoloader, Surveys kommen aus dem
Backend). Die Schule soll nur noch eine ZIP hochladen, einen Code einfügen und das Plugin per
Update-Meldung aktuell halten.

## Ziel

1. Plugin als fertige ZIP, installierbar über *Plugins → Installieren → Hochladen* (kein FTP).
2. Verbindung zum Backend über **einen** Verbindungscode statt dreier Felder.
3. Updates im WP-Dashboard, ohne Shell und ohne manuellen Upload.

Nicht Ziel: Backend ohne Shell betreiben (Web-Installer, Shared-Hosting-Release).

## Voraussetzung: Erreichbarkeit des Backends (Betrieb)

Externe WordPress-Hostings müssen `submit.php`, `upload.php`, `form-config.php` und den
PDF-Download erreichen, nicht aber den Admin-Bereich.

- Reverse Proxy mit Pfad-Whitelist: `/api/*` und `/pdf/download.php` öffentlich, alles andere nur intern.
- HTTPS verpflichtend (Signaturen haben keinen Replay-Schutz).
- Muss vor dem ersten externen Tenant stehen. Kein Code, aber in `DEPLOYMENT.md` zu dokumentieren.

## Arbeitspakete

### AP1 – Release-Build der Plugin-ZIP

- Build-Schritt (Make-Target und/oder CI-Job) erzeugt `ondisos-<version>.zip` aus `wordpress-plugin/`.
- Ohne Entwicklungsdateien (Tests, `.git*`, Doku außer `INSTALL.md`); Ordnername im ZIP = Plugin-Slug,
  damit WordPress beim Update dasselbe Verzeichnis überschreibt.
- Version aus dem Plugin-Header; Prüfsumme (SHA-256) wird mit erzeugt.
- **Keine** Tenant-Daten oder Secrets in der ZIP: sie ist allgemein und weitergebbar.

### AP2 – Verbindungscode (Backend und Plugin)

Backend (`tenants.php`):
- Button „Anbindungscode erzeugen" je Tenant. Der Code enthält Backend-URL, Slug und Secret, dazu
  Formatversion und Prüfsumme (Tippfehler erkennen, Format später änderbar).
- Das Secret ist nur im Moment des Erzeugens sichtbar. Neu erzeugen ⇒ Secret wird rotiert
  (das ist zugleich die Secret-Rotation für Tenants). Audit-Log-Eintrag.

Plugin (*Einstellungen → Ondisos*):
- Ein Eingabefeld für den Code (write-only wie bisher das Secret); Anzeige nach dem Speichern nur Slug und URL.
- Button „Verbindung testen": `health.php`, dann `form-config.php` mit Signatur. Eigene Meldungen für
  ungültigen Code, Backend nicht erreichbar, Tenant unbekannt/inaktiv, falsches Secret, Backend-Version zu alt.
- Optional: Konstante `ONDISOS_CONNECTION` in `wp-config.php` ersetzt die Einstellungsseite (für Betreuer,
  die es fest verdrahten wollen).
- Bestehende Einzelfelder bleiben für Altinstallationen funktionsfähig.

### AP3 – Update-Prüfung

- Das Plugin fragt periodisch (WP-Transient, z. B. 12 h) eine `update.json` ab und meldet Updates über die
  üblichen WP-Mechanismen (`pre_set_site_transient_update_plugins`, `plugins_api`).
- `update.json`: Version, Download-URL, SHA-256 der ZIP, Changelog, „getestet mit WP", PHP-Mindestversion,
  Mindest-Backend-Version.
- Sicherheit: Quelle ist im Plugin fest verdrahtet (nicht aus Einstellungen oder Antworten übernommen),
  nur HTTPS, ZIP wird vor der Installation gegen die Prüfsumme verifiziert (besser: Signatur).
  Ohne diese Prüfung wäre die Update-Funktion ein Einfallstor in jede Schul-WordPress-Installation.
- Kompatibilität: Plugin meldet seine Version an das Backend (z. B. Header bei `form-config.php`); das Backend
  nennt seine Mindest-Plugin-Version. Plugin zeigt bei Abweichung einen Hinweis statt halb zu funktionieren.
- Fehlschlag der Update-Abfrage darf nie das Formular beeinträchtigen (still, nur Log/Admin-Hinweis).

## Offene Entscheidungen

| Frage | Optionen | Tendenz |
|---|---|---|
| Wo liegt `update.json` und die ZIP? | beim Stadtmedienzentrum; GitLab-Releases (öffentlich lesbar nötig) | zentral beim Stadtmedienzentrum |
| ZIP-Integrität | SHA-256 aus `update.json`; echte Signatur | SHA-256 minimal, Signatur wenn der Aufwand vertretbar ist |
| Code-Übergabe an die Schule | Mail; Passwortmanager; persönlich | nicht per unverschlüsselter Mail |
| Plugin-Slug / Name im Verzeichnis | bisheriger Slug beibehalten | beibehalten (Update-Kompatibilität) |

## Reihenfolge

1. AP1 + AP2 (größter Nutzen für die Schulen)
2. AP3, sobald die erste echte Release-Version existiert (vorher nicht sinnvoll testbar)

## Tests und Abnahme

- Unit: Code-Format (Roundtrip, Prüfsumme, manipulierter Code, unbekannte Formatversion), Versionsvergleich,
  Prüfsummenprüfung der Update-ZIP.
- Manuell: frische WordPress-Instanz ohne Shell, ZIP hochladen, Code einfügen, Verbindung testen,
  Formular anzeigen und absenden; danach Update auf eine höhere Version auslösen.
- Negativtests: falscher Code, rotiertes Secret, Backend aus, manipulierte ZIP, `update.json` nicht erreichbar.
