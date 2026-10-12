# PLAN 3.1.2 – Härtung der Admin-Anmeldung: Netz-Allowlist und Zwei-Faktor-Anmeldung

**Status:** umgesetzt, Release **3.1.2** ist gebaut (Release Notes, Abnahmeprotokoll). AP1, AP2, AP2b, AP3, AP5 und AP6 sind fertig; AP4 (Passkeys) ist auf **3.1.3** verschoben. Offen vor dem Tag: die manuellen Prüfungen auf einer bestehenden Instanz (siehe [ABNAHME.md](../releases/ABNAHME.md#v312)).
Die Abschnitte der Arbeitspakete unten sind teils noch im Entwurfston geschrieben; maßgeblich ist der Stand im Code, in [sicherheit.md](../sicherheit.md) und [zwei-faktor.md](../../betreiber/zwei-faktor.md).

**Außerhalb des ursprünglichen Plans** kamen in 3.1.2 hinzu (Einzelheiten im [Changelog](../changelog.md#312)): Content-Security-Policy mit Nonce, Missbrauchsschutz der öffentlichen Anmeldung (Rate-Limit je Besucher, Honeypot, Upload-Grenzen), Tenant löschen, Unterschriftsfelder, „Name oder E-Mail“, Wegfall der Ansicht „Alle Tenants“, Abhängigkeits-Updates (`composer audit`) und der Hilfe-Button.

**Stand der Voraussetzungen:** Die Client-IP wird zentral über `App\Utils\ClientIp` ermittelt (`X-Forwarded-For` nur hinter Proxys aus `TRUSTED_PROXIES`, siehe [sicherheit.md](../sicherheit.md) und [konfiguration.md](../konfiguration.md)).
`AuditLogger`, `RateLimiter`, `editor_rate_limit.php` und `download.php` nutzen sie bereits. Damit ist die Grundlage für die Netz-Allowlist (AP2) geschaffen.

## Ausgangslage

- **Platform-Admin** ist kein Datenbankbenutzer: Zugangsdaten stehen in `.env` (`ADMIN_USERNAME`, `ADMIN_PASSWORD_HASH`), geprüft in `LoginService::attemptPlatformAdminLogin()`.
  Es gibt keine Zeile, an der ein zweiter Faktor oder ein Zähler hängen könnte.
- **Tenant-Admins** liegen in `tenant_admins` (Passwort-Hash, `active`). Auch sie melden sich nur mit Passwort an.
- **Login-Ablauf** (`backend/public/login.php`): CSRF-Prüfung, Passwortprüfung, `session_regenerate_id(true)`, Session-Schlüssel (`admin_logged_in`, `is_platform_admin`, `tenant_id`). Gegen Brute Force gibt es nur eine Verzögerung von 0,5 s pro Fehlversuch.
- **`inc/auth.php`** prüft pro Request nur `admin_logged_in`, Session-Timeout und Tenant-Kontext. Es gibt keine Netz- und keine Faktor-Prüfung.
- Schutz vor einem Admin-Zugriff aus fremden Netzen besteht heute nur über die Betriebsarchitektur (Backend im Intranet bzw. Reverse Proxy), nicht in der Anwendung.

## Ziel

1. **Netz-Allowlist:** Admin-Anmeldung und Admin-Sitzungen nur aus zulässigen Netzen, pro Tenant einstellbar; für Platform-Admins eine eigene, globale Liste. Dieselbe Liste begrenzt auch den **Betrieb ohne Login** (Modell A) auf das Verwaltungsnetz (AP2b).
2. **Zwei-Faktor-Anmeldung:** verpflichtend für Platform-Admins im Mehrmandantenbetrieb; TOTP (QR-Code); Einführung mit Schonfrist und Wizard. Passkeys folgen in 3.1.3 (AP4).

Nicht Ziel: Single Sign-on (SAML, OIDC), E-Mail- oder SMS-Codes, Einschränkung der signierten Frontend-API (`/api/*`; sie ist durch HMAC und CORS geschützt und kommt von Schul-Webservern, nicht von Admin-Arbeitsplätzen).

## Übergabe an die 2FA-Sitzung (AP3 umgesetzt, AP4 in 3.1.3)

AP1 (CIDR-Hilfsklasse) sowie AP2 und AP2b (Netz-Allowlist, auch ohne Login) sind umgesetzt (`App\Utils\CidrList`, `App\Services\AdminNetworkPolicy`, Prüfung in `inc/auth.php` und `login.php`). AP3 (TOTP, Wizard, Recovery) ist umgesetzt, AP4 (Passkeys) folgt. AP5 und AP6 sind umgesetzt.

**Entscheidungen für AP3/AP4:**

- **Geltungsbereich der Pflicht:** `TWO_FACTOR_REQUIRED=platform` gilt nur bei `MULTI_TENANT_ENABLED=true`. Mehrmandantenbetrieb ist das beste verfügbare Indiz dafür, dass die Instanz aus dem Internet erreichbar ist; im Einzelbetrieb bleibt 2FA optional einrichtbar.
- **Replay-Schutz:** Die Faktor-Tabelle bekommt eine Spalte für den letzten akzeptierten TOTP-Zeitschritt (`last_totp_step`).
- **Fehlversuche beim zweiten Faktor:** Nach etwa 5 falschen Codes wird `pending_2fa` verworfen (neuer Passwort-Login nötig). Die Netzprüfung gilt auch im Zwischenzustand.
- **`TWO_FACTOR_KEY`:** Pflicht bei aktiver 2FA; fehlt er, wird abgelehnt statt unverschlüsselt gespeichert (fail-closed, klare Meldung). Der Docker-Entrypoint erzeugt ihn wie die anderen Secrets. Keine Schlüsselrotation in 3.1.2 (dokumentieren).
- **Recovery-Codes:** zufällig und lang genug, deshalb als SHA-256 gespeichert (kein `password_hash`).
- **Bibliotheken:** `spomky-labs/otphp` und ein QR-Code als SVG ohne GD (z. B. `bacon/bacon-qr-code`); vor der Übernahme Lizenz und Abhängigkeiten prüfen. Dasselbe für `web-auth/webauthn-lib` in AP4.

**Was die Sitzung vorfindet und nutzen soll:**

- `App\Utils\ClientIp` (mit `TRUSTED_PROXIES`) und `AuditLogger` (u. a. `login_blocked`, `api_legacy_signature`); `LoginThrottle` und `RateLimiter` für Fehlversuche, auch für TOTP-Codes wiederverwendbar.
- `App\Utils\SessionCookie` setzt `HttpOnly`, `SameSite=Lax` und `Secure` vor `session_start()`; `session_regenerate_id(true)` gehört wie geplant erst nach dem **vollständigen** Login (Passwort und zweiter Faktor).
- `AUTH_ENABLED=true` ist Standard; `inc/auth.php` kehrt bei ausgeschalteter Authentifizierung früh zurück. AP2b muss die Allowlist **vor** diesem Zweig prüfen.
- **Content-Security-Policy:** Skripte nur mit Nonce, **keine Inline-Handler**. Neue Seiten (`two_factor.php`, `two_factor_setup.php`, Passkey-Registrierung) laden ihr JavaScript als Datei unter `assets/js/` oder tragen `nonce="<?= \App\Utils\ContentSecurityPolicy::nonce() ?>"`; Bestätigungen laufen über `data-confirm`. `ContentSecurityPolicyTest` schlägt sonst fehl. Die CSP wird in `inc/header.php` und `login.php` gesendet: Seiten ohne `header.php` (wie die 2FA-Seiten, falls eigenständig) rufen `\App\Utils\ContentSecurityPolicy::send()` selbst auf, vor jeder Ausgabe.
- Das Sicherheitsverhalten von `auth.php` (Zwischenzustand `pending_2fa` gibt keinen Zugang) braucht den Skript-übergreifenden Test, wie unter „Tests und Abnahme“ beschrieben.

**Reihenfolge innerhalb der Sitzung:** AP1, AP2/AP2b, AP3, AP4 wie unten unter „Reihenfolge“. Wer `auth.php` ändert, ergänzt `sicherheit.md`, `konfiguration.md` und `docs/README.md` (neue Dateien).

## Arbeitspakete

### AP1 – CIDR-Hilfsklasse herauslösen (Refactoring) – umgesetzt

`ClientIp::matches()` und `isTrusted()` sind `private`, `parseTrustedProxies()` ist auf Proxys zugeschnitten. Für die Allowlist wird derselbe Code gebraucht.

- Neue Klasse `App\Utils\CidrList` (reine Logik, ohne Umgebung): `parse(string): CidrList` (IPs und CIDR, IPv4 und IPv6; Trenner Komma, Semikolon, Zeilenumbruch) mit `entries()`, `invalid()` (ungültige Einträge werden gemeldet statt still verworfen), `contains(string $ip): bool` und `normalize()` wie zuvor in `ClientIp`.
- `ClientIp` nutzt `CidrList`; Verhalten unverändert (`ClientIpTest` bleibt grün, ergänzt um `CidrListTest`).
- Frontend-Pendant `Frontend\Utils\ClientIp` bleibt wie es ist (eigenständig ausgeliefert); nur ändern, wenn dort dieselbe Logik angepasst werden müsste (`ClientIpTest` prüft die Übereinstimmung).

### AP2 – Netz-Allowlist für Admin-Zugänge – umgesetzt

**Datenmodell**

- Migration: `tenants.admin_allowed_networks TEXT NULL` (CIDR-Liste, kommagetrennt oder zeilenweise; `NULL`/leer = keine Einschränkung). Auch in `schema.sql` und `architektur.md` nachziehen.
- Platform-Admins: `.env` `PLATFORM_ADMIN_ALLOWED_NETWORKS` (leer = keine Einschränkung). Sie liegen ohnehin in `.env`; eine Datenbankeinstellung wäre bei Aussperrung nicht erreichbar.

**Prüfung**

- Neuer Service `AdminNetworkPolicy` (`isAllowed(?int $tenantId, bool $isPlatformAdmin, string $ip): bool`), gespeist aus `ClientIp::get()` und `CidrList`. Eine gesetzte Liste ohne gültigen Eintrag (Tippfehler) sperrt alle, statt still zu öffnen.
- **Beim Login** (`login.php`), *nach* der Passwortprüfung: Ist die IP nicht zulässig, gibt es dieselbe Meldung wie bei falschem Passwort (kein Hinweis, dass die Zugangsdaten stimmten) und einen Audit-Eintrag `login_network_denied` mit Benutzer und IP.
- **Bei jedem Request** (`inc/auth.php`): Die Prüfung läuft pro Seitenaufruf, nicht nur beim Login. Eine gestohlene Session ist aus einem fremden Netz sonst nutzbar. Bei Verstoß: Session beenden, Audit-Eintrag, Redirect auf `login.php`.
- **Nicht betroffen:** `/api/*` und `pdf/download.php` (Token-Endpunkt); sie haben eigene Schutzmechanismen und andere Aufrufer.
- Platform-Admins unterliegen immer nur der Platform-Liste, auch nach dem Wechsel in einen einzelnen Tenant.
- Bei Verstoß während der Sitzung führt der Redirect auf `login.php?network=1` (Hinweis „Zugriff aus diesem Netz nicht zulässig“).

**Pflege und Bedienung**

- Das Feld erscheint in `tenants.php` (Karte „Admin-Zugang nur aus bestimmten Netzen“, eigene Aktion `update_networks`). Die Seite ist ohnehin Platform-Admins vorbehalten; Tenant-Admins können die Einstellung weder sehen noch ändern, ein gestohlener Account kann die Sperre also nicht selbst aufheben. Ungültige Einträge werden beim Speichern abgelehnt.
- **Selbstaussperrung:** Die Bearbeitenden sind Platform-Admins und unterliegen der Tenant-Liste nicht; eine Bestätigungsabfrage entfällt deshalb. Die Karte zeigt die aktuelle Adresse als Eingabehilfe und nennt den Notausstieg.
- **Fehlkonfigurations-Warnung:** Trägt die Anfrage `X-Forwarded-For`, obwohl die Verbindung nicht von einem Proxy aus `TRUSTED_PROXIES` kommt (`ClientIp::forwardedHeaderIgnored()`), sieht das Backend nur die Proxy-IP. Die Karte warnt dann, dass die Liste so nicht wirkt. (Das Kriterium „private Adresse“ wäre im Intranet ein Fehlalarm.)
- **Notausstieg:** CLI-Skript `backend/clear-network-restriction.php <slug>` (Platform-Betreiber mit Shell-Zugang; idempotent wie `migrate.php`); für Platform-Admins genügt, die `.env`-Variable zu leeren.
- Audit-Log-Eintrag bei jeder Änderung (`admin_networks_changed`, alt/neu).

### AP2b – Allowlist für den Betrieb ohne Login (Modell A) – umgesetzt

**Hintergrund:** Ohne Login (`AUTH_ENABLED=false`, Einzelbetrieb) gilt jeder Nutzer als Platform-Admin; der einzige Schutz ist das Netz. Zulässig ist das nur im **Verwaltungsnetz** (Sekretariat und erweiterte Schulleitung, vom Schüler-, Lehrer- und Gästenetz getrennt; [Betriebsmodell](../../betreiber/betriebsmodell.md#verwaltungsoberfläche-verwaltungsnetz-oder-login)).
Die Anwendung soll diese Annahme selbst durchsetzen können, damit ein Fehler in der Netzaufteilung nicht sofort alle Daten öffnet. AP2 prüft dagegen nur bei Login und Sitzung; im Modell A gibt es beides nicht.

- **Eine Liste für beide Fälle:** `PLATFORM_ADMIN_ALLOWED_NETWORKS` (AP2) gilt im Modell A für **alle** Browser-Anfragen, da dort jeder Platform-Admin ist. Keine zweite Variable.
- **Einhängepunkt:** in `inc/auth.php` **vor** dem frühen Rückkehr-Zweig für „Auth ausgeschaltet“ (heute `return`, bevor überhaupt etwas geprüft wird). Nicht betroffen bleiben wie in AP2 `/api/*` und `pdf/download.php`. Neutrale 403-Seite, Audit-Ereignis `admin_network_denied` (Adresse, Pfad).
- **Standard bleibt „keine Einschränkung“** (leere Liste), damit ein Update niemanden aussperrt. Einen Hinweis in der Oberfläche gibt es bewusst nicht: Wer `AUTH_ENABLED=false` setzt, entscheidet das selbst, und der Einzelbetrieb mit Login ist nicht sicherer als der Betrieb ohne Login im Verwaltungsnetz, solange alle Konten dieselben Rechte haben (siehe Offene Entscheidungen, Rollen).
- **Client-IP:** nur `ClientIp` (`REMOTE_ADDR`, `X-Forwarded-For` nur hinter `TRUSTED_PROXIES`). Hinter einem Reverse-Proxy ohne Vertrauenseintrag sieht das Backend nur die Proxy-Adresse; AP2 warnt dann bereits bei Fehlkonfiguration.
- **Selbstaussperrung:** Die Variable steht in der `.env` (nicht in der Oberfläche); Notausstieg ist, sie dort zu leeren. Im Hinweis- und Setup-Text das Verwaltungsnetz als Beispiel nennen (`10.10.0.0/24`).
- **Tests:** Modell A mit leerer Liste (alles erlaubt), mit Liste (fremde Adresse ⇒ 403, API und PDF-Token unberührt), `X-Forwarded-For` ohne `TRUSTED_PROXIES` wirkungslos, Mehrschul-Betrieb unverändert.

### AP3 – Zugangsdaten-Tabelle und TOTP – umgesetzt

**Umgesetzt wie geplant, mit diesen Abweichungen und Ergänzungen:**

- Tabellen `admin_two_factor` (mit `last_totp_step`) und `admin_recovery_codes`, Admin-Schlüssel `tenant:<id>` / `platform:<ADMIN_USERNAME>`; Klassen `TwoFactorPolicy`, `TwoFactorLogin`, `TwoFactorSession`, `TwoFactorCrypto`, `TotpService`, `RecoveryCodes`, `LoginSession`, `TwoFactorRepository`; Seiten `two_factor.php`, `two_factor_setup.php`, `account.php`; Skript `reset-2fa.php`. Betrieb und Wiederherstellung: [zwei-faktor.md](../../betreiber/zwei-faktor.md).
- **Wizard speichert erst im letzten Schritt.** Secret und Recovery-Codes liegen bis „Fertig“ nur in der Sitzung; wer abbricht, hinterlässt keinen halb eingerichteten Faktor. Der Schritt „Methode wählen“ entfällt, solange es nur den Authenticator gibt (AP4).
- **Mein Konto:** ein Faktor je Konto (Status, Recovery-Codes erneuern, Entfernen nur, wo er nicht vorgeschrieben ist; beides mit Passwort). „Faktor hinzufügen“ kommt mit den Passkeys.
- **Schonfrist-Hinweis** erscheint einmal pro Anmeldung („Später“ = weiterarbeiten). Ohne `TWO_FACTOR_KEY` verbraucht eine Anmeldung keine Schonfrist; nach Ablauf wird abgelehnt.
- **Zurücksetzen:** `reset-2fa.php <benutzer>` setzt alle Konten dieses Namens zurück (Platform-Admin und gleichnamige Schul-Admins); Platform-Admins setzen Schul-Admins unter *Tenants* zurück.
- **Test:** `TwoFactorScriptsTest` stellt statisch sicher, dass kein Skript außer den bekannten Ausnahmen `inc/auth.php` auslässt und nur Login und die beiden 2FA-Seiten den Zwischenzustand lesen; der Ablauf wurde zusätzlich Ende-zu-Ende gegen einen Wegwerf-Server geprüft (Schonfrist, Wizard, Codes, Replay, Recovery-Code, Abbruch nach 5 Fehlversuchen).

**Datenmodell** (Migration, tenant-sicher dokumentieren)

- Neue Tabelle für zweite Faktoren, adressiert über einen stabilen Admin-Schlüssel: `tenant_admins.id` für Tenant-Admins; der `.env`-Platform-Admin bekommt eine feste Kennung (z. B. `platform:<ADMIN_USERNAME>`), solange er nicht in die Datenbank wandert (siehe offene Entscheidungen).
- Spalten (Entwurf): Admin-Schlüssel, `totp_secret_enc` (verschlüsselt, siehe unten), `totp_confirmed_at`, `grace_logins_used`, `created_at`, `updated_at`.
- Recovery-Codes in eigener Tabelle (nur Hashes, einmalig verwendbar, `used_at`).
- **Verschlüsselung des TOTP-Secrets:** Schlüssel aus `.env` (neuer Wert `TWO_FACTOR_KEY`, Pflicht, wenn 2FA aktiv); Verschlüsselung mit `sodium_crypto_secretbox`. Ohne Verschlüsselung wäre ein Datenbankabzug zugleich ein Abzug aller zweiten Faktoren.

**TOTP**

- Bibliotheken: `spomky-labs/otphp` (Code-Prüfung, RFC 6238) und ein QR-Generator (`endroid/qr-code` oder reines SVG ohne Bild-Erweiterungen, damit keine PHP-Extension nötig wird). Beide prüfen: Lizenz, Pflege, Abhängigkeiten.
- Prüfung mit Toleranz von ±1 Zeitschritt; **bereits benutzte Codes** werden abgewiesen (letzter akzeptierter Zeitschritt pro Admin speichern, sonst Replay innerhalb von 30 s).
- Rate-Limit für Codeeingaben (`RateLimiter`, pro Benutzer und IP), zusätzlich zur Verzögerung beim Fehlversuch.

**Login-Ablauf mit Zwischenzustand**

- Nach erfolgreichem Passwort (und bestandener Netzprüfung) setzt `login.php` *nicht* sofort `admin_logged_in`, sondern `pending_2fa` (mit Admin-Schlüssel, Zeitstempel, kurzer Gültigkeit von z. B. 5 Minuten). Erst `two_factor.php` setzt nach bestandener Prüfung die bisherigen Session-Schlüssel.
- **Kernrisiko:** Zwischenzustand darf keinen Zugang zu geschützten Seiten geben. Deshalb prüft `inc/auth.php` weiterhin ausschließlich `admin_logged_in`; `pending_2fa` wird nur von den Seiten `two_factor.php` und `two_factor_setup.php` gelesen. Dazu ein Test, der alle Skripte in `backend/public/` ohne `SKIP_AUTH_CHECK` aufruft und bei `pending_2fa` ohne `admin_logged_in` den Redirect erwartet.
- `session_regenerate_id(true)` beim Übergang in den Zustand „angemeldet" (nicht nur nach dem Passwort).

**Einführung mit Schonfrist (Grace-Logins)**

- Gilt zunächst für Platform-Admins, wenn `MULTI_TENANT_ENABLED=true`. Konfigurierbar über `.env` (z. B. `TWO_FACTOR_REQUIRED=platform|tenants|all|off`, `TWO_FACTOR_GRACE_LOGINS=10`); Standard nach Update: `platform`.
- Login 1 bis 10 nach der Einführung: Hinweisbanner mit „Jetzt einrichten" und „Später"; der Zähler `grace_logins_used` steigt **nur bei erfolgreichem Passwort-Login ohne Einrichtung**, nicht bei Fehlversuchen.
- Ab Login 11: Wizard (`two_factor_setup.php`) ist Pflicht; ohne eingerichteten Faktor entsteht keine Sitzung.
- Wizard-Schritte: Methode wählen (Authenticator-App oder Passkey, später beides) → QR-Code samt manuellem Schlüssel → Bestätigung mit einem Code → **Recovery-Codes anzeigen** (einmalig, Download/Druck, Bestätigung „gespeichert") → fertig.
- Nach der Einrichtung: Verwaltung unter „Mein Konto" (Faktor hinzufügen/entfernen, Recovery-Codes neu erzeugen; Entfernen des letzten Faktors nur mit erneuter Passwortprüfung, solange 2FA Pflicht ist: gar nicht).

**Wiederherstellung**

- Recovery-Codes (10 Stück, je einmal nutzbar).
- CLI-Skript `backend/reset-2fa.php <benutzer>`: setzt die Faktoren zurück und gibt dem Admin erneut Schonfrist-Logins (Audit-Eintrag). Wer die Shell hat, hat ohnehin Datenbankzugriff; das ist ein bewusster Notausstieg.
- Ein Platform-Admin kann die Faktoren eines Tenant-Admins zurücksetzen (Audit-Eintrag), nie umgekehrt.

### AP4 – Passkeys (WebAuthn): verschoben auf 3.1.3

Erst nach stabilem AP3; bewusst nicht Teil von 3.1.2, damit die erste Ausgabe der Zwei-Faktor-Anmeldung klein und überprüfbar bleibt.

- Bibliothek: `web-auth/webauthn-lib` (Prüfung auf PHP 8.2+, Abhängigkeitsumfang). Alternativ eigene, schlanke Implementierung nur, wenn die Bibliothek unverhältnismäßig groß ist (nicht empfohlen: kryptografischer Code).
- Relying-Party-ID und Origin aus der Backend-URL (`APP_URL`); bei Betrieb hinter Proxy muss die öffentliche URL stimmen, sonst scheitert die Registrierung. Dokumentieren.
- Tabelle `admin_passkeys` (Credential-ID, Public Key, Signaturzähler, Bezeichnung, `last_used_at`, Transporte).
- Ablauf: Registrierung im Wizard und unter „Mein Konto"; beim Login als zweiter Faktor (Zustand `pending_2fa` wie bei TOTP); bei mehreren Faktoren Auswahl auf der Prüfseite.
- Vanilla JavaScript (`navigator.credentials`), keine Frontend-Frameworks, passend zum Stack. Fehlertexte über `MessageService`.
- Später möglich: Passkey statt Passwort (passwortloser Login). Ausdrücklich weder in 3.1.2 noch in 3.1.3.

### AP5 – Begleitende Härtung (umgesetzt)

**Stand:** Login-Rate-Limit (`LoginThrottle`), Cookie-Flags (`SessionCookie`, `SameSite=Lax` statt `Strict`, weil Links aus E-Mails sonst ohne Sitzung ankämen) und Gleichlauf bei unbekanntem Benutzer (Dummy-Hash) sind umgesetzt.

### AP6 – Signaturformat 2 für die API (umgesetzt, Festschreibung offen)

**Stand:** Das Frontend (Standalone und WordPress-Plugin über `BackendApiClient`) signiert in Format 2 (`X-Timestamp`, `X-Nonce`, Inhaltshash, bei Uploads SHA-256 der Datei); das Backend prüft über `SignatureVerifier` und nimmt im Modus `compat` (Standard) auch das alte Format an. Aufbau, Wirkung und Notausstieg: [sicherheit.md](../sicherheit.md#signierte-api-signaturformat-2-312).

**Umstellungsphase (rückwärtskompatibel):**

1. **Release 3.1.2:** Backend, Standalone-Frontend und Plugin bringen Format 2 mit. Update-Reihenfolge wie immer: Backend zuerst, dann Frontends und Plugin. Bis ein Frontend aktualisiert ist, sendet es Format 1 und wird vom Backend im Modus `compat` weiter angenommen; jede solche Anfrage steht (höchstens einmal pro Stunde und Tenant) als `api_legacy_signature` im Audit-Log, damit klar ist, welche Schule noch fehlt.
2. **Festschreiben:** Sobald das Audit-Log keinen `api_legacy_signature` mehr zeigt, setzt der Betreiber `API_SIGNATURE_MODE=strict`. Ab diesem Zeitpunkt ist Format 1 abgeschaltet.
3. **Release-Notes 3.1.2** nennen die Uhrzeit-Voraussetzung (höchstens 5 Minuten Abweichung zwischen Frontend- und Backend-Server) und den Notausstieg (`API_SIGNATURE_VERSION=1` im Frontend, nur gegen ein älteres Backend).

**Zu entscheiden:** Ab welchem Release `strict` der Standard ist. Vorschlag: Mit 3.1.2 bleibt `compat` Standard, im Folgerelease wird `strict` der Standard und Format 1 entfällt später ganz. So bricht ein Update auf 3.1.2 keine Schule, die ihr Plugin noch nicht aktualisiert hat.

## Offene Entscheidungen

| Frage | Optionen | Tendenz |
|---|---|---|
| Standard von `AUTH_ENABLED` | bleibt `false`; `true` mit Übergangsphase; sofort `true` | **Entschieden:** `true` in `.env.example` und im Docker-Entrypoint (ändert keine bestehende `.env`); `false` setzt der Betreiber bewusst. Eine Installation ohne Login entsteht nicht mehr aus Versehen |
| Rollen: Schul-Admin und Sekretariat getrennt? | keine Trennung (heute); Rolle `sekretariat` an `tenant_admins` (nur Anmeldungen, kein Editor, keine Empfänger-Adresse); Hinweis-Mail an die alte Empfänger-Adresse bei Änderung | **Idee, zunächst nicht verfolgt** (siehe [TODO.md](../TODO.md)). Heute sieht jedes Konto einer Schule alle Anmeldungen und darf `notify_email` ändern, im Mehrschul-Betrieb genauso wie im Einzelbetrieb |
| Sollen Tenant-Admins 2FA bekommen? | nein; optional (selbst einrichten); Pflicht pro Tenant einstellbar | Optional einrichtbar ab AP3, Pflicht pro Tenant als Einstellung; Standard für Tenant-Admins: aus (Schulen müssen es mittragen) |
| Platform-Admin langfristig in die Datenbank? | bei `.env` bleiben; in `tenant_admins`/eigene Tabelle überführen | Später überführen; für 3.1.2 mit fester Kennung auskommen, damit der Umbau nicht den Login-Kern berührt |
| Speicherort der Allowlist | Datenbank (`tenants`); Konfigurationsdatei | Datenbank für Tenants, `.env` für die Platform-Liste |
| TOTP-Secret-Schlüssel | `.env`; abgeleitet aus bestehendem Secret | eigener Wert in `.env`, Pflicht bei aktiver 2FA |
| Passkey als alleiniger Faktor oder zusätzlich | zusätzlich zum Passwort; Passwort ersetzen | zusätzlich zum Passwort, in 3.1.3 nichts anderes |
| Schonfrist-Zähler | Anzahl Logins; Zeitraum (z. B. 14 Tage); beides | Anzahl Logins (wie angedacht), Zeitgrenze optional später |

## Reihenfolge

1. AP1 (Refactoring, klein, mit Tests)
2. AP2 und AP2b (Allowlist, auch ohne Login): eigenständig nutzbar, größter Nutzen pro Aufwand
3. AP5 (Login-Rate-Limit, Cookie-Flags): klein, vor 2FA, damit der Login-Kern einmal angefasst wird
4. AP6 (Signaturformat 2): umgesetzt, Festschreibung nach der Umstellungsphase
5. AP3 (2FA mit TOTP, Schonfrist, Wizard, Recovery)
6. AP4 (Passkeys)

AP1 bis AP3 erscheinen als **3.1.2**; AP4 (Passkeys) ist auf **3.1.3** verschoben, AP3 ist davon nicht betroffen.

## Tests und Abnahme

- **Unit:** `CidrListTest` (IPv4/IPv6, Randfälle der Präfixlängen, ungültige Einträge, IPv4-mapped); `AdminNetworkPolicyTest` (leer = alles erlaubt, Tenant-Grenzen, Platform-Liste, „Alle Tenants"); TOTP-Prüfung (Zeitfenster, Replay, falscher Code); Schonfrist-Zähler (steigt nur bei erfolgreichem Passwort-Login, Wizard-Pflicht ab dem 11.); Recovery-Codes (einmalig, Hash); Verschlüsselung des Secrets (Roundtrip, manipulierter Chiffretext).
- **Integration (Datenbank):** Migration idempotent; Tenant-Filter bei Allowlist und Faktoren; Rücksetzen von Faktoren; Audit-Einträge.
- **Sicherheitstest auf Skriptebene:** kein geschütztes Skript antwortet im Zustand `pending_2fa` mit Inhalt; Session-ID wechselt beim Übergang; Sitzung wird bei Netzwechsel beendet.
- **Manuell** (in [../tests.md](../tests.md) und in den Abnahmeprotokollen ergänzen): Login aus erlaubtem und gesperrtem Netz (Proxy mit und ohne `TRUSTED_PROXIES`); Selbstaussperrungs-Warnung; Wizard mit Authenticator-App (Passkey: mit AP4 in 3.1.3); Schonfrist über Login 1 bis 11; Wiederherstellung mit Recovery-Code und mit `reset-2fa.php`.
- **Negativtests:** IP per `X-Forwarded-For` fälschen (ohne Vertrauenseintrag wirkungslos), abgelaufenes `pending_2fa`, wiederverwendeter Code, manipulierter Passkey-Zähler, Wizard ohne JavaScript.

## Dokumentation

Zu aktualisieren, sobald umgesetzt: [sicherheit.md](../sicherheit.md) (neue Schutzmaßnahmen, Zwischenzustand), [konfiguration.md](../konfiguration.md) (`PLATFORM_ADMIN_ALLOWED_NETWORKS`, `TWO_FACTOR_*`), [architektur.md](../architektur.md) (Schema, Dateien), [changelog.md](../changelog.md), Betreiber-Doku (Einrichtung, Notfall: Aussperrung und 2FA-Reset), Handreichung für Schul-Admins (Netz-Einstellung, Konto-Seite). Neue Dateien in [docs/README.md](../../README.md) eintragen.
