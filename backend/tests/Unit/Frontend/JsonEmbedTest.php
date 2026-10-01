<?php
declare(strict_types=1);

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class JsonEmbedTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../frontend/src/Utils/JsonEmbed.php';
    }

    private function enc(string $json): string
    {
        return \Frontend\Utils\JsonEmbed::encode($json);
    }

    public function testScriptEndTagCannotBreakOutOfTheScriptElement(): void
    {
        $out = $this->enc('{"html":"</script><script>alert(1)</script>","c":"<!-- x -->"}');

        $this->assertStringNotContainsString('<', $out);
        $this->assertStringNotContainsString('>', $out);
        $this->assertStringNotContainsStringIgnoringCase('</script', $out);
        $this->assertStringNotContainsString('<!--', $out);
    }

    public function testMeaningIsPreserved(): void
    {
        $in  = '{"a":"</script>","b":"Tom & Jerry","c":"it\'s \"q\"","d":"Größe €","e":[1,2.0,true,null],"f":"a/b"}';
        $out = $this->enc($in);

        $this->assertEquals(json_decode($in), json_decode($out), 'decoded content identical');
        $this->assertStringContainsString('Größe €', $out, 'umlauts stay readable');
        $this->assertStringContainsString('a/b', $out, 'slashes not escaped');
    }

    public function testEmptyObjectsStayObjectsAndEmptyArraysStayArrays(): void
    {
        $out = $this->enc('{"o":{},"a":[],"nested":{"x":{}}}');
        $this->assertSame('{"o":{},"a":[],"nested":{"x":{}}}', $out);
    }

    public function testFloatsAndLargeNumbersSurvive(): void
    {
        $this->assertSame('{"f":2.0,"n":1.5e+25}', str_replace('E', 'e', $this->enc('{"f":2.0,"n":1.5e25}')));
        $this->assertSame('{"big":"12345678901234567890"}', $this->enc('{"big":12345678901234567890}'));
    }

    public function testLineSeparatorsAreEscapedForOlderJavaScriptEngines(): void
    {
        $out = $this->enc("{\"a\":\"x\u{2028}y\u{2029}z\"}");
        $this->assertStringNotContainsString("\u{2028}", $out);
        $this->assertStringNotContainsString("\u{2029}", $out);
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(\JsonException::class);
        $this->enc('{"a": ');
    }

    public function testOutputIsAValidJavaScriptExpressionAssignedInsideAnObjectLiteral(): void
    {
        $out = $this->enc('{"k":"v "}');
        $this->assertStringStartsWith('{', $out);
        $this->assertStringEndsWith('}', $out);
    }
}
