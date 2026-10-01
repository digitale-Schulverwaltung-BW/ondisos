<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\JsonLocator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JsonLocatorTest extends TestCase
{
    public function testValidJsonHasNoErrorAndEveryValueHasALine(): void
    {
        $json = "{\n  \"pages\": [\n    {\n      \"elements\": [\n        {\"type\": \"text\", \"name\": \"a\"},\n        {\"type\": \"html\",\n         \"html\": \"x\"}\n      ]\n    }\n  ]\n}";

        $r = JsonLocator::analyze($json);

        $this->assertNull($r['error']);
        $this->assertSame(2, $r['lines']['pages']);
        $this->assertSame(5, $r['lines']['pages[0].elements[0]']);
        $this->assertSame(5, $r['lines']['pages[0].elements[0].name']);
        $this->assertSame(6, $r['lines']['pages[0].elements[1]']);
        $this->assertSame(7, $r['lines']['pages[0].elements[1].html']);
    }

    /** @return array<string,array{0:string,1:int,2:int,3:string}> json, line, column, fragment of the message */
    public static function brokenJson(): array
    {
        return [
            'missing comma'        => ["{\n  \"a\": 1\n  \"b\": 2\n}", 3, 3, 'Komma'],
            'trailing comma'       => ["{\n  \"a\": 1,\n}", 3, 1, 'Name in Anführungszeichen'],
            'unclosed object'      => ["{\n  \"a\": 1", 2, 9, 'nicht geschlossen'],
            'unclosed array'       => ["[1, 2", 1, 6, 'nicht geschlossen'],
            'unclosed string'      => ["{\"a\": \"text}", 1, 7, 'nicht abgeschlossen'],
            'single quotes'        => ["{'a': 1}", 1, 2, 'Name in Anführungszeichen'],
            'missing colon'        => ["{\"a\" 1}", 1, 6, 'Doppelpunkt'],
            'bad literal'          => ["{\"a\": tru}", 1, 7, 'erwartet: true'],
            'bad number'           => ["{\"a\": 01}", 1, 8, 'Komma'],
            'newline in string'    => ["{\"a\": \"x\ny\"}", 1, 9, 'Zeilenumbruch'],
            'bad escape'           => ["{\"a\": \"x\\qy\"}", 1, 10, 'Escape'],
            'text after value'     => ["{}\nfoo", 2, 1, 'weiterer Text'],
            'empty input'          => ['', 1, 1, 'fehlt ein Wert'],
            'comment'              => ["{\n// kommentar\n\"a\": 1}", 2, 1, 'Name in Anführungszeichen'],
        ];
    }

    #[DataProvider('brokenJson')]
    public function testSyntaxErrorPosition(string $json, int $line, int $column, string $message): void
    {
        $e = JsonLocator::analyze($json)['error'];

        $this->assertNotNull($e, 'must find the error');
        $this->assertSame($line, $e['line'], $e['message']);
        $this->assertSame($column, $e['column'], $e['message']);
        $this->assertStringContainsString($message, $e['message']);
    }

    public function testAgreesWithJsonDecodeOnValidityForManyInputs(): void
    {
        $inputs = [
            '{}', '[]', '[1,2,3]', '{"a":[{"b":null}]}', '"text"', '12', '-0.5e+3', 'true', '{"a":"ä\n"}',
            '{"a":1,}', '[1,]', '{a:1}', '{"a":}', '[,1]', '01', '1.', '.5', '"\x01"', "{\"a\":\"\t\"}", 'nul', '[1 2]', '{"a":1 "b":2}',
            "\xEF\xBB\xBF{\"a\":1}",
        ];
        foreach ($inputs as $in) {
            $mine  = JsonLocator::analyze($in)['error'] === null;
            // json_decode refuses a BOM; the locator skips it (the editor trims input), so compare without it.
            $theirs = json_decode(ltrim($in, "\xEF\xBB\xBF")) !== null || trim(ltrim($in, "\xEF\xBB\xBF")) === 'null';
            $this->assertSame($theirs, $mine, 'disagreement for ' . json_encode($in));
        }
    }

    public function testDeeplyNestedInputIsAnErrorNotACrash(): void
    {
        $r = JsonLocator::analyze(str_repeat('[', 200) . str_repeat(']', 200));
        $this->assertNotNull($r['error']);
        $this->assertStringContainsString('verschachtelt', $r['error']['message']);
    }

    public function testObjectKeyLineIsWhereTheKeyIs(): void
    {
        $r = JsonLocator::analyze("{\n  \"a\":\n    {\"b\": 1}\n}");
        $this->assertSame(2, $r['lines']['a'], 'the key line, even if the value starts below');
        $this->assertSame(3, $r['lines']['a.b']);
    }

    public function testLinesOfRealSurveyPointAtTheRightPlaces(): void
    {
        $text = (string)file_get_contents(__DIR__ . '/../../../../frontend/surveys/bs.json');
        $r    = JsonLocator::analyze($text);
        $this->assertNull($r['error']);

        $lines = explode("\n", $text);
        $line  = $r['lines']['pages[0].elements[0].name'];
        $this->assertStringContainsString('"name"', $lines[$line - 1]);
    }
}
