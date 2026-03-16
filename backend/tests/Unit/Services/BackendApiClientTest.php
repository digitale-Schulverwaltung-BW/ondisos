<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * Wave 0 stubs for BackendApiClient::fetchFormConfig().
 *
 * fetchFormConfig() does not yet exist — it will be added to
 * frontend/src/Services/BackendApiClient.php in Plan 03.
 *
 * The method fetches the form config JSON from the backend API,
 * appending form and tenant query parameters to the base URL.
 *
 * These tests are AMBER (markTestIncomplete) — they define the expected
 * interface and behavior contracts.
 *
 * Expected interface:
 *   $client->fetchFormConfig(string $formKey, string $tenantSlug): ?array
 *
 * Contracts tested:
 *   - URL is constructed as: baseUrl + '/form-config.php?form={key}&tenant={slug}'
 *   - Returns null when backend responds with non-200 HTTP status
 */
class BackendApiClientTest extends TestCase
{
    // =========================================================================
    // fetchFormConfig — URL construction
    // =========================================================================

    public function testFetchFormConfigAppendsFormAndTenantParams(): void
    {
        $this->markTestIncomplete('BackendApiClient::fetchFormConfig() not yet implemented');
    }

    // =========================================================================
    // fetchFormConfig — error handling
    // =========================================================================

    public function testFetchFormConfigReturnsNullOnNon200(): void
    {
        $this->markTestIncomplete('BackendApiClient::fetchFormConfig() not yet implemented');
    }
}
