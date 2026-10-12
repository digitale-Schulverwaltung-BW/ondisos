# Ondisos 3.1.2 — Härtung der Admin-Anmeldung

Die Verwaltungsoberfläche lässt sich jetzt auf bestimmte Netze beschränken und verlangt von Platform-Admins im Mehrschul-Betrieb einen zweiten Faktor (Authenticator-App).
Dazu kommen die Ergebnisse eines Sicherheits-Audits: Content-Security-Policy, signierte API mit Zeitstempel und Missbrauchsschutz für die öffentliche Anmeldung.
Neue Funktionen für Sekretariat oder Redaktion gibt es kaum; die Änderungen betreffen Betrieb und Sicherheit. Upgrade von 3.1.1: [unten](#upgrade-von-311).

## Neu

**Zwei-Faktor-Anmeldung** (Betrieb und Wiederherstellung: [zwei-faktor.md](../../betreiber/zwei-faktor.md))
- TOTP mit jeder Authenticator-App (Aegis, FreeOTP, Google, Microsoft u. a.). Pflicht für **Platform-Admins im Mehrschul-Betrieb** (`TWO_FACTOR_REQUIRED=platform`), freiwillig für alle anderen; auf Wunsch für Schul-Admins oder alle verpflichtend.
- **Schonfrist:** Die ersten 10 Anmeldungen ohne zweiten Faktor (`TWO_FACTOR_GRACE_LOGINS`) zeigen nur einen Hinweis; danach ist der Einrichtungs-Assistent Pflicht (QR-Code, Bestätigung, **10 Recovery-Codes**).
- „Mein Konto“: Status, neue Recovery-Codes, Entfernen (wo nicht vorgeschrieben), jeweils mit erneuter Passworteingabe.
- Wiederherstellung: Recovery-Code, Zurücksetzen durch einen Platform-Admin unter *Tenants* (für Schul-Admins) oder `php reset-2fa.php <benutzer>` auf dem Server.
- Geheimnisse liegen verschlüsselt in der Datenbank (Schlüssel `TWO_FACTOR_KEY` in der `.env`); ein bereits benutzter Code und alle älteren werden abgewiesen; nach fünf falschen Codes beginnt die Anmeldung von vorn.

**Netz-Allowlist für Admin-Zugänge**
- `PLATFORM_ADMIN_ALLOWED_NETWORKS` (`.env`) für Platform-Admins und eine Liste je Schule unter *Tenants*. Geprüft bei der Anmeldung (Antwort wie bei falschem Passwort) und bei **jedem Seitenaufruf**; eine Sitzung aus einem fremden Netz endet.
- Gilt auch **ohne Login** (`AUTH_ENABLED=false`): Das Backend antwortet Anfragen von außerhalb des Verwaltungsnetzes mit `403`. Leer (Standard) heißt keine Einschränkung.
- Nicht betroffen: die signierte API für die Frontends (`/api/*`) und der PDF-Download per Token. Notausstieg: `.env` leeren bzw. `php clear-network-restriction.php <slug>`.

**Weiteres**
- **Name oder E-Mail genügt:** Eine Anmeldung braucht mindestens eines von beiden (bisher beides), damit Formulare ohne Rückfragebedarf aus Datensparsamkeit keine E-Mail-Adresse abfragen müssen. Beides leer wird weiter abgelehnt (`name_or_email`). Der Survey-Editor warnt nur noch, wenn weder Name noch E-Mail als Pflichtfeld vorhanden ist. Die Standard-Anmeldung fragt weiter beides ab.
- Hilfe-Button „?“ in der Navigationsleiste: öffnet die zur Seite passende Dokumentation (`HELP_BASE_URL` für eine eigene Kopie).
- Unterschriftsfelder (SurveyJS `signaturepad`) erscheinen als Bild in Detailansicht und PDF, in Excel und Mail als „[Unterschrift]“.
- Tenants lassen sich löschen (nur inaktive, ohne Formulare und Anmeldungen).

## Sicherheit

- **Content-Security-Policy** für Verwaltungsoberfläche und Standalone-Formularseite (Skripte nur mit Nonce; `CSP_MODE=enforce|report-only|off`); die Seiten haben keine Inline-Handler mehr.
- **Signaturformat 2** für die API (Zeitstempel, Nonce, Inhaltshash, bei Uploads Hash der Datei): ein abgefangener Request ist nach wenigen Minuten wertlos und nur einmal verwendbar. `API_SIGNATURE_MODE=compat` (Standard) nimmt das alte Format in der Umstellungsphase noch an und meldet es im Audit-Log (`api_legacy_signature`); `strict` schaltet es ab. Im Folgerelease 3.1.3 wird `strict` Standard.
- **Missbrauchsschutz der Anmeldung:** Rate-Limit je Besucher statt je Frontend-Server, Honeypot-Feld, Upload-Limits (20 Dateien, 50 MB je Anmeldung), Prüfung des Upload-Feldnamens gegen die Survey.
- **Login:** Drosselung (5 Fehlversuche je IP und Benutzername, 20 je IP in 15 Minuten), gleiche Laufzeit bei unbekanntem Benutzer, Session-Cookies im Code mit `HttpOnly`/`SameSite=Lax`/`Secure`.
- **Weitere Korrekturen aus dem Audit:** Excel-Formel-Injection (Eingaben immer als Text), CSRF bei Excel-Export, Mandantenwechsel und Dashboard, Metadaten der Anmeldung nicht mehr vom Browser setzbar, PDF-Logo nur aus erlaubten Verzeichnissen, SVG-Uploads nicht mehr erlaubt, Downloads mit `nosniff`, Docker: MySQL und phpMyAdmin nur an `127.0.0.1`.
- **Abhängigkeiten aktualisiert** (PhpSpreadsheet 3.10.8, mPDF 8.3.1, FPDI 2.6.8): `composer audit` meldet keine Advisories mehr.
- `AUTH_ENABLED=true` ist Standard in `.env.example` und im Docker-Entrypoint (bestehende `.env`-Dateien ändern sich nicht).

## Korrekturen

- Das Audit-Log war nach einem CLI-Aufruf als `root` (`docker compose exec backend php import-surveys.php …`, wie in der Installationsanleitung) für den Webserver nicht mehr beschreibbar, und **jede Anmeldung endete mit Fehler 500**. Das Log wird jetzt für `www-data` angelegt, und ein nicht schreibbares Log legt die Anwendung nicht mehr lahm (Meldung im PHP-Fehlerlog).
- **Keine Ansicht „Alle Tenants“ mehr (Mehrschul-Betrieb):** Platform-Admins arbeiten immer in einer gewählten Schule; ohne Wahl zeigt die Kopfzeile „Schule wählen“, und Seiten mit Anmeldungen leiten auf *Tenants* um. Damit entfallen die Fehler in dieser Ansicht (Excel-Export lieferte 500, Statuswechsel, Löschen und Sammelaktionen scheiterten). Das automatische Aufräumen archivierter Anmeldungen läuft je Schule. Im Einzelbetrieb ändert sich nichts.
- Der Feldname `website_url` ist in Surveys reserviert (Fehler beim Prüfen): So heißt das unsichtbare Spamschutz-Feld, eine Frage mit diesem Namen würde als Bot verworfen, obwohl der Besucher „Erfolg“ sieht. Bestehende Surveys mit diesem Namen bitte umbenennen.
- WordPress-Plugin: Bei einem kaputten Shortcode (z. B. `[ondisos form=""bs]`) nennt die Fehlermeldung den empfangenen Text und den wahrscheinlichen Grund (Anführungszeichen), statt zu behaupten, der Parameter fehle.
- Excel-Export ohne CSRF-Token liefert `403` statt `500`; das Slug-Muster beim Anlegen eines Tenants ist für aktuelle Browser gültig.

## Upgrade von 3.1.1

Reihenfolge wie immer: **Backend zuerst**, danach Frontends und WordPress-Plugin. Vorher sichern ([betrieb.md](../../betreiber/betrieb.md#sicherung-und-wiederherstellung)).

1. **`TWO_FACTOR_KEY` setzen** (Root-`.env`; erzeugen mit `openssl rand -hex 32`, sichern, später nicht ändern). Fehlt er, läuft die Anmeldung in der Schonfrist weiter, danach wird sie mit einer klaren Meldung abgelehnt.
2. Update: `git pull`, `docker compose up -d --build backend` (ohne Docker zusätzlich `composer install --no-dev --optimize-autoloader` und `php migrate.php`; neu nötige PHP-Erweiterungen: `iconv` und `sodium`). Die Migration legt die Spalte `tenants.admin_allowed_networks` und die Tabellen `admin_two_factor` und `admin_recovery_codes` an; im Log erscheint `Migration complete`.
3. **Mehrschul-Betrieb:** Platform-Admins sehen bei den nächsten Anmeldungen den Hinweis zur Zwei-Faktor-Anmeldung und müssen sie spätestens nach 10 Anmeldungen einrichten. Wer das vermeiden will, setzt vorübergehend `TWO_FACTOR_REQUIRED=off`.
4. **Einzelbetrieb:** keine Pflicht (`platform` greift nur im Mehrschul-Betrieb); Einrichtung freiwillig unter *Mein Konto*.
5. **Optional:** `PLATFORM_ADMIN_ALLOWED_NETWORKS` und Netze je Schule setzen, z. B. `10.10.0.0/24`. Hinter einem Reverse-Proxy zuerst `TRUSTED_PROXIES` eintragen, sonst sieht das Backend nur die Proxy-Adresse ([betrieb.md](../../betreiber/betrieb.md#reverse-proxy-client-ip-und-rate-limit)).
6. **Uhren:** Die Server von Frontend und Backend dürfen höchstens 5 Minuten abweichen (Signaturformat 2). Bis ein Frontend aktualisiert ist, wird sein altes Format im Modus `compat` weiter angenommen.
7. **Frontends und Plugin:** aktualisieren (WordPress: Plugin-ZIP 3.1.2). Taucht im Audit-Log kein `api_legacy_signature` mehr auf, kann der Betreiber `API_SIGNATURE_MODE=strict` setzen.

Rückfall: `git checkout v3.1.1` und neu bauen. Die Migration ist rein additiv (neue Spalte und Tabellen); zum Zurückrollen genügt der alte Code, die Datenbank muss nicht zurückgespielt werden.

## Hinweise

- Ein TOTP-Code gilt nur einmal: Wer sich ab- und innerhalb derselben 30 Sekunden wieder anmeldet, wartet auf den nächsten Code.
- Der Platform-Admin aus der `.env` wird über seinen Benutzernamen zugeordnet; wird `ADMIN_USERNAME` geändert, beginnt er ohne zweiten Faktor.
- Das WordPress-Plugin trägt dieselbe Versionsnummer (3.1.2).
- Docker: Fehlt `ADMIN_PASSWORD_HASH`, warnt der Start jetzt auch im Mehrschul-Betrieb; vorher blieb die Anmeldung ohne Hinweis verschlossen.

## Nicht enthalten / vorgemerkt für 3.1.3

- **Passkeys (WebAuthn)** als weiterer zweiter Faktor ([PLAN-3.1.2.md](../plans/PLAN-3.1.2.md#ap4--passkeys-webauthn-verschoben-auf-313)).
- `API_SIGNATURE_MODE=strict` als Standard.
- Bekannte Einschränkungen: [CLAUDE.md](../../../CLAUDE.md#bekannte-einschränkungen) und [sicherheit.md](../sicherheit.md).
