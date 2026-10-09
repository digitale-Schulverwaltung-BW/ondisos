# Zwei-Faktor-Anmeldung

Ab 3.1.2 können sich Admins zusätzlich zum Passwort mit einem Einmal-Code aus einer **Authenticator-App** anmelden (TOTP, z. B. Aegis, FreeOTP, Google oder Microsoft Authenticator). Für Platform-Admins im Mehrschul-Betrieb ist das vorgeschrieben.
Passkeys sind für eine spätere Ausgabe vorgesehen ([PLAN-3.1.2.md](../entwicklung/plans/PLAN-3.1.2.md), AP4).

## Wer braucht es?

Die Einstellung `TWO_FACTOR_REQUIRED` in der `.env` bestimmt, für wen der zweite Faktor **Pflicht** ist:

| Wert | Pflicht für |
|---|---|
| `platform` (Standard) | Platform-Admins, **nur** bei `MULTI_TENANT_ENABLED=true` (Mehrschul-Betrieb ist das beste Anzeichen dafür, dass das Backend aus dem Internet erreichbar ist) |
| `tenants` | Schul-Admins |
| `all` | alle |
| `off` | niemand, die Prüfung entfällt vollständig (Notausstieg) |

Wer nicht verpflichtet ist, kann den zweiten Faktor freiwillig unter *Mein Konto* einrichten. Danach wird er bei jeder Anmeldung verlangt. Ohne Login (`AUTH_ENABLED=false`) gibt es keine Konten und damit keinen zweiten Faktor.

## Einführung beim Betreiber

1. Schlüssel erzeugen und in die `.env` (bei Docker: Root-`.env`) eintragen:

   ```bash
   openssl rand -hex 32     # Ausgabe als TWO_FACTOR_KEY=... eintragen
   ```

   Der Schlüssel verschlüsselt die Authenticator-Geheimnisse in der Datenbank; ein Datenbankabzug allein enthält keine nutzbaren zweiten Faktoren. **Sichern Sie ihn mit der übrigen `.env` und ändern Sie ihn nicht:** Mit einem neuen Schlüssel sind alle eingerichteten Authenticator ungültig (Abhilfe: Zurücksetzen, siehe unten).
2. `php migrate.php` ausführen (Docker: geschieht beim Start), danach `docker compose up -d backend`.
3. Die Platform-Admins melden sich wie gewohnt an. Sie sehen einen Hinweis und können die ersten `TWO_FACTOR_GRACE_LOGINS` Anmeldungen (Standard 10) noch ohne zweiten Faktor durchführen. **Ab der nächsten Anmeldung** erscheint der Einrichtungs-Assistent, ohne ihn entsteht keine Sitzung. Der Zähler steigt nur bei erfolgreichem Passwort-Login ohne Einrichtung, nicht bei Fehlversuchen.

Fehlt `TWO_FACTOR_KEY`, während ein zweiter Faktor Pflicht ist: Während der Schonfrist läuft die Anmeldung weiter (ohne die Schonfrist zu verbrauchen) und die Seite weist auf den fehlenden Schlüssel hin; danach wird die Anmeldung mit einer klaren Meldung abgelehnt.

## Einrichten (für Admins)

1. Nach der Anmeldung den Hinweis „Jetzt einrichten“ wählen (oder *Mein Konto*).
2. QR-Code mit der Authenticator-App scannen, oder den Schlüssel von Hand eintragen.
3. Den angezeigten 6-stelligen Code eingeben.
4. **Recovery-Codes sichern** (10 Stück, jeder gilt einmal; als Textdatei oder Ausdruck, getrennt vom Gerät). Sie werden nur in diesem Schritt angezeigt. Erst danach ist die Einrichtung abgeschlossen.

Bei der Anmeldung geben Sie nach dem Passwort den Code der App ein. Hat ein Code einmal funktioniert, ist er verbraucht (auch innerhalb seiner 30 Sekunden). Nach fünf falschen Codes beginnt die Anmeldung von vorn.
Ist das Gerät verloren, melden Sie sich mit einem Recovery-Code an und richten unter *Mein Konto* einen neuen Authenticator ein. *Mein Konto* erlaubt außerdem, neue Recovery-Codes zu erzeugen (Passwort nötig); den zweiten Faktor zu entfernen ist nur möglich, wo er nicht vorgeschrieben ist.

## Wiederherstellung

| Situation | Abhilfe |
|---|---|
| Gerät weg, Recovery-Codes vorhanden | Anmelden mit einem Recovery-Code, neuen Authenticator einrichten |
| Schul-Admin hat nichts mehr | Ein Platform-Admin setzt den zweiten Faktor unter *Tenants* zurück („2FA zurücksetzen“, Audit-Eintrag) |
| Platform-Admin hat nichts mehr | Auf dem Server: `php reset-2fa.php <benutzer>` (Docker: `docker compose exec backend php reset-2fa.php <benutzer>`) |
| `TWO_FACTOR_KEY` verloren oder geändert | `TWO_FACTOR_REQUIRED=off` setzen, anmelden, Schlüssel setzen, `reset-2fa.php` für die Betroffenen, `TWO_FACTOR_REQUIRED` zurücksetzen |

Nach dem Zurücksetzen beginnt die Schonfrist von vorn. Ein Schul-Admin kann nie die Faktoren eines Platform-Admins ändern. Jede Zurücksetzung steht im Audit-Log (`two_factor_reset`).
Der Platform-Admin aus der `.env` wird über seinen Benutzernamen (`ADMIN_USERNAME`) zugeordnet: Wird er umbenannt, beginnt er ohne zweiten Faktor.

## Audit-Ereignisse

`two_factor_enabled`, `two_factor_success`, `two_factor_failed`, `recovery_code_used`, `recovery_codes_regenerated`, `two_factor_removed`, `two_factor_reset`. Codes und Geheimnisse stehen nie im Log.

Sicherheitsdetails (Zwischenzustand, Replay-Schutz, Verschlüsselung): [sicherheit.md](../entwicklung/sicherheit.md).
