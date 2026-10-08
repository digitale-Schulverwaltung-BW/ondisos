<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\UploadPolicy;
use PHPUnit\Framework\TestCase;

class UploadPolicyTest extends TestCase
{
    private const SURVEY = [
        'pages' => [[
            'elements' => [
                ['type' => 'text', 'name' => 'vorname'],
                ['type' => 'file', 'name' => 'zeugnis'],
                ['type' => 'panel', 'name' => 'p', 'elements' => [
                    ['type' => 'file', 'name' => 'foto'],
                ]],
            ],
        ]],
    ];

    public function testFileQuestionIsAccepted(): void
    {
        $this->assertTrue(UploadPolicy::isFileField(self::SURVEY, 'zeugnis'));
        $this->assertTrue(UploadPolicy::isFileField(self::SURVEY, 'foto'));
    }

    public function testIndexedFileFieldFromFrontendIsAccepted(): void
    {
        $this->assertTrue(UploadPolicy::isFileField(self::SURVEY, 'zeugnis_0'));
        $this->assertTrue(UploadPolicy::isFileField(self::SURVEY, 'foto_12'));
    }

    public function testNonFileQuestionAndUnknownNamesAreRejected(): void
    {
        $this->assertFalse(UploadPolicy::isFileField(self::SURVEY, 'vorname'));
        $this->assertFalse(UploadPolicy::isFileField(self::SURVEY, 'vorname_0'));
        $this->assertFalse(UploadPolicy::isFileField(self::SURVEY, 'evil'));
        $this->assertFalse(UploadPolicy::isFileField(self::SURVEY, '../etc'));
        $this->assertFalse(UploadPolicy::isFileField(self::SURVEY, ''));
    }

    public function testCapsOnFileCount(): void
    {
        $this->assertNull(UploadPolicy::capError(19, 0, 10, 20, 0));
        $this->assertNotNull(UploadPolicy::capError(20, 0, 10, 20, 0));
    }

    public function testCapsOnTotalBytes(): void
    {
        $this->assertNull(UploadPolicy::capError(1, 40, 10, 20, 50));
        $this->assertNotNull(UploadPolicy::capError(1, 41, 10, 20, 50));
    }

    public function testZeroDisablesCaps(): void
    {
        $this->assertNull(UploadPolicy::capError(1000, 10 ** 12, 10 ** 9, 0, 0));
    }
}
