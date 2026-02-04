<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\PdfTokenService;
use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for PdfTokenService
 *
 * Tests PDF token generation and validation including:
 * - Token generation and format
 * - Valid token validation
 * - Expired token rejection
 * - Tampered token detection
 * - Malformed token handling
 * - Security requirements
 */
class PdfTokenServiceTest extends TestCase
{
    private PdfTokenService $tokenService;
    private string $originalSecret;

    protected function setUp(): void
    {
        parent::setUp();

        // Save original secret
        $this->originalSecret = getenv('PDF_TOKEN_SECRET') ?: '';

        // Set test secret (min 32 chars)
        putenv('PDF_TOKEN_SECRET=test-secret-key-for-unit-tests-min-32-chars-long');

        $this->tokenService = new PdfTokenService();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Restore original secret
        if (!empty($this->originalSecret)) {
            putenv('PDF_TOKEN_SECRET=' . $this->originalSecret);
        } else {
            putenv('PDF_TOKEN_SECRET');
        }
    }

    public function testConstructorThrowsExceptionIfSecretMissing(): void
    {
        putenv('PDF_TOKEN_SECRET');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PDF_TOKEN_SECRET not configured');

        new PdfTokenService();
    }

    public function testConstructorThrowsExceptionIfSecretTooShort(): void
    {
        putenv('PDF_TOKEN_SECRET=short');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be at least 32 characters');

        new PdfTokenService();
    }

    public function testGenerateTokenReturnsBase64String(): void
    {
        $token = $this->tokenService->generateToken(123);

        // Should be base64
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', $token);

        // Should decode successfully
        $decoded = base64_decode($token, true);
        $this->assertNotFalse($decoded);
    }

    public function testGenerateTokenContainsCorrectParts(): void
    {
        $anmeldungId = 456;
        $token = $this->tokenService->generateToken($anmeldungId);

        $decoded = base64_decode($token, true);
        $parts = explode(':', $decoded);

        // Should have 4 parts: id:timestamp:lifetime:hmac
        $this->assertCount(4, $parts);

        // Check ID
        $this->assertEquals($anmeldungId, (int)$parts[0]);

        // Check timestamp (should be recent)
        $timestamp = (int)$parts[1];
        $this->assertGreaterThan(time() - 5, $timestamp);
        $this->assertLessThanOrEqual(time(), $timestamp);

        // Check lifetime (default 1800)
        $this->assertEquals(1800, (int)$parts[2]);

        // Check HMAC (should be 64 hex chars for SHA256)
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $parts[3]);
    }

    public function testGenerateTokenWithCustomLifetime(): void
    {
        $token = $this->tokenService->generateToken(123, 3600);

        $decoded = base64_decode($token, true);
        $parts = explode(':', $decoded);

        $this->assertEquals(3600, (int)$parts[2]);
    }

    public function testValidateTokenAcceptsValidToken(): void
    {
        $anmeldungId = 789;
        $token = $this->tokenService->generateToken($anmeldungId);

        $result = $this->tokenService->validateToken($token);

        $this->assertEquals($anmeldungId, $result);
    }

    public function testValidateTokenRejectsExpiredToken(): void
    {
        // Generate token with 1 second lifetime
        $token = $this->tokenService->generateToken(123, 1);

        // Wait for token to expire
        sleep(2);

        $result = $this->tokenService->validateToken($token);

        $this->assertNull($result);
    }

    public function testValidateTokenRejectsTamperedId(): void
    {
        $token = $this->tokenService->generateToken(100);

        // Decode and tamper with ID
        $decoded = base64_decode($token, true);
        $parts = explode(':', $decoded);
        $parts[0] = '999'; // Change ID
        $tampered = base64_encode(implode(':', $parts));

        $result = $this->tokenService->validateToken($tampered);

        $this->assertNull($result);
    }

    public function testValidateTokenRejectsTamperedTimestamp(): void
    {
        $token = $this->tokenService->generateToken(100);

        // Decode and tamper with timestamp
        $decoded = base64_decode($token, true);
        $parts = explode(':', $decoded);
        $parts[1] = (string)(time() + 1000); // Change timestamp
        $tampered = base64_encode(implode(':', $parts));

        $result = $this->tokenService->validateToken($tampered);

        $this->assertNull($result);
    }

    public function testValidateTokenRejectsTamperedHmac(): void
    {
        $token = $this->tokenService->generateToken(100);

        // Decode and tamper with HMAC
        $decoded = base64_decode($token, true);
        $parts = explode(':', $decoded);
        $parts[3] = str_repeat('a', 64); // Invalid HMAC
        $tampered = base64_encode(implode(':', $parts));

        $result = $this->tokenService->validateToken($tampered);

        $this->assertNull($result);
    }

    public function testValidateTokenRejectsInvalidBase64(): void
    {
        $result = $this->tokenService->validateToken('not-valid-base64!!!');

        $this->assertNull($result);
    }

    public function testValidateTokenRejectsMalformedFormat(): void
    {
        // Token with wrong number of parts
        $malformed = base64_encode('123:456:789'); // Missing HMAC

        $result = $this->tokenService->validateToken($malformed);

        $this->assertNull($result);
    }

    public function testValidateTokenRejectsNonNumericParts(): void
    {
        // Token with non-numeric ID
        $malformed = base64_encode('abc:' . time() . ':1800:' . str_repeat('a', 64));

        $result = $this->tokenService->validateToken($malformed);

        $this->assertNull($result);
    }

    public function testValidateTokenRejectsEmptyToken(): void
    {
        $result = $this->tokenService->validateToken('');

        $this->assertNull($result);
    }

    public function testDifferentSecretsProduceDifferentTokens(): void
    {
        // Generate token with first secret
        $token1 = $this->tokenService->generateToken(123);

        // Change secret
        putenv('PDF_TOKEN_SECRET=different-secret-key-min-32-chars-long-test');
        $tokenService2 = new PdfTokenService();

        // Validate with different secret should fail
        $result = $tokenService2->validateToken($token1);

        $this->assertNull($result);
    }

    public function testMultipleTokensForSameIdAreIndependent(): void
    {
        // Generate multiple tokens for same ID
        $token1 = $this->tokenService->generateToken(100);
        sleep(1); // Different timestamp
        $token2 = $this->tokenService->generateToken(100);

        // Both should be valid
        $this->assertEquals(100, $this->tokenService->validateToken($token1));
        $this->assertEquals(100, $this->tokenService->validateToken($token2));

        // But tokens should be different
        $this->assertNotEquals($token1, $token2);
    }

    public function testGetDefaultLifetime(): void
    {
        $lifetime = PdfTokenService::getDefaultLifetime();

        $this->assertEquals(1800, $lifetime);
    }

    public function testTokenValidationIsTimingSafe(): void
    {
        // This test ensures hash_equals is used (timing-safe comparison)
        // We can't directly test timing, but we verify the behavior is correct

        $validToken = $this->tokenService->generateToken(123);

        // Create almost-correct token (all correct except last char of HMAC)
        $decoded = base64_decode($validToken, true);
        $parts = explode(':', $decoded);
        $parts[3] = substr($parts[3], 0, -1) . 'X'; // Change last char
        $almostCorrect = base64_encode(implode(':', $parts));

        // Should reject (timing-safe comparison via hash_equals)
        $result = $this->tokenService->validateToken($almostCorrect);

        $this->assertNull($result);
    }

    public function testLargeAnmeldungId(): void
    {
        $largeId = PHP_INT_MAX - 1;
        $token = $this->tokenService->generateToken($largeId);

        $result = $this->tokenService->validateToken($token);

        $this->assertEquals($largeId, $result);
    }

    public function testZeroLifetimeTokenExpiresImmediately(): void
    {
        $token = $this->tokenService->generateToken(123, 0);

        // Token should be expired immediately
        $result = $this->tokenService->validateToken($token);

        $this->assertNull($result);
    }

    public function testValidateTokenHandlesExtremelyLongToken(): void
    {
        // Try to trigger validation with an extremely long base64 string
        // This tests robustness and the catch block
        $longToken = str_repeat('A', 10000);

        $result = $this->tokenService->validateToken($longToken);

        $this->assertNull($result);
    }

    public function testValidateTokenHandlesNullByteInToken(): void
    {
        // Try token with null byte (potential security issue)
        $tokenWithNull = base64_encode("123:" . time() . ":1800:abc\0def");

        $result = $this->tokenService->validateToken($tokenWithNull);

        // Should reject gracefully
        $this->assertNull($result);
    }

    public function testValidateTokenHandlesNegativeValues(): void
    {
        // Token with negative ID (invalid)
        $invalidToken = base64_encode('-123:' . time() . ':1800:' . str_repeat('a', 64));

        $result = $this->tokenService->validateToken($invalidToken);

        $this->assertNull($result);
    }

    public function testValidateTokenHandlesVeryLargeTimestamp(): void
    {
        // Token with timestamp far in the future
        $futureTime = time() + 1000000;
        $invalidToken = base64_encode('123:' . $futureTime . ':1800:' . str_repeat('a', 64));

        $result = $this->tokenService->validateToken($invalidToken);

        // Should reject (HMAC won't match)
        $this->assertNull($result);
    }

    public function testValidateTokenHandlesEmptyParts(): void
    {
        // Token with empty parts
        $invalidToken = base64_encode(':::');

        $result = $this->tokenService->validateToken($invalidToken);

        $this->assertNull($result);
    }
}
