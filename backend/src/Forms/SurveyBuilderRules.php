<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * The rules the server enforces on a survey, in a form the graphical survey builder (JavaScript) can use.
 *
 * The builder never decides validity itself: SurveyValidator stays the authority. It needs the same facts, though, to
 * avoid offering what the server would refuse (reserved names, HTML tags, limits). They come from here, so there is
 * one definition; the page embeds toArray() as JSON. backend/tests/js/fixtures/survey-rules.json is the committed copy
 * the JavaScript tests read; SurveyBuilderRulesTest keeps it equal to this class
 * (refresh: php backend/tools/dump-survey-rules.php > backend/tests/js/fixtures/survey-rules.json).
 */
final class SurveyBuilderRules
{
    /**
     * @return array{reservedNames: list<string>, displayTypes: list<string>, maxBytes: int, maxElements: int,
     *               maxDepth: int, htmlTags: array<string, list<string>>, safeSchemes: list<string>}
     */
    public static function toArray(): array
    {
        return [
            'reservedNames' => SurveyValidator::RESERVED_NAMES,
            'displayTypes'  => SurveyFieldExtractor::DISPLAY_TYPES,
            'maxBytes'      => SurveyValidator::MAX_BYTES,
            'maxElements'   => SurveyValidator::MAX_ELEMENTS,
            'maxDepth'      => SurveyValidator::MAX_DEPTH,
            'htmlTags'      => HtmlPolicy::ALLOWED,
            'safeSchemes'   => HtmlPolicy::SAFE_SCHEMES,
        ];
    }
}
