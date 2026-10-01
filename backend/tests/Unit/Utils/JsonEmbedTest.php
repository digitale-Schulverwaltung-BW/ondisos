<?php
declare(strict_types=1);

namespace Tests\Unit\Utils;

use App\Utils\JsonEmbed;
use PHPUnit\Framework\TestCase;

class JsonEmbedTest extends TestCase
{
    public function testScriptEndTagAndCommentsCannotBreakOutOfAScriptElement(): void
    {
        $out = JsonEmbed::encode('{"h":"</script><script>alert(1)</script>","c":"<!-- x -->","a":"&amp;"}');

        $this->assertStringNotContainsString('<', $out);
        $this->assertStringNotContainsString('>', $out);
        $this->assertStringNotContainsString('&', $out);
    }

    public function testMeaningIsPreservedAndObjectsStayObjects(): void
    {
        $in = '{"o":{},"a":[],"s":"Größe € \"q\" it\'s","n":[1,2.0,null,true],"big":12345678901234567890}';
        $out = JsonEmbed::encode($in);

        $this->assertStringContainsString('"o":{}', $out);
        $this->assertStringContainsString('"a":[]', $out);
        $this->assertStringContainsString('Größe €', $out);
        $this->assertSame('{"big":"12345678901234567890"}', JsonEmbed::encode('{"big":12345678901234567890}'));
        $this->assertEquals(json_decode('{"o":{},"a":[],"n":[1,2.0,null,true]}'), json_decode(JsonEmbed::encode('{"o":{},"a":[],"n":[1,2.0,null,true]}')));
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(\JsonException::class);
        JsonEmbed::encode('{"a": ');
    }
}
