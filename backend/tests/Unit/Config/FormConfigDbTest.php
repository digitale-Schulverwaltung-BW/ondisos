<?php
declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\FormConfig;
use PHPUnit\Framework\TestCase;

/**
 * Wave 0 stubs for DB-backed FormConfig::get().
 *
 * Currently FormConfig reads from a PHP file (forms-config.php).
 * After Plan 03 implementation, FormConfig::get() will query the
 * form_configs table using TenantContext::getTenantId().
 *
 * These tests are AMBER (markTestIncomplete) — they define the expected
 * interface and behavior contracts for the DB-backed implementation.
 *
 * Contracts tested:
 *   - FormConfig::get() uses TenantContext tenant_id in the DB query
 *   - FormConfig::get() returns null for unknown form keys
 *   - FormConfig::get() JSON-decodes the config_json column
 *   - FormConfig::reset() clears the static cache
 */
class FormConfigDbTest extends TestCase
{
    // =========================================================================
    // DB-backed get() — tenant scoping
    // =========================================================================

    public function testGetQueriesDbWithCorrectTenantId(): void
    {
        $this->markTestIncomplete('FormConfig DB rewrite not yet implemented');
    }

    // =========================================================================
    // DB-backed get() — unknown form key
    // =========================================================================

    public function testGetReturnsNullForUnknownFormKey(): void
    {
        $this->markTestIncomplete('FormConfig DB rewrite not yet implemented');
    }

    // =========================================================================
    // DB-backed get() — JSON decoding
    // =========================================================================

    public function testGetDecodesConfigJsonToArray(): void
    {
        $this->markTestIncomplete('FormConfig DB rewrite not yet implemented');
    }

    // =========================================================================
    // reset() — cache invalidation
    // =========================================================================

    public function testResetClearsStaticCache(): void
    {
        $this->markTestIncomplete('FormConfig DB rewrite not yet implemented');
    }
}
