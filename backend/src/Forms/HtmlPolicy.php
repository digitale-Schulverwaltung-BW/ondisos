<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Allowlist for the HTML that admins may put into survey texts (html elements, descriptions, ...).
 *
 * SurveyJS renders these strings unfiltered into the public form page, so anything that could run
 * script (script/iframe/object tags, on* attributes, javascript: URLs, inline styles) is rejected.
 * The check is allow-based: unknown tags and attributes are errors, not silently dropped.
 */
final class HtmlPolicy
{
    /** @var array<string, list<string>> tag => allowed attributes */
    private const ALLOWED = [
        'a'      => ['href', 'target', 'rel', 'title'],
        'b'      => [],
        'strong' => [],
        'i'      => [],
        'em'     => [],
        'u'      => [],
        'small'  => [],
        'sub'    => [],
        'sup'    => [],
        'br'     => [],
        'hr'     => [],
        'p'      => [],
        'div'    => [],
        'span'   => [],
        'ul'     => [],
        'ol'     => [],
        'li'     => [],
        'h1'     => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
    ];

    /** URL schemes allowed in href. Relative URLs and anchors are fine. */
    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** True if the string contains something that looks like a tag or comment and needs checking. */
    public static function looksLikeHtml(string $value): bool
    {
        // No whitespace allowed after "<": "a < b" is text, "<b>" is a tag.
        return preg_match('/<[\/!?]?[a-zA-Z]/', $value) === 1;
    }

    /**
     * @return list<string> human-readable problems (empty = allowed)
     */
    public static function check(string $html): array
    {
        $problems = [];

        // Browsers and libxml parse broken markup differently ("<!-->", unclosed tags, comments around tags), so a DOM
        // walk alone can be talked past. These checks look at the raw text and are deliberately conservative:
        // comments and other "<!" / "<?" constructs are not needed in survey texts and are refused, and every tag
        // name that appears anywhere (also inside attribute values or comments) must be on the allowlist.
        if (preg_match('/<[!?]/', $html) === 1) {
            $problems[] = 'Kommentare und Sonderkonstrukte (<!…, <?…) sind nicht erlaubt';
        }
        if (preg_match_all('/<\/?\s*([a-zA-Z][^\s\/>\x00]*)/', $html, $m) > 0) {
            foreach (array_unique(array_map('strtolower', $m[1])) as $name) {
                if (!isset(self::ALLOWED[$name])) {
                    $problems[] = "Nicht erlaubtes HTML-Element <{$name}>";
                }
            }
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // The wrapper keeps fragments (several top-level nodes, plain text) parseable.
        $doc->loadHTML(
            '<?xml encoding="utf-8" ?><div id="__root">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('__root');
        if ($root === null) {
            return ['HTML konnte nicht gelesen werden'];
        }

        self::walk($root, $problems);

        return array_values(array_unique($problems));
    }

    /**
     * @param list<string> $problems
     */
    private static function walk(\DOMNode $node, array &$problems): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (!isset(self::ALLOWED[$tag])) {
                    $problems[] = "Nicht erlaubtes HTML-Element <{$tag}>";
                } else {
                    foreach ($child->attributes as $attr) {
                        $name = strtolower($attr->name);
                        if (!in_array($name, self::ALLOWED[$tag], true)) {
                            $problems[] = "Nicht erlaubtes Attribut {$name} an <{$tag}>";
                        } elseif ($name === 'href' && !self::isSafeUrl($attr->value)) {
                            $problems[] = 'Nicht erlaubte URL in href (erlaubt: http, https, mailto, tel, relative Links)';
                        }
                    }
                }
                self::walk($child, $problems);
            }
            // Text and comment nodes are harmless.
        }
    }

    public static function isSafeUrl(string $url): bool
    {
        // Browsers ignore control characters and whitespace inside the scheme ("java\tscript:").
        $normalized = strtolower(preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($url)) ?? '');
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $normalized, $m) === 1) {
            return in_array($m[1], self::SAFE_SCHEMES, true);
        }
        return true;
    }

    /** True for values like "javascript:alert(1)" regardless of where they appear (e.g. navigateToUrl). */
    public static function isDangerousScheme(string $value): bool
    {
        $normalized = strtolower(preg_replace('/[\x00-\x20\x7f]+/', '', $value) ?? '');
        return preg_match('/^(javascript|vbscript|data):/', $normalized) === 1;
    }
}
