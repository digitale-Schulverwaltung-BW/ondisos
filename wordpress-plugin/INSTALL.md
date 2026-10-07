# WordPress-Plugin installieren

Das Plugin **ondisos** bindet die Anmeldeformulare per Shortcode in Seiten Ihrer WordPress-Website ein:

```
[ondisos form="bs"]
```

Die Formulardaten werden **nicht** in WordPress gespeichert: Das Plugin leitet sie serverseitig und signiert an das Ondisos-Backend weiter, das Ihr Schulträger bzw. Medienzentrum betreibt.
Wie beide Teile zusammenspielen: [Betriebsmodell](../docs/betreiber/betriebsmodell.md).

Diese Anleitung beschreibt die Installation mit der **fertigen ZIP-Datei**, ohne Kommandozeile. (Installation aus dem Git-Repository für Entwickler und eigene Server: [INSTALL-AUS-GIT.md](INSTALL-AUS-GIT.md).)

## Inhalt

1. [Was Sie brauchen](#was-sie-brauchen)
2. [Installieren](#installieren)
3. [Einstellungen](#einstellungen)
4. [Formular einbinden](#formular-einbinden)
5. [Testen](#testen)
6. [Aktualisieren](#aktualisieren)
7. [Fehlersuche](#fehlersuche)
8. [Deinstallation](#deinstallation)
9. [Sicherheitshinweise](#sicherheitshinweise)

---

## Was Sie brauchen

**Von Ihrem Betreiber (Schulträger, Medienzentrum)**, der Ihre Schule im Backend angelegt hat:

- die **Backend-Adresse** (API), z. B. `https://backend.example.org/api`
- den **Tenant-Slug**, die Kennung Ihrer Schule, z. B. `bsz-karlsruhe`
- das **Tenant-API-Secret** (ein langer Schlüssel; er wird beim Anlegen nur einmal angezeigt)
- den **Schlüssel** der Formulare, die Sie einbinden wollen, z. B. `bs` (nennt Ihnen die Redaktion Ihrer Schule)

**Auf Ihrer Website:** WordPress 5.8 oder neuer, PHP 8.1 oder neuer mit der Erweiterung `curl`. Der **WordPress-Server muss das Backend erreichen können** (die Anfragen kommen vom Server, nicht aus dem Browser der Besucher).
Das Plugin benötigt ein Ondisos-Backend ab Version 3.0; Surveys aus dem Backend und der erweiterte Verbindungsstatus brauchen Backend 3.1. Aktuelle Plugin-Version: **3.1.0**.

## Installieren

1. **ZIP herunterladen:** Auf der [Releases-Seite](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/-/releases) steht die Datei `ondisos-<version>.zip` unter *Assets* („WordPress plugin (ZIP)“).
   Zur neuesten Version führt dieser Link: [Neuestes Release](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/-/releases/permalink/latest).
   Daneben liegt die Prüfsumme `ondisos-<version>.zip.sha256` (optional: `shasum -a 256 -c ondisos-<version>.zip.sha256`).
2. In WordPress: *Plugins → Installieren → Plugin hochladen*, die ZIP wählen, **Jetzt installieren**, dann **Plugin aktivieren**.
   Der Eintrag heißt „ondisos - Onboarding Digital Souverän + Open Source“.

Die ZIP ist für alle Schulen gleich und enthält keine Zugangsdaten; es ist nichts weiter zu kopieren. Den Formular-Cache (siehe unten) legt WordPress selbst unter `wp-content/uploads/ondisos-cache/` an.

## Einstellungen

*Einstellungen → Ondisos*. Oben steht der **Verbindungsstatus** (wird beim Öffnen geprüft): ob das Backend antwortet, ob der Tenant akzeptiert wird, ob ein Secret gesetzt ist und wie viele Formulare es gibt.

![Einstellungsseite des Plugins mit Verbindungsstatus](../docs/img/wordpress-einstellungen.png)

| Feld | Eintrag |
|---|---|
| **Backend API URL** | die Adresse vom Betreiber, z. B. `https://backend.example.org/api` |
| **Tenant-Slug** | Kennung Ihrer Schule; leer = `default` (nur bei einer Einzelinstallation sinnvoll) |
| **Tenant-API-Secret** | der Schlüssel vom Betreiber. Er wird nach dem Speichern **nie wieder angezeigt**; leer lassen bedeutet „unverändert“ |
| **Von E-Mail-Adresse** | Absender der Benachrichtigungs-E-Mails an die Schule |
| **Vertrauenswürdige Proxys** | Nur bei einem Reverse-Proxy vor WordPress: IPs/CIDR (kommagetrennt), deren `X-Forwarded-For` für die Client-IP der Anmeldung gilt. Leer = Header ignoriert. Siehe [Betrieb](../docs/betreiber/betrieb.md#reverse-proxy-client-ip-und-rate-limit) |

Beim **Speichern** einer URL, unter der das Backend nicht antwortet, erscheint eine Warnung (die URL wird trotzdem gespeichert). Ob das Secret zum Tenant passt, zeigt der Verbindungsstatus
(„Secret passt zum Tenant“, ab Backend 3.1) bzw. spätestens das erste Absenden.

**WordPress läuft selbst in Docker?** Dann ist `localhost` der Container, nicht Ihr Rechner, und `http://localhost:9080/api` findet das Backend nicht. Das Plugin warnt in diesem Fall. Passende Adressen:

| Aufbau | Backend API URL |
|---|---|
| Backend auf demselben Docker-Host, über einen Port veröffentlicht (Docker Desktop: Mac/Windows) | `http://host.docker.internal:9080/api` |
| Dasselbe unter Linux | im WordPress-Dienst `extra_hosts: ["host.docker.internal:host-gateway"]` eintragen, dann wie oben |
| WordPress und Backend teilen ein Docker-Netzwerk | `http://<dienstname>/api`, z. B. `http://backend/api` |
| Backend auf einem anderen Server | dessen Adresse, z. B. `https://backend.example.org/api` |

## Formular einbinden

Neue Seite (oder Beitrag) anlegen und den **Shortcode** einfügen, mit dem Schlüssel des Formulars:

```
[ondisos form="bs"]
```

`form` ist Pflicht und muss ein Formular sein, das im Backend für Ihre Schule existiert. Ein Attribut für die Schule gibt es nicht: Sie gehört zur Installation (Einstellungen), nicht zur Seite.
Mehrere Shortcodes auf einer Seite sind möglich.

**Vorausfüllen:** Felder lassen sich per Link vorbelegen, entweder mit `?prefill=<base64-JSON>` (den Link erzeugt das System nach einer Anmeldung) oder einfach per Parameter, z. B. `…/anmeldung/?Vorname=Erika&Klasse=5a`.
Übernommen werden nur Parameter, die als Feldname im Formular existieren (Tracking-Parameter wie `utm_source` werden ignoriert).

## Testen

1. Die Seite aufrufen: Das Formular erscheint.
2. Eine **Testanmeldung** absenden. Im Backend (Sekretariat) erscheint ein Eintrag mit Status *Neu*; die PDF-Bestätigung (falls aktiviert) lässt sich herunterladen.
3. Die Testanmeldung im Backend wieder löschen (Papierkorb).

Checkliste:

- [ ] Plugin ist aktiviert, *Einstellungen → Ondisos* öffnet sich, Verbindungsstatus ohne Warnung
- [ ] Der Shortcode zeigt das Formular (keine Meldung „Unknown form“), Schrift und Layout laden
- [ ] Absenden funktioniert, der Eintrag ist im Backend
- [ ] PDF-Download, Datei-Upload und Vorausfüll-Link funktionieren (falls eingerichtet)

## Aktualisieren

1. Die neue ZIP von der [Releases-Seite](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/-/releases) laden.
2. *Plugins → Installieren → Plugin hochladen*, die ZIP wählen und, wenn WordPress nachfragt, **Aktuelles durch hochgeladenes ersetzen**.
3. Bei aktiven Cache-Plugins den Cache leeren.

Ihre Einstellungen bleiben erhalten (sie liegen in der WordPress-Datenbank). Das Backend sollte zuerst aktualisiert sein; das Plugin 3.1 arbeitet mit Backend 3.0 und 3.1 zusammen.
Beim Wechsel von einer 2.x-Installation: [MIGRATION-3.0.md](../docs/betreiber/MIGRATION-3.0.md) (Tenant-Slug und Secret eintragen).

## Fehlersuche

Melden Sie sich als **Administrator** an und sehen Sie die Seite mit dem Formular an: Fehlermeldungen nennen dann Ursache und Adresse (Besucher sehen nur eine neutrale Meldung).
Zusätzlich zeigt *Einstellungen → Ondisos* den Verbindungsstatus.

| Symptom | Ursache und Lösung |
|---|---|
| `Error: The form is currently unavailable` (Besucher) bzw. `Error (shown to administrators only): …` | Backend nicht erreichbar, Schule abgelehnt oder unerwartete Antwort. Backend-URL und Slug prüfen; vom **WordPress-Server** aus testen: `curl "<Backend-URL>/health.php"` |
| `Error: Unknown form "bs"` | Das Backend kennt das Formular für Ihre Schule nicht: Schlüssel im Shortcode richtig? Wurde das Formular angelegt? (Redaktion oder Betreiber fragen) |
| Absenden: „Unauthorized“ | Tenant-API-Secret fehlt oder passt nicht zum Backend; ggf. wurde der Schlüssel erneuert, dann neuen Wert beim Betreiber erfragen |
| Absenden: „Backend-Zugang nicht konfiguriert“ | Es ist kein Tenant-API-Secret eingetragen |
| Formular lädt, PDF-Link schlägt fehl | Backend-URL und Erreichbarkeit vom WordPress-Server aus prüfen |
| Formular zeigt eine alte Fassung | Das Plugin fragt bei jedem Aufruf das Backend ab; eine alte Fassung erscheint nur, solange das Backend nicht erreichbar ist (der Cache hält bis zu 7 Tage). Erreichbarkeit prüfen; im Zweifel `wp-content/uploads/ondisos-cache/` leeren |
| Cache-Verzeichnis nicht beschreibbar | Das Plugin arbeitet dann ohne Cache; Schreibrechte auf `wp-content/uploads` prüfen |

Mehr Details liefert das WordPress-Debug-Log (`define('WP_DEBUG', true); define('WP_DEBUG_LOG', true);` in der `wp-config.php`, Ausgabe in `wp-content/debug.log`) und im Browser der Reiter *Netzwerk*
(Antwort von `admin-ajax.php?action=ondisos_submit`).

## Deinstallation

Plugin in WordPress **deaktivieren und löschen**: Dabei entfernt das Plugin seine Einstellungen (`ondisos_backend_url`, `ondisos_from_email`, `ondisos_trusted_proxies`, `ondisos_tenant_slug`, `ondisos_tenant_api_secret`).
Die Anmeldedaten liegen im Backend und bleiben unberührt.

## Sicherheitshinweise

- ✅ CSRF-Schutz über WordPress-Nonces; Ausgaben werden escaped.
- ✅ Anfragen ans Backend sind pro Schule signiert (HMAC-SHA256); das Secret verlässt den Server nie.
- ✅ Das Secret-Feld der Einstellungen ist schreibgeschützt (wird nie ins HTML zurückgegeben).
- ⚠️ **Das Secret liegt im Klartext in `wp_options`:** Datenbank-Zugriff und Backups von WordPress schützen.
  *Auswirkung im Ernstfall:* Wer das Secret erhält, kann im Namen Ihrer Schule Anmeldungen und Datei-Uploads an das Backend senden (**Schreibzugriff**, vom Rate-Limit gebremst).
  Anmeldedaten **lesen** kann er damit nicht, es sind keine Daten kompromittiert. Abhilfe: Der Betreiber erneuert den API-Schlüssel im Backend (der alte ist sofort ungültig), Sie tragen den neuen ein.
- ⚠️ Die Backend-Adresse in Produktion mit **HTTPS** (die Signaturen enthalten keinen Zeitstempel).
- ⚠️ Upload-Größen im Backend sind begrenzt (`UPLOAD_MAX_SIZE`); der Virenscan läuft im Backend.

## Mehrere WordPress-Websites

Dieselbe ZIP lässt sich auf mehreren Websites installieren. Jede Website hat **eigene** Einstellungen, bei verschiedenen Schulen jeweils mit dem passenden Tenant-Slug und -Secret.
Mehr zum Betrieb mehrerer Schulen: [MULTI-TENANT.md](../docs/betreiber/MULTI-TENANT.md).

## Weitere Informationen

Funktionen und Aufbau: [README.md](README.md) · Installation aus dem Git-Repository: [INSTALL-AUS-GIT.md](INSTALL-AUS-GIT.md) · Dokumentation: [docs/README.md](../docs/README.md) · Lizenz: MIT ([LICENSE](../LICENSE))
