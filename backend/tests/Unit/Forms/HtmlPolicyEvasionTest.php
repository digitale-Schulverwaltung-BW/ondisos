<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\HtmlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Known XSS evasion shapes must all be reported by the allowlist; harmless markup must not.
 */
class HtmlPolicyEvasionTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function attacks(): array
    {
        return [
            'upper case script'          => ['<SCRIPT>alert(1)</SCRIPT>'],
            'mixed case handler'         => ['<a href="https://x.de" OnClick="alert(1)">x</a>'],
            'handler without quotes'     => ['<a href=https://x.de onmouseover=alert(1)>x</a>'],
            'entity encoded javascript'  => ['<a href="&#106;avascript:alert(1)">x</a>'],
            'hex entity javascript'      => ['<a href="&#x6A;avascript:alert(1)">x</a>'],
            'tab in scheme'              => ["<a href=\"java\tscript:alert(1)\">x</a>"],
            'newline in scheme'          => ["<a href=\"java\nscript:alert(1)\">x</a>"],
            'leading spaces'             => ['<a href="   javascript:alert(1)">x</a>'],
            'control char prefix'        => ["<a href=\"\x01javascript:alert(1)\">x</a>"],
            'vbscript'                   => ['<a href="vbscript:msgbox(1)">x</a>'],
            'data html'                  => ['<a href="data:text/html,<script>alert(1)</script>">x</a>'],
            'file url'                   => ['<a href="file:///etc/passwd">x</a>'],
            'ftp url'                    => ['<a href="ftp://example.org/x">x</a>'],
            'protocol relative javascript' => ['<a href="//evil.example/x" onclick="1">x</a>'],
            'svg with script'            => ['<svg><script>alert(1)</script></svg>'],
            'svg onload'                 => ['<svg/onload=alert(1)>'],
            'math'                       => ['<math><mi xlink:href="javascript:alert(1)">x</mi></math>'],
            'img onerror'                => ['<img src=x onerror=alert(1)>'],
            'img src only'               => ['<img src="https://tracker.example/p.gif">'],
            'iframe srcdoc'              => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>'],
            'object'                     => ['<object data="https://evil.example/x.swf"></object>'],
            'embed'                      => ['<embed src="https://evil.example/x">'],
            'base tag'                   => ['<base href="https://evil.example/">'],
            'meta refresh'               => ['<meta http-equiv="refresh" content="0;url=https://evil.example">'],
            'link stylesheet'            => ['<link rel="stylesheet" href="https://evil.example/x.css">'],
            'style tag'                  => ['<style>@import url(https://evil.example/x.css)</style>'],
            'style attribute'            => ['<p style="background:url(https://evil.example/x)">x</p>'],
            'form with action'           => ['<form action="https://evil.example"><button formaction="https://evil.example">x</button></form>'],
            'button formaction'          => ['<button formaction="https://evil.example">x</button>'],
            'input autofocus handler'    => ['<input autofocus onfocus=alert(1)>'],
            'details ontoggle'           => ['<details open ontoggle=alert(1)>x</details>'],
            'video source'               => ['<video><source src=x onerror=alert(1)></video>'],
            'template'                   => ['<template><script>alert(1)</script></template>'],
            'noscript breakout'          => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>">'],
            'xml namespace'              => ['<x:script xmlns:x="http://www.w3.org/1999/xhtml">alert(1)</x:script>'],
            'data attribute'             => ['<p data-x="1">x</p>'],
            'id attribute'               => ['<p id="x">x</p>'],
            'class attribute'            => ['<p class="x">x</p>'],
            'unclosed tag swallowing'    => ['<a href="https://x.de" <script>alert(1)</script>'],
            'comment hiding a tag'       => ['<!--><script>alert(1)</script>-->'],
            'conditional comment'        => ['<!--[if IE]><script>alert(1)</script><![endif]-->'],
            'cdata'                      => ['<![CDATA[<script>alert(1)</script>]]>'],
            'processing instruction'     => ['<?php echo 1; ?><script>x</script>'],
            'null byte in tag'           => ["<scr\0ipt>alert(1)</scr\0ipt>"],
        ];
    }

    #[DataProvider('attacks')]
    public function testAttackShapesAreReported(string $html): void
    {
        $this->assertNotEmpty(HtmlPolicy::check($html), 'accepted: ' . json_encode($html));
    }

    /** @return array<string,array{0:string}> */
    public static function harmless(): array
    {
        return [
            'plain text'          => ['Ein ganz normaler Text'],
            'umlauts and entities' => ['Gr&uuml;&szlig;e &amp; Gr&#252;&#223;e für alle &euro;'],
            'comparison in text'  => ['a &lt; b und c &gt; d'],
            'formatting'          => ['<p>Text <b>fett</b> <i>kursiv</i> <u>unterstrichen</u><br />Zeile<sup>2</sup></p>'],
            'headings and lists'  => ['<h1>A</h1><h3>B</h3><ul><li>x</li><li>y</li></ul><ol><li>1</li></ol>'],
            'https link'          => ['<a href="https://www.hhs.karlsruhe.de/datenschutz/" target="_blank" rel="noopener noreferrer" title="Info">Datenschutz</a>'],
            'mail and phone link' => ['<a href="mailto:a@b.de">Mail</a> <a href="tel:+49721123456">Anrufen</a>'],
            'relative link'       => ['<a href="/datenschutz">x</a> <a href="#anker">y</a> <a href="seite.html?x=1">z</a>'],
            'span and div'        => ['<div><span>x</span></div>'],
            'horizontal rule'     => ['<hr>'],
        ];
    }

    #[DataProvider('harmless')]
    public function testHarmlessMarkupIsAccepted(string $html): void
    {
        $this->assertSame([], HtmlPolicy::check($html), $html);
    }
}
