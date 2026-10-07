<?php
// src/Utils/HelpLinks.php
// Context-sensitive help: which documentation belongs to which backend page

declare(strict_types=1);

namespace App\Utils;

use App\Config\Version;

/**
 * Maps a backend page to the matching documentation (the "?" in the navigation bar).
 *
 * Targets are repository paths (`docs/...#anchor`). The base URL defaults to the documentation of the installed
 * version on GitHub; operators can host it themselves with HELP_BASE_URL (same layout as the repository;
 * `{version}` is replaced by the installed version).
 */
final class HelpLinks
{
    public const DEFAULT_BASE_URL = 'https://github.com/digitale-Schulverwaltung-BW/ondisos/blob/v{version}/';

    private const SEKRETARIAT = 'docs/sekretariat/README.md';
    private const ASV         = 'docs/sekretariat/ASV.md';
    private const SURVEYJS    = 'docs/redaktion/SURVEYJS.md';
    private const PDF         = 'docs/redaktion/pdf-bestaetigung.md';
    private const TENANTS     = 'docs/betreiber/MULTI-TENANT.md';
    private const INDEX       = 'docs/README.md';

    /**
     * Help topics per page script: label and repository path (with anchor). The first entry is the main topic.
     *
     * @return array<string, list<array{label: string, path: string}>>
     */
    public static function topics(): array
    {
        return [
            'index.php' => [
                ['label' => 'Neue Anmeldungen finden', 'path' => self::SEKRETARIAT . '#2-sehen-was-neu-ist'],
                ['label' => 'Excel herunterladen und in ASV-BW importieren', 'path' => self::SEKRETARIAT . '#5-als-excel-herunterladen-und-in-asv-bw-importieren'],
                ['label' => 'Bearbeitungsstand und Sammelaktionen', 'path' => self::SEKRETARIAT . '#6-bearbeitungsstand-pflegen'],
                ['label' => 'Anbindung an ASV-BW', 'path' => self::ASV],
            ],
            'detail.php' => [
                ['label' => 'Eine Anmeldung ansehen', 'path' => self::SEKRETARIAT . '#3-eine-anmeldung-ansehen'],
                ['label' => 'Ausdrucken', 'path' => self::SEKRETARIAT . '#4-ausdrucken'],
                ['label' => 'Bearbeitungsstand pflegen', 'path' => self::SEKRETARIAT . '#6-bearbeitungsstand-pflegen'],
            ],
            'trash.php' => [
                ['label' => 'Archivieren, Löschen, Papierkorb', 'path' => self::SEKRETARIAT . '#7-aufräumen-archivieren-löschen-papierkorb'],
            ],
            'dashboard.php' => [
                ['label' => 'Handreichung für das Sekretariat', 'path' => self::SEKRETARIAT],
            ],
            'forms.php' => [
                ['label' => 'Formulare im Backend pflegen', 'path' => self::SURVEYJS . '#workflow-im-backend'],
                ['label' => 'Neues Formular erstellen', 'path' => self::SURVEYJS . '#neues-formular-erstellen'],
            ],
            'form_edit.php' => [
                ['label' => 'Bestehendes Formular ändern', 'path' => self::SURVEYJS . '#bestehendes-formular-ändern'],
                ['label' => 'PDF-Bestätigung gestalten', 'path' => self::PDF],
                ['label' => 'Konfiguration und Survey', 'path' => self::SURVEYJS . '#zusammenhang-formular-konfiguration--survey'],
            ],
            'form_survey.php' => [
                ['label' => 'Survey einfügen, prüfen, veröffentlichen', 'path' => self::SURVEYJS . '#bestehendes-formular-ändern'],
                ['label' => 'Was „Prüfen“ meldet', 'path' => self::SURVEYJS . '#was-prüfen-meldet'],
                ['label' => 'Verlauf und Wiederherstellen', 'path' => self::SURVEYJS . '#verlauf-und-wiederherstellen'],
            ],
            'form_preview.php' => [
                ['label' => 'Vorschau', 'path' => self::SURVEYJS . '#vorschau'],
            ],
            'form_pdf_preview.php' => [
                ['label' => 'PDF-Bestätigung gestalten', 'path' => self::PDF],
            ],
            'tenants.php' => [
                ['label' => 'Tenant anlegen', 'path' => self::TENANTS . '#tenant-anlegen-platform-admin'],
                ['label' => 'Neuen Tenant einrichten (Checkliste)', 'path' => self::TENANTS . '#neuen-tenant-einrichten-checkliste'],
            ],
        ];
    }

    /**
     * Help entries for a page; pages without own topics get the documentation index.
     *
     * @return list<array{label: string, url: string}>
     */
    public static function forPage(string $script, ?string $baseUrl = null): array
    {
        $topics = self::topics()[basename($script)] ?? [['label' => 'Dokumentation', 'path' => self::INDEX]];
        $base   = self::baseUrl($baseUrl);

        return array_map(
            static fn(array $t): array => ['label' => $t['label'], 'url' => $base . $t['path']],
            $topics
        );
    }

    /** Base URL with trailing slash and the version filled in; empty or unusable values fall back to GitHub. */
    public static function baseUrl(?string $configured = null): string
    {
        $base = trim((string)$configured);
        if ($base === '' || preg_match('#^https?://#i', $base) !== 1) {
            $base = self::DEFAULT_BASE_URL;
        }
        return rtrim(str_replace('{version}', Version::CURRENT, $base), '/') . '/';
    }
}
