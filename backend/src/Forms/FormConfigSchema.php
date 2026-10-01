<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Describes every form-config option exactly once: type, limits and who may change it.
 *
 * The admin form (AP 4), the validator and the JSON that ends up in form_configs.config_json
 * all derive from this list, so a new option is one entry here. Keys are dotted paths into the
 * config array ("pdf.enabled" = $config['pdf']['enabled']).
 *
 * Config keys that are NOT listed here are never touched by the editor; they survive a save unchanged.
 */
final class FormConfigSchema
{
    public const ROLE_PLATFORM = 'platform';
    public const ROLE_TENANT   = 'tenant';

    public const TYPE_BOOL         = 'bool';
    public const TYPE_STRING       = 'string';
    public const TYPE_TEXT         = 'text';          // multi-line string
    public const TYPE_INT          = 'int';
    public const TYPE_EMAIL_LIST   = 'email_list';
    public const TYPE_NAME_LIST    = 'name_list';     // list of survey field names
    public const TYPE_FIELD_FILTER = 'field_filter';  // 'all' or a name list
    public const TYPE_DATE         = 'date';
    public const TYPE_TIME         = 'time';
    public const TYPE_SECTIONS     = 'sections';      // list of {title, content}
    public const TYPE_RESOURCE     = 'resource';      // survey/theme file name
    public const TYPE_PATH         = 'path';          // file path (PDF logo)

    /**
     * @return array<string, array<string,mixed>> dotted path => descriptor
     *   Descriptor keys: type, editableBy (list of roles), max/min (length or value), maxItems, group
     */
    public static function fields(): array
    {
        $both     = [self::ROLE_PLATFORM, self::ROLE_TENANT];
        $platform = [self::ROLE_PLATFORM];

        return [
            // --- general -----------------------------------------------------------------------
            'version'      => ['type' => self::TYPE_STRING, 'editableBy' => $both, 'max' => 50, 'group' => 'general'],
            'db'           => ['type' => self::TYPE_BOOL, 'editableBy' => $both, 'group' => 'general'],
            'notify_email' => ['type' => self::TYPE_EMAIL_LIST, 'editableBy' => $both, 'maxItems' => 20, 'group' => 'general'],
            'prefill_fields' => ['type' => self::TYPE_NAME_LIST, 'editableBy' => $both, 'maxItems' => 100, 'group' => 'general'],

            // --- files the form is built from (set by the system / platform admin) -------------
            'form'  => ['type' => self::TYPE_RESOURCE, 'editableBy' => $platform, 'group' => 'files'],
            'theme' => ['type' => self::TYPE_RESOURCE, 'editableBy' => $platform, 'group' => 'files'],

            // --- notification mail -------------------------------------------------------------
            'email.intro_template' => ['type' => self::TYPE_TEXT, 'editableBy' => $both, 'max' => 2000, 'group' => 'email'],

            // --- iCal download -----------------------------------------------------------------
            'ical.enabled'           => ['type' => self::TYPE_BOOL, 'editableBy' => $both, 'group' => 'ical'],
            'ical.download_title'    => ['type' => self::TYPE_STRING, 'editableBy' => $both, 'max' => 200, 'group' => 'ical'],
            'ical.event_title'       => ['type' => self::TYPE_STRING, 'editableBy' => $both, 'max' => 200, 'group' => 'ical'],
            'ical.event_date'        => ['type' => self::TYPE_DATE, 'editableBy' => $both, 'group' => 'ical'],
            'ical.event_time_start'  => ['type' => self::TYPE_TIME, 'editableBy' => $both, 'group' => 'ical'],
            'ical.event_time_end'    => ['type' => self::TYPE_TIME, 'editableBy' => $both, 'group' => 'ical'],
            'ical.event_location'    => ['type' => self::TYPE_STRING, 'editableBy' => $both, 'max' => 300, 'group' => 'ical'],
            'ical.event_description' => ['type' => self::TYPE_TEXT, 'editableBy' => $both, 'max' => 2000, 'group' => 'ical'],

            // --- PDF confirmation --------------------------------------------------------------
            'pdf.enabled'        => ['type' => self::TYPE_BOOL, 'editableBy' => $both, 'group' => 'pdf'],
            'pdf.required'       => ['type' => self::TYPE_BOOL, 'editableBy' => $both, 'group' => 'pdf'],
            'pdf.title'          => ['type' => self::TYPE_STRING, 'editableBy' => $both, 'max' => 200, 'group' => 'pdf'],
            'pdf.download_title' => ['type' => self::TYPE_STRING, 'editableBy' => $both, 'max' => 200, 'group' => 'pdf'],
            'pdf.token_lifetime' => ['type' => self::TYPE_INT, 'editableBy' => $both, 'min' => 60, 'max' => 86400, 'group' => 'pdf'],
            'pdf.header_title'   => ['type' => self::TYPE_STRING, 'editableBy' => $both, 'max' => 200, 'group' => 'pdf'],
            'pdf.intro_text'     => ['type' => self::TYPE_TEXT, 'editableBy' => $both, 'max' => 2000, 'group' => 'pdf'],
            'pdf.footer_text'    => ['type' => self::TYPE_TEXT, 'editableBy' => $both, 'max' => 2000, 'group' => 'pdf'],
            'pdf.include_fields' => ['type' => self::TYPE_FIELD_FILTER, 'editableBy' => $both, 'maxItems' => 300, 'group' => 'pdf'],
            'pdf.exclude_fields' => ['type' => self::TYPE_NAME_LIST, 'editableBy' => $both, 'maxItems' => 300, 'group' => 'pdf'],
            'pdf.pre_sections'   => ['type' => self::TYPE_SECTIONS, 'editableBy' => $both, 'maxItems' => 10, 'group' => 'pdf'],
            'pdf.post_sections'  => ['type' => self::TYPE_SECTIONS, 'editableBy' => $both, 'maxItems' => 10, 'group' => 'pdf'],
            // A file path read by the server: only the platform operator may set it.
            'pdf.logo'           => ['type' => self::TYPE_PATH, 'editableBy' => $platform, 'max' => 255, 'group' => 'pdf'],
        ];
    }

    /** True if $role may change $path. */
    public static function isEditableBy(string $path, string $role): bool
    {
        $field = self::fields()[$path] ?? null;
        return $field !== null && in_array($role, $field['editableBy'], true);
    }
}
