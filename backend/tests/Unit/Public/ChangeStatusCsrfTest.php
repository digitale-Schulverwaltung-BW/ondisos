<?php
declare(strict_types=1);

namespace Tests\Unit\Public;

use PHPUnit\Framework\TestCase;

/**
 * change_status.php changes the status and soft-deletes registrations, so it needs the same CSRF protection as
 * bulk_actions.php, restore.php and hard_delete.php; every form posting to it must carry the token.
 */
class ChangeStatusCsrfTest extends TestCase
{
    public function testEndpointValidatesTheCsrfToken(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../public/change_status.php');

        $this->assertStringContainsString("inc/csrf.php", $source);
        $this->assertStringContainsString('csrf_validate()', $source);
        $this->assertLessThan(
            strpos($source, "\$_POST['id']"),
            strpos($source, 'csrf_validate()'),
            'the token must be checked before any POST data is used'
        );
    }

    public function testEveryChangeStatusFormInDetailCarriesTheToken(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../public/detail.php');

        $this->assertStringContainsString("inc/csrf.php", $source);

        $count = preg_match_all('/<form\b[^>]*action="change_status\.php"[^>]*>.*?<\/form>/s', $source, $forms);
        $this->assertGreaterThanOrEqual(4, $count, 'status and delete forms not found');

        foreach ($forms[0] as $form) {
            $this->assertStringContainsString('csrf_field()', $form, 'form without CSRF token: ' . substr($form, 0, 80));
        }
    }
}
