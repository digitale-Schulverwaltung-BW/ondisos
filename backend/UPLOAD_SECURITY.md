# Upload Security

Dieses Dokument beschreibt die Schutzmaßnahmen des Datei-Uploads (`backend/public/api/upload.php`).
Überblick über Ablage und API: [src/UPLOADS.md](src/UPLOADS.md).

## Prinzip

Ein Upload wird **erst authentifiziert und dem Tenant zugeordnet**, dann wird die Datei geprüft, und zuletzt wird
sie unter einem **vom Server bestimmten** Namen in einem festen Verzeichnis abgelegt. Nichts vom Client (Name,
Endung, Content-Type) bestimmt Speicherort oder Dateityp.

## Authentifizierung und Tenant-Isolierung (ab 3.0)

- **Signatur:** `upload.php?tenant=<slug>` verlangt `X-Signature` = HMAC-SHA256 über
  `"{anmeldung_id}:{fieldname}:{original_filename}"` mit dem `api_secret` des Tenants (`HmacValidator`).
  Fehlt sie oder ist sie falsch → `401`. Bekannte Platzhalter-/Standard-Secrets validieren nie (`SecretPolicy`).
- **Zuordnung:** Die Anmeldung muss existieren **und zum authentifizierten Tenant gehören**
  (tenant-gefiltertes `AnmeldungRepository::findById()`); sonst `404` — fremde IDs erscheinen als `idor_attempt` im Audit-Log.
- **Tenant-Verzeichnis:** Dateien landen ausschließlich in `uploads/tenant-<id>/` des authentifizierten Tenants.
- **Audit:** `upload_success` und `virus_found` enthalten die `tenant_id` (`logs/audit.log`).

## Schutzmaßnahmen

### 1. Dateityp wird am Inhalt erkannt

`AnmeldungValidator::validateFile()`:

- **Größe:** maximal `UPLOAD_MAX_SIZE` (Standard 10 MB); leere Dateien werden abgelehnt.
- **MIME-Typ** wird aus dem **Dateiinhalt** ermittelt (`finfo`), nicht aus dem vom Client gesendeten Content-Type.
- **Whitelist** (`ALLOWED_MIME_TYPES`): PDF (`pdf`), JPEG (`jpg`, `jpeg`), PNG, GIF, WebP, SVG. Die Endung muss zum erkannten Typ passen.
  Office-Formate (`doc`, `docx`, …) sind **bewusst ausgeschlossen** (Makro-Risiko).

Damit wird z. B. PHP-Code, der als `evil.pdf` hochgeladen wird, mit `400` abgelehnt (live verifiziert).

### 2. Die Endung wird erzwungen

Die gespeicherte Datei erhält die **aus dem Inhalt abgeleitete** Endung, nicht die des Uploads. Ein Double-Extension-Angriff
(`evil.php.jpg`) kann keine ausführbare Endung erzeugen.

### 3. Dateinamen werden bereinigt, nicht abgelehnt

`FilenameSanitizer::sanitizeStem()` (auf den Namen ohne Endung, nach `basename()`):

- Umlaute werden transliteriert (`ä→ae, ö→oe, ü→ue, ß→ss`)
- alles außer `[a-zA-Z0-9_-]` wird zu `_`, mehrfache `_` werden zusammengezogen, Ränder abgeschnitten
- bleibt nichts übrig, heißt der Name `upload`

Folge: Pfadbestandteile (`../`), Punkte, Leerzeichen, Sonderzeichen, Null-Bytes, Unicode und versteckte Dateien (`.htaccess`)
können den Zielpfad oder die Endung nicht beeinflussen.

### 4. Fester Zielpfad

`uploads/tenant-<id>/{anmeldung_id}_{bereinigter_name}.{erzwungene_endung}` — Verzeichnis und Präfix kommen vom Server.
Beim **Download** wird zusätzlich per `realpath()` geprüft, dass die Datei im Verzeichnis des aktiven Tenants liegt
(`DownloadController`).

### 5. Virenscan

ClamAV (TCP/INSTREAM), aktivierbar mit `VIRUS_SCAN_ENABLED=true`: befallene Dateien werden mit `400` abgelehnt und als
`virus_found` protokolliert. Ist der Scanner nicht erreichbar, läuft der Upload im Standardmodus mit Log-Eintrag weiter;
mit `VIRUS_SCAN_STRICT=true` wird er stattdessen mit `503` abgelehnt. Die Dateien verlassen dabei nie die lokale Infrastruktur.

## Ablauf

```
1. POST upload.php?tenant=<slug>   (multipart: anmeldung_id, fieldname, file)
2. Tenant aus dem Slug auflösen (unbekannt/inaktiv → 401), CORS-Prüfung
3. X-Signature prüfen → sonst 401
4. Methode POST, anmeldung_id > 0, fieldname vorhanden
5. Anmeldung existiert und gehört zum Tenant → sonst 404 (+ idor_attempt)
6. Upload-Fehler prüfen (UPLOAD_ERR_OK)
7. AnmeldungValidator::validateFile(): Größe, MIME per Inhalt, Endung ↔ MIME
8. Virenscan (optional)
9. Zielname: {anmeldung_id}_{bereinigter Name}.{erzwungene Endung}
10. move_uploaded_file() nach uploads/tenant-<id>/, chmod 644, Audit-Eintrag
```

## Beispiele (Name des Uploads → gespeicherter Name, Anmeldung 5)

| Upload-Name (Inhalt echtes …) | Gespeichert |
|---|---|
| `Zeugnis Übersicht 2026.pdf` (PDF) | `5_Zeugnis_Uebersicht_2026.pdf` |
| `evil.php.jpg` (JPEG) | `5_evil_php.jpg` |
| `../../etc/passwd.pdf` (PDF) | `5_passwd.pdf` |
| `.htaccess` (Text) | abgelehnt — Text ist kein erlaubter Typ (wäre sonst `5_upload.<endung>`) |
| `evil.pdf` mit PHP-Code als Inhalt | **abgelehnt** (Typ passt nicht zur Endung) |
| beliebige Datei größer als `UPLOAD_MAX_SIZE` | **abgelehnt** |

## Tests

```bash
cd backend
composer test:filter UploadSecurityTest        # FilenameSanitizer: Bereinigung, Traversal, Sonderzeichen
composer test:filter MimeTypeValidationTest    # Typ-Erkennung per Inhalt
composer test:filter UploadPathIsolationTest   # Tenant-Verzeichnisse, Zuordnungsprüfung (Source-Guard)
composer test:filter UploadCleanupServiceTest  # Dateien beim endgültigen Löschen entfernen (exakter Präfix, nur eigener Tenant)
```

Der Endpoint `upload.php` selbst ist ein Script ohne Unit-Tests; Signatur, Zuordnung, Ablehnung getarnter Dateien und
der Erfolgsfall wurden live gegen ein laufendes Backend geprüft (Protokoll der Signatur:
[../MIGRATION-3.0.md § 7](../docs/betreiber/MIGRATION-3.0.md#7-eigene-api-clients)).

## Konfiguration

```bash
# .env (Backend)
UPLOAD_MAX_SIZE=10485760        # 10 MB in Bytes
VIRUS_SCAN_ENABLED=false        # true = ClamAV verwenden (Docker-Service clamav)
VIRUS_SCAN_STRICT=false         # true = Upload ablehnen, wenn der Scanner nicht erreichbar ist
```

PHP (`php.ini`): `upload_max_filesize` und `post_max_size` mindestens so groß wie `UPLOAD_MAX_SIZE`.
Webserver-Limits (`client_max_body_size` bei Nginx): [../DEPLOYMENT.md](../docs/betreiber/DEPLOYMENT.md).

## Empfehlungen

1. **Ausführung in `uploads/` sperren** (siehe unten)
2. **Dateirechte** `0644` (macht `upload.php`), Verzeichnisse `0755`
3. **HTTPS** zwischen Frontend und Backend
4. **Virenscan** in Production aktivieren
5. **Audit-Log** regelmäßig auf `virus_found`, `idor_attempt` und auffällige Uploads prüfen

### Server-Härtung

```apache
# Ausführung in uploads/ verhindern
<FilesMatch "\.(php|phtml|php3|php4|php5|pl|py|jsp|asp|sh|cgi)$">
    Require all denied
</FilesMatch>

# Downloads erzwingen
<FilesMatch "\.(pdf|jpg|jpeg|png|gif)$">
    Header set Content-Disposition attachment
</FilesMatch>
```

Downloads laufen über `download.php` und erfordern eine angemeldete Admin-Sitzung des Tenants.

## Bekannte Einschränkungen

- **Löschen:** Beim endgültigen Löschen einer Anmeldung (Hard-Delete, Auto-Expunge, manuelles Expunge) entfernt
  `UploadCleanupService` die Dateien `uploads/tenant-<id>/{anmeldung_id}_*`. Das Soft-Delete (Papierkorb) lässt sie bestehen,
  und Dateien, die schon vor dieser Funktion verwaist sind, werden nicht erfasst (siehe [src/UPLOADS.md](src/UPLOADS.md)).
- **Upload- und Download-Typen weichen ab:** Upload erlaubt zusätzlich WebP und SVG, `DownloadController` liefert aber nur
  `pdf, jpg, jpeg, png, gif, doc, docx, xls, xlsx, txt` aus — WebP/SVG-Uploads lassen sich im Backend nicht herunterladen.

## Referenzen

- OWASP: File Upload Cheat Sheet
- CWE-22: Path Traversal · CWE-434: Unrestricted Upload of File with Dangerous Type

Gilt für Ondisos 3.x.
