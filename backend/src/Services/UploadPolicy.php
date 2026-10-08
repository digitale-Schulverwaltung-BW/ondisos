<?php
// src/Services/UploadPolicy.php

declare(strict_types=1);

namespace App\Services;

use App\Forms\SurveyFieldExtractor;

/**
 * Regeln für Uploads zu einer Anmeldung (reine Logik, ohne Dateisystem).
 *
 * Die Signatur der API belegt nur, dass das Frontend des Tenants anfragt; was das Frontend
 * weiterreicht, kommt aber vom Browser. Deshalb prüft das Backend selbst, ob der Feldname zu
 * einer Datei-Frage des Formulars gehört und ob die Grenzen pro Anmeldung eingehalten sind.
 */
final class UploadPolicy
{
    /**
     * Gehört der Feldname zu einer Datei-Frage der Survey?
     *
     * Das Frontend hängt bei mehreren Dateien einen Index an ("foto_0", "foto_1").
     *
     * @param array<string,mixed> $survey
     */
    public static function isFileField(array $survey, string $fieldname): bool
    {
        $fields = SurveyFieldExtractor::fields($survey);

        if (isset($fields[$fieldname]) && $fields[$fieldname]['type'] === 'file') {
            return true;
        }

        if (preg_match('/^(.+)_(\d{1,3})$/', $fieldname, $m) === 1) {
            return isset($fields[$m[1]]) && $fields[$m[1]]['type'] === 'file';
        }

        return false;
    }

    /**
     * @param int $existingFiles  Dateien, die zu dieser Anmeldung schon gespeichert sind (ohne die, die überschrieben wird)
     * @param int $existingBytes  deren Gesamtgröße
     * @return string|null Fehlermeldung, wenn die Grenze überschritten würde
     */
    public static function capError(int $existingFiles, int $existingBytes, int $newBytes, int $maxFiles, int $maxBytes): ?string
    {
        if ($maxFiles > 0 && $existingFiles + 1 > $maxFiles) {
            return 'Zu viele Dateien für diese Anmeldung.';
        }
        if ($maxBytes > 0 && $existingBytes + $newBytes > $maxBytes) {
            return 'Das Gesamtvolumen der Dateien für diese Anmeldung ist überschritten.';
        }

        return null;
    }
}
