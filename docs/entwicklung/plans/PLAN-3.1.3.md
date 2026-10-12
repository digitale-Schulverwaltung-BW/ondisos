# PLAN 3.1.3 – Passkeys, Platform-Admin in der Datenbank mit Einrichtung im Browser, `strict` als Standard

**Status:** Entwurf (Stand 2026-10-12), noch keine Zeile Code. Eigenes Release **3.1.3**, unabhängig vom grafischen Survey-Editor ([PLAN-3.2.0.md](PLAN-3.2.0.md)).
Aufwände sind Schätzungen für eine Person und nicht gemessen; AP0 soll sie belegen oder korrigieren.

**Herkunft:** AP4 (Passkeys) und die Festschreibung des Signaturformats stammen aus [PLAN-3.1.2.md](PLAN-3.1.2.md). Neu ist der Umbau des Platform-Admins (Offene Entscheidung dort: „Platform-Admin langfristig in die Datenbank?“), der zugleich die Einrichtung einer frischen Instanz im Browser ermöglicht.
*Gestrichen:* die Hinweis-Mail bei Änderung von `notify_email` und die Rolle „Sekretariat“ (bleiben Ideen in [TODO.md](../TODO.md)).

## Ausgangslage (Stand 3.1.2)

- **Platform-Admin** ist kein Datenbankbenutzer: `ADMIN_USERNAME` und `ADMIN_PASSWORD_HASH` stehen in `.env` und werden in `LoginService::attemptPlatformAdminLogin()` geprüft. Der zweite Faktor hängt am Schlüssel `platform:<ADMIN_USERNAME>` (`App\Services\AdminKey`).
- **Erster Start einer Neuinstallation:** Der Betreiber muss vor `docker compose up -d` einen Hash mit `generate-password-hash.php` erzeugen und in die Root-`.env` schreiben (einfache Anführungszeichen wegen der `$`-Zeichen). Das ist die häufigste Stolperstelle; der Entrypoint und `LoginService` warnen inzwischen bei leerem oder beschädigtem Hash, lösen sie aber nicht.
- **Zweiter Faktor:** nur TOTP (`admin_two_factor`, `admin_recovery_codes`); `TwoFactorPolicy::decide()`, `TwoFactorLogin`, `TwoFactorSession`, `two_factor.php`, `two_factor_setup.php`, `account.php`. Der Zwischenzustand `pending_2fa` gibt keinen Zugang (Test `TwoFactorScriptsTest`).
- **Signaturformat:** `SignatureVerifier::DEFAULT_MODE` ist `compat`; `.env.example` setzt `API_SIGNATURE_MODE=compat`. `SignatureModeDefaultTest` schlägt ab Version 3.1.3 fehl, bis das umgestellt ist. Weder Entrypoint noch `docker-compose.yml` setzen den Wert: Docker-Installationen folgen dem Code-Standard (siehe Risiken).

## Ziel

1. **Passkeys (WebAuthn)** als zweiter Faktor neben TOTP, für Platform- und Schul-Admins.
2. **Platform-Admin in der Datenbank.** Passwortwechsel in der Oberfläche; Bestandsinstallationen behalten das `.env`-Passwort, bis es in der Oberfläche geändert wird, danach hat die Datenbank Vorrang.
3. **Einrichtung im Browser** bei einer Neuinstallation: nach `docker compose up -d` legt der Betreiber Konto, Passwort und zweiten Faktor (Passkey und/oder TOTP) im Browser an. Kein Hash mehr in der `.env`.
4. **`API_SIGNATURE_MODE=strict` als Standard.**

**Nicht Ziel:** passwortloser Login mit Passkey (Passkey bleibt zweiter Faktor, wie in 3.1.2 entschieden); mehrere Platform-Admin-Konten mit Benutzerverwaltung (das Schema erlaubt es, die Oberfläche kommt nicht); Umbenennen des Platform-Admins; Entfernen des Signaturformats 1 aus dem Code; Single Sign-on.

## AP0 – Bibliothek prüfen (Spike, vor AP3)

**Lizenzprüfung (erledigt, soweit aus den Paket-Metadaten ersichtlich):**

| Paket | Version | Lizenz |
|---|---|---|
| `web-auth/webauthn-lib` | 5.3.9 (PHP ≥ 8.2) | MIT |
| `web-auth/cose-lib`, `spomky-labs/cbor-php`, `spomky-labs/pki-framework`, `brick/math` | 4.8.2, 3.4.2, 1.6.3, 1.0.0 | MIT |
| `paragonie/constant_time_encoding` (bereits im Projekt) | 3.x | MIT |
| `symfony/serializer`, `property-access`, `property-info`, `uid`, `clock`, `string`, `type-info`, Polyfills | 7.4.x | MIT |
| `phpdocumentor/reflection-docblock`, `phpstan/phpdoc-parser`, `webmozart/assert` | 6.0.3, 2.3.6, 2.5.0 | MIT |

Ondisos steht unter der MIT-Lizenz; alle geprüften Pakete sind MIT und damit kompatibel (Hinweispflicht: Lizenztexte in `LICENSES.txt` bzw. `composer licenses` mitliefern, wie bei den vorhandenen Bibliotheken). **Noch zu prüfen** (nicht abgerufen): `doctrine/deprecations`, `psr/container`, `phpdocumentor/reflection-common`, `phpdocumentor/type-resolver`, `symfony/deprecation-contracts`. Das erledigt `composer licenses` nach dem Einspielen; ein Eintrag außerhalb von MIT/BSD/Apache-2.0 stoppt die Übernahme.
*Hinweis zur Recherche:* In dieser Sitzung war nur die Paketdatenbank (Packagist) erreichbar, nicht GitHub; Pflege-Stand und Issues der Bibliothek sind damit nicht geprüft.

**Umfang der Abhängigkeiten:** Ein Trockenlauf (`composer require web-auth/webauthn-lib --dry-run` gegen `composer.lock`) zieht **rund 28 neue Pakete** hinein. Wegen der Plattform-Festlegung auf PHP 8.2 wählt Composer Symfony 7.4 (Symfony 8 braucht PHP 8.4); 7.4 ist die aktuelle LTS und damit vertretbar. Das ist deutlich mehr als bei TOTP (4 Pakete).

**Alternative:** `lbuchs/webauthn` 2.2.0 (MIT, ohne weitere Abhängigkeiten, nur `ext-openssl`; letztes Release Juli 2024, kleiner Maintainer-Kreis). Kleinere Angriffsfläche und kein Symfony im Backend, dafür weniger Pflege und weniger Prüfung durch Dritte. Kryptografischen Code selbst zu schreiben bleibt ausgeschlossen.

**Spike-Aufgaben:**
- `webauthn-lib` im Dockerfile bauen, Größe von `vendor/` und Image vorher/nachher messen; PHP-8.2-Lauf (Plattform-Pin) bestätigen; `composer audit`.
- Registrierung und Anmeldung mit beiden Bibliotheken in einem Wegwerf-Skript gegen die **virtuelle Authenticator-Funktion** von Chromium (DevTools-Protokoll, `WebAuthn.addVirtualAuthenticator`; Chromium und Playwright sind in der Entwicklungsumgebung vorhanden). Das liefert zugleich die Ende-zu-Ende-Prüfung für AP3.
- Entscheidung dokumentieren. **Tendenz:** `webauthn-lib`, wenn die Größe verkraftbar ist (gepflegt, breit eingesetzt); sonst `lbuchs/webauthn`.

## AP1 – `strict` als Standard (klein, zuerst)

- `SignatureVerifier::DEFAULT_MODE = MODE_STRICT`, `.env.example` auf `strict`, Kommentar im Code anpassen; `SignatureModeDefaultTest` läuft dann grün (er prüft Code und `.env.example`).
- Doku: [sicherheit.md](../sicherheit.md#signierte-api-signaturformat-2-312), [konfiguration.md](../konfiguration.md), [betrieb.md](../../betreiber/betrieb.md) (Checkliste: aus „dann `strict` setzen“ wird „`compat` nur noch als Übergang“), [CLAUDE.md](../../../CLAUDE.md) (Bekannte Einschränkungen), TODO.md (Punkt entfällt).
- **Release Notes, Upgrade-Schritt 1 (vor dem Update):** Im Audit-Log nach `api_legacy_signature` suchen. Gibt es Treffer, entweder zuerst die betroffenen Frontends und Plugins auf 3.1.2 bringen oder `API_SIGNATURE_MODE=compat` ausdrücklich setzen.
- Der Wechsel passiert beim Update **still**, weil kein Skript den Wert schreibt. Deshalb gibt der Entrypoint beim Start eine Zeile aus („Signaturformat: strict“ bzw. „compat: Format 1 wird noch angenommen“).
- Format 1 aus dem Code zu entfernen ist **nicht** Teil von 3.1.3.

## AP2 – Platform-Admin in der Datenbank

### Datenmodell

Neue Tabelle `platform_admins`: `id`, `username` (UNIQUE), `password_hash`, `password_changed_at`, `created_at`, `updated_at`. In `schema.sql` und per Migration (idempotent), in [architektur.md](../architektur.md) nachtragen. Das Schema erlaubt mehrere Konten, die Oberfläche kennt in 3.1.3 genau eines.

**Schlüssel bleibt stabil:** `AdminKey::forPlatform($username)` liefert weiter `platform:<username>`. Ein Bestandskonto, das aus der `.env` in die Datenbank übernommen wird, behält denselben Benutzernamen und damit seinen zweiten Faktor, seine Recovery-Codes und den Schonfrist-Zähler. Umbenennen ist nicht vorgesehen (bräuchte eine Schlüsselmigration in `admin_two_factor` und `admin_recovery_codes`).

### Vorrang und Übernahme

`LoginService::attemptPlatformAdminLogin()` entscheidet in dieser Reihenfolge:

1. Gibt es eine Zeile in `platform_admins`: **nur** sie gilt. Ein noch gesetzter `ADMIN_PASSWORD_HASH` wird ignoriert; das Backend schreibt dazu einmal pro Start eine Zeile ins Fehlerlog, damit niemand glaubt, der alte Hash gelte noch.
2. Sonst gilt `.env` (`ADMIN_USERNAME` + `ADMIN_PASSWORD_HASH`) wie bisher.
3. Sonst ist weder noch vorhanden: Einrichtungsmodus (AP4).

**Passwort ändern** (neu unter „Mein Konto“, für Platform- und Schul-Admins): aktuelles Passwort, neues Passwort zweimal, Mindestlänge 12 Zeichen, keine Zusammensetzungsregeln. Beim Platform-Admin aus der `.env` legt die erste Änderung die Zeile in `platform_admins` an (Benutzername aus `ADMIN_USERNAME`); ab dann hat die Datenbank Vorrang. Nach der Änderung `session_regenerate_id(true)` und Audit-Ereignis `admin_password_changed`. Ist ein zweiter Faktor eingerichtet und vorgeschrieben, bleibt die Sitzung ohnehin durch ihn geschützt; eine Änderung verlangt zusätzlich das aktuelle Passwort (wie das Entfernen eines Faktors heute).

`verifyPasswordForKey()` kennt dafür den DB-Pfad für `platform:`-Schlüssel (heute ruft er den `.env`-Pfad). Gleiche Laufzeit bei unbekanntem Benutzer (Dummy-Hash) und `LoginThrottle` gelten unverändert.

### Notausstieg

CLI-Skript `backend/reset-platform-admin.php` (idempotent wie `migrate.php`, Audit-Eintrag): setzt ein neues Passwort für das DB-Konto (liest es von der Standardeingabe, nie als Argument) **oder** löscht die Zeile (`--remove-db-account`), sodass wieder `.env` gilt. Zusammen mit `reset-2fa.php` deckt das „Passwort vergessen“ und „Faktor weg“ ab; beides setzt Shell-Zugang voraus, der ohnehin gleichwertig zum Datenbankzugriff ist. In [notfall.md](../../betreiber/notfall.md) und [zwei-faktor.md](../../betreiber/zwei-faktor.md) ergänzen.

## AP3 – Passkeys (WebAuthn)

**Entscheidungen**

- **Nur zweiter Faktor**, nach dem Passwort, im Zwischenzustand `pending_2fa` wie TOTP. `userVerification: preferred`, `residentKey: discouraged` (der Benutzer ist durch das Passwort bekannt, `allowCredentials` genügt), `attestation: none`.
- **Relying Party:** Die Backend-URL steht nirgends in der Konfiguration (`APP_URL` gibt es nicht). Neu in `.env`: `WEBAUTHN_RP_ID` (Host ohne Port) und `WEBAUTHN_ORIGIN` (z. B. `https://backend.example.de`). **Nicht** aus `Host` oder `X-Forwarded-Host` abgeleitet: Das wäre manipulierbar, und eine falsche Origin lässt Registrierungen später stillschweigend ins Leere laufen. Fehlen die Werte, ist die Passkey-Option ausgeblendet mit klarer Meldung (fail-closed wie bei `TWO_FACTOR_KEY`). Entwicklungsumgebung: `localhost` ohne HTTPS ist für Browser zulässig. Der Docker-Entrypoint reicht beide Werte durch; hinter Reverse-Proxy muss die **öffentliche** Adresse stehen (dokumentieren, mit Fehlerbild).
- **Signaturzähler:** Passkeys, die zwischen Geräten synchronisiert werden, melden oft `0`. Ein Rückschritt wird nur abgewiesen, wenn der gespeicherte Zähler `> 0` ist; jede Auffälligkeit steht als `passkey_counter_anomaly` im Audit-Log.
- **Ein Faktor genügt:** Wer einen Passkey hat, braucht weder TOTP noch umgekehrt. `TwoFactorPolicy::decide()` zählt beide als „hat Faktor“ (`$hasFactor`); Schonfrist, Pflicht und Wizard-Zwang bleiben unverändert. Recovery-Codes gelten für jeden Faktor.
- **Challenge** liegt in der Sitzung, einmalig und an die Lebensdauer von `pending_2fa` gebunden (5 Minuten). Falsche Antworten zählen zu den 5 Fehlversuchen, danach ist `pending_2fa` verworfen.

**Datenmodell:** Tabelle `admin_passkeys` (`id`, `admin_key`, `credential_id` VARBINARY UNIQUE, `public_key` BLOB, `sign_count`, `aaguid`, `transports` JSON, `label`, `created_at`, `last_used_at`), Index auf `admin_key`. Zugriff über `TwoFactorRepository` bzw. ein eigenes `PasskeyRepository`; jede Abfrage nach `admin_key`.

**Oberfläche**

- `two_factor.php`: Ist ein Passkey vorhanden, zeigt die Seite zuerst „Mit Passkey bestätigen“ (ein Knopf), darunter Authenticator-Code und Recovery-Code als Rückfall. Ohne JavaScript oder ohne WebAuthn im Browser bleibt der Code-Weg.
- `two_factor_setup.php` (Wizard): der Schritt „Methode wählen“, der in 3.1.2 entfiel, kommt zurück (Authenticator-App / Passkey). Der Wizard speichert wie bisher erst im letzten Schritt.
- `account.php`: Passkeys hinzufügen (mit Bezeichnung), auflisten (Bezeichnung, angelegt, zuletzt benutzt), entfernen (mit Passwort; den letzten Faktor nur, wo 2FA nicht vorgeschrieben ist).
- JavaScript als Datei `assets/js/webauthn.js`, Vanilla (`navigator.credentials`), keine Inline-Handler (CSP mit Nonce, `ContentSecurityPolicyTest`); Base64url-Umwandlung, Fehlertexte über `MessageService`; Daten per `data-*`-Attribute.
- `reset-2fa.php` und das Zurücksetzen durch den Platform-Admin entfernen auch Passkeys (Audit-Eintrag nennt die Anzahl).

**Tests**

- Unit: Challenge einmalig und abgelaufen, Zähler-Regeln (0/0 erlaubt, Rückschritt bei > 0 abgewiesen), fehlende RP-Konfiguration, `TwoFactorPolicy` mit Passkey-Faktor, Entfernungsregeln.
- Integration (MySQL): `admin_passkeys` je `admin_key` getrennt, Migration idempotent, Zurücksetzen.
- Skriptebene: `TwoFactorScriptsTest` deckt neue Seiten und Endpunkte ab (kein Zugang im Zwischenzustand).
- Ende-zu-Ende mit virtuellem Authenticator in Chromium (aus AP0): Registrierung im Wizard und in „Mein Konto“, Anmeldung, falscher Origin, Wiederholung derselben Antwort.
- Manuell: echter Hardware-Schlüssel und ein Passkey auf Handy oder Passwortmanager, Betrieb hinter Reverse-Proxy.

## AP4 – Einrichtung im Browser (Neuinstallation)

**Ablauf.** Nach `docker compose up -d` öffnet der Betreiber das Backend. Gibt es weder eine Zeile in `platform_admins` noch einen `ADMIN_PASSWORD_HASH`, leitet `login.php` auf `setup.php` um. Schritte:

1. **Einrichtungscode** eingeben (siehe Absicherung).
2. **Konto:** Benutzername (Vorschlag `admin`) und Passwort (zweimal, Mindestlänge 12).
3. **Zweiter Faktor:** Passkey und/oder Authenticator-App (der Wizard aus AP3 und 3.1.2). Im Mehrschul-Betrieb mindestens einer (entspricht `TWO_FACTOR_REQUIRED=platform`); im Einzelbetrieb optional.
4. **Recovery-Codes** anzeigen und bestätigen; **Fertig:** Konto und Faktor werden in **einer Transaktion** gespeichert, die Sitzung wird angelegt (`session_regenerate_id(true)`), Weiterleitung zu *Tenants* bzw. zur Anmeldeliste.

Voraussetzung ist ein gesetzter `TWO_FACTOR_KEY` (der Entrypoint erzeugt ihn bereits); fehlt er, bricht die Einrichtung mit klarer Meldung ab.

**Absicherung – das ist das eigentliche Risiko.** Wer die Seite als Erster erreicht, wird Platform-Admin. Auf einer frisch gestarteten Instanz im Intranet ist das ein kleines, im Internet ein reales Risiko. Deshalb:

- **Einrichtungscode:** Der Entrypoint erzeugt beim ersten Start einen zufälligen Code und gibt ihn **nur im Containerlog** aus (`docker compose logs backend`) und legt ihn in `cache/setup-code` ab (Rechte `0600`; die Datei wird nach erfolgreicher Einrichtung gelöscht). Wer den Browser erreicht, aber keinen Zugriff auf den Server hat, kommt nicht weiter. *Zu klären in AP4:* ob `cache/` (dort liegen schon `last_expunge_<id>.txt`) im Docker-Betrieb als Volume überlebt; `data/` scheidet aus (dort liegt die Schulliste `schulen.csv`, kein Laufzeitzustand). Der Entrypoint schreibt `backend/.env` bei jedem Start neu und kann deshalb keinen Zufallswert halten, der nur einmal entstehen darf.
- **Netzliste gilt:** `PLATFORM_ADMIN_ALLOWED_NETWORKS` wird vor der Einrichtung geprüft (AP2b-Mechanismus). Für Betreiber, die die Instanz öffentlich starten, ist das der empfohlene Schutz zusätzlich zum Code.
- **Rate-Limit** für Codeeingaben (`RateLimiter`, wie `LoginThrottle`); Audit-Ereignisse `setup_started`, `setup_completed`, `setup_code_failed`.
- **Einmalig und atomar:** Die Seite antwortet nur im Zustand „kein Platform-Admin“; Anlegen unter Sperre und mit UNIQUE-Schlüssel, damit zwei gleichzeitige Einrichtungen nicht beide gelingen. Danach `404`.
- **Nicht aktiv**, wenn die Anmeldung ausgeschaltet ist (`AUTH_ENABLED=false` und `MULTI_TENANT_ENABLED=false`): Dort gibt es keinen Login, also auch nichts einzurichten. (Mehrschul-Betrieb erzwingt den Login.)
- HTTPS: Die Seite warnt ohne HTTPS (außer `localhost`), weil Passwort und Passkey über die Leitung gehen; `SESSION_SECURE` bleibt maßgeblich.

**Folgen für Doku und Skripte**

- [installation.md](../../betreiber/installation.md) und [installation-ohne-docker.md](../../betreiber/installation-ohne-docker.md): Der Schritt „Admin-Hash erzeugen“ entfällt für Neuinstallationen und wird zum Abschnitt „Alternative: Konto in der `.env`“. `generate-password-hash.php` bleibt. `.env.example` (beide) und `docker-compose.yml`: `ADMIN_USERNAME`/`ADMIN_PASSWORD_HASH` bleiben möglich, sind aber nicht mehr nötig.
- Entrypoint: Die Warnung bei leerem `ADMIN_PASSWORD_HASH` wird zum Hinweis „Einrichtung im Browser: <URL>, Code im Log“, solange noch kein Konto existiert.
- Wer die Einrichtung im Browser nicht will (Automatisierung, Ansible), setzt weiter die `.env`-Werte; der Vorrang aus AP2 bleibt gleich.

## Offene Entscheidungen

| Frage | Optionen | Tendenz |
|---|---|---|
| Bibliothek | `web-auth/webauthn-lib` (≈ 28 Pakete, gepflegt); `lbuchs/webauthn` (keine Abhängigkeiten, kleiner Maintainer-Kreis) | `webauthn-lib`, wenn AP0 die Größe als verträglich zeigt |
| RP-ID und Origin | explizite `.env`-Werte; aus der Anfrage abgeleitet | explizit (fail-closed) |
| Ort und Form des Einrichtungscodes | Containerlog + Datei in `cache/`; Wert in der `.env`; Link mit Token | Log + Datei, in AP4 zu klären |
| Mindestlänge des Passworts | 8 / 12 / 15 | 12, ohne Zusammensetzungsregeln |
| Passkey-Einrichtung bei Einzelbetrieb | Pflicht / optional | optional (`TWO_FACTOR_REQUIRED=platform` gilt nur im Mehrschul-Betrieb) |
| Umbenennen des Platform-Admins | nicht in 3.1.3; mit Schlüsselmigration später | später |
| `strict` auch für bestehende Docker-Installationen automatisch | still (Code-Standard) mit lauter Release-Note; oder beim Update `compat` in die `.env` schreiben | still, mit Hinweis im Entrypoint und in den Release Notes |

## Reihenfolge

1. **AP0** Spike (Bibliothek, Größe, virtueller Authenticator) – Entscheidung festhalten.
2. **AP1** `strict` (klein, unabhängig; kann vorab in einen eigenen Commit).
3. **AP2** Platform-Admin in der Datenbank (berührt den Login-Kern einmal; vor den Passkeys, damit der Wizard nur einmal gebaut wird).
4. **AP3** Passkeys.
5. **AP4** Einrichtung im Browser (nutzt AP2 und den Wizard aus AP3).

## Risiken

- **Erstzugriff auf die Einrichtung** (AP4): siehe Absicherung. Ohne den Einrichtungscode wäre die Funktion für nicht abgeschirmte Instanzen unverantwortlich.
- **Aussperrung bei falscher RP-Konfiguration:** Passkeys, die unter einer falschen Domain registriert wurden, funktionieren nicht mehr, wenn die Adresse später wechselt. Der Rückfall sind TOTP und Recovery-Codes; deshalb ist der Passkey nie der einzige Weg (Wizard verlangt Recovery-Codes), und `reset-2fa.php` entfernt Passkeys.
- **Zwei Wahrheiten für das Passwort** (AP2): `.env` und Datenbank. Der Vorrang ist eindeutig und wird im Log genannt; trotzdem dokumentieren, dass ein später in der `.env` geänderter Hash dann wirkungslos ist.
- **Stilles `strict`** (AP1): Eine Schule mit altem Plugin fällt beim Update des Backends aus. Mitigation: Release Notes, Entrypoint-Zeile, Prüfung des Audit-Logs vor dem Update. Betroffen sind die Anmeldungen über dieses Formular (Eltern, Betriebe), nicht das Sekretariat.
- **Abhängigkeitsumfang** (AP0): 28 neue Pakete wollen gepflegt werden (`composer audit` in der Pipeline läuft bereits).
- **Browser-Eigenheiten bei Passkeys:** Synchronisierte Passkeys, Zähler 0, Safari-Besonderheiten bei Nicht-HTTPS. Abdeckung durch Handtests, siehe AP3.

## Tests und Abnahme

- Unit: Vorrang `platform_admins` vor `.env`; Passwortwechsel; Einrichtungsmodus nur ohne Konto; Einrichtungscode (falsch, richtig, Rate-Limit); stabiler `AdminKey` bei Übernahme; Passkey-Tests aus AP3; `SignatureModeDefaultTest`.
- Integration: Migration idempotent (`platform_admins`, `admin_passkeys`); gleichzeitige Einrichtung (zweite scheitert); Übernahme eines `.env`-Kontos behält Faktor und Recovery-Codes.
- Skriptebene: `setup.php` im Zustand „Konto vorhanden“ → 404; kein geschütztes Skript im Zwischenzustand der Einrichtung zugänglich.
- Manuell ([../tests.md](../tests.md) und ein neuer Abschnitt in [../releases/ABNAHME.md](../releases/ABNAHME.md)): frische Instanz ohne Hash im Browser einrichten (Passkey, TOTP, beides); Bestandsinstanz mit `.env`-Hash aktualisieren, Passwort in der Oberfläche ändern, `.env`-Hash danach wirkungslos; `reset-platform-admin.php`; Passkey hinter Reverse-Proxy; `strict` mit einem Frontend im Format 1 (erwartet 401) und Format 2.

## Dokumentation und Version

Zu ergänzen: [sicherheit.md](../sicherheit.md), [konfiguration.md](../konfiguration.md) (`WEBAUTHN_RP_ID`, `WEBAUTHN_ORIGIN`, Einrichtungscode, `API_SIGNATURE_MODE`), [architektur.md](../architektur.md) (Tabellen, Dateien), [zwei-faktor.md](../../betreiber/zwei-faktor.md), [notfall.md](../../betreiber/notfall.md), [installation.md](../../betreiber/installation.md), [betrieb.md](../../betreiber/betrieb.md), [changelog.md](../changelog.md), neue Release Notes `RELEASE-NOTES-3.1.3.md` (Upgrade von 3.1.2 mit dem Schritt zur Signatur), [CLAUDE.md](../../../CLAUDE.md) (Platform-Admin, Bekannte Einschränkungen), Handreichung für Schul-Admins (Passwort ändern, Passkey). Neue Dateien in [docs/README.md](../../README.md) eintragen. Versionsnummer an vier Stellen auf 3.1.3 (`ondisos.php`, `readme.txt`, README-Badge, `App\Config\Version`); `HelpLinksTest` prüft den Abgleich.
