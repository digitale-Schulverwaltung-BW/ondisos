<?php
declare(strict_types=1);

namespace Tests\Unit\Validators;

use App\Validators\AnmeldungValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the AnmeldungValidator::validate() instance method
 * (form submission validation, distinct from file and formular-name validation)
 */
class AnmeldungFormValidatorTest extends TestCase
{
    private AnmeldungValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new AnmeldungValidator();
    }

    // =========================================================================
    // validate() - happy path
    // =========================================================================

    public function testValidateReturnsTrueForValidData(): void
    {
        $data = [
            'formular' => 'bs',
            'name' => 'Max Mustermann',
            'email' => 'max@example.com',
        ];

        $result = $this->validator->validate($data);

        $this->assertTrue($result);
        $this->assertEmpty($this->validator->getErrors());
    }

    public function testValidateReturnsTrueWithOptionalStatusSet(): void
    {
        $data = [
            'formular' => 'bs',
            'name' => 'Max Mustermann',
            'email' => 'max@example.com',
            'status' => 'in_bearbeitung',
        ];

        $result = $this->validator->validate($data);

        $this->assertTrue($result);
        $this->assertEmpty($this->validator->getErrors());
    }

    public function testValidateAcceptsAllValidStatuses(): void
    {
        $validStatuses = ['neu', 'in_bearbeitung', 'akzeptiert', 'abgelehnt', 'archiviert'];

        foreach ($validStatuses as $status) {
            $this->validator->validate([
                'formular' => 'bs',
                'name' => 'Max',
                'email' => 'max@example.com',
                'status' => $status,
            ]);

            $this->assertArrayNotHasKey('status', $this->validator->getErrors(), "Status '$status' should be valid");
        }
    }

    // =========================================================================
    // validate() - required fields
    // =========================================================================

    public function testValidateFailsWhenFormularMissing(): void
    {
        $data = ['name' => 'Max', 'email' => 'max@example.com'];

        $result = $this->validator->validate($data);

        $this->assertFalse($result);
        $this->assertArrayHasKey('formular', $this->validator->getErrors());
    }

    public function testValidateFailsWhenFormularIsEmpty(): void
    {
        $data = ['formular' => '   ', 'name' => 'Max', 'email' => 'max@example.com'];

        $result = $this->validator->validate($data);

        $this->assertFalse($result);
        $this->assertArrayHasKey('formular', $this->validator->getErrors());
    }

    public function testValidateFailsWhenNameMissing(): void
    {
        $data = ['formular' => 'bs', 'email' => 'max@example.com'];

        $result = $this->validator->validate($data);

        $this->assertFalse($result);
        $this->assertArrayHasKey('name', $this->validator->getErrors());
    }

    public function testValidateFailsWhenNameIsEmpty(): void
    {
        $data = ['formular' => 'bs', 'name' => '', 'email' => 'max@example.com'];

        $result = $this->validator->validate($data);

        $this->assertFalse($result);
        $this->assertArrayHasKey('name', $this->validator->getErrors());
    }

    public function testValidateFailsWhenEmailMissing(): void
    {
        $data = ['formular' => 'bs', 'name' => 'Max'];

        $result = $this->validator->validate($data);

        $this->assertFalse($result);
        $this->assertArrayHasKey('email', $this->validator->getErrors());
    }

    public function testValidateFailsWhenAllFieldsMissing(): void
    {
        $result = $this->validator->validate([]);

        $this->assertFalse($result);
        $errors = $this->validator->getErrors();
        $this->assertArrayHasKey('formular', $errors);
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);
    }

    // =========================================================================
    // validate() - email format
    // =========================================================================

    public function testValidateFailsForInvalidEmailFormat(): void
    {
        $data = [
            'formular' => 'bs',
            'name' => 'Max',
            'email' => 'not-an-email',
        ];

        $result = $this->validator->validate($data);

        $this->assertFalse($result);
        $this->assertArrayHasKey('email', $this->validator->getErrors());
    }

    public function testValidateFailsForEmailWithoutDomain(): void
    {
        $data = [
            'formular' => 'bs',
            'name' => 'Max',
            'email' => 'max@',
        ];

        $result = $this->validator->validate($data);

        $this->assertFalse($result);
        $this->assertArrayHasKey('email', $this->validator->getErrors());
    }

    public function testValidateAcceptsValidEmailFormats(): void
    {
        $validEmails = [
            'user@example.com',
            'user+tag@example.org',
            'firstname.lastname@school.de',
        ];

        foreach ($validEmails as $email) {
            $result = $this->validator->validate([
                'formular' => 'bs',
                'name' => 'Max',
                'email' => $email,
            ]);
            $this->assertTrue($result, "Email '$email' should be valid");
        }
    }

    // =========================================================================
    // validate() - name length
    // =========================================================================

    public function testValidateFailsForNameExceeding255Chars(): void
    {
        $data = [
            'formular' => 'bs',
            'name' => str_repeat('a', 256),
            'email' => 'max@example.com',
        ];

        $result = $this->validator->validate($data);

        $this->assertFalse($result);
        $this->assertArrayHasKey('name', $this->validator->getErrors());
    }

    public function testValidateAcceptsNameOfExactly255Chars(): void
    {
        $data = [
            'formular' => 'bs',
            'name' => str_repeat('a', 255),
            'email' => 'max@example.com',
        ];

        $result = $this->validator->validate($data);

        $this->assertTrue($result);
    }

    // =========================================================================
    // validate() - status validation
    // =========================================================================

    public function testValidateFailsForInvalidStatus(): void
    {
        $data = [
            'formular' => 'bs',
            'name' => 'Max',
            'email' => 'max@example.com',
            'status' => 'invalid_status',
        ];

        $result = $this->validator->validate($data);

        $this->assertFalse($result);
        $this->assertArrayHasKey('status', $this->validator->getErrors());
    }

    public function testValidateOmittedStatusIsAllowed(): void
    {
        // No status key at all - should be fine
        $data = ['formular' => 'bs', 'name' => 'Max', 'email' => 'max@example.com'];

        $result = $this->validator->validate($data);

        $this->assertTrue($result);
        $this->assertArrayNotHasKey('status', $this->validator->getErrors());
    }

    // =========================================================================
    // validate() - error state resets between calls
    // =========================================================================

    public function testValidateResetsErrorsBetweenCalls(): void
    {
        // First call: invalid
        $this->validator->validate([]);
        $this->assertNotEmpty($this->validator->getErrors());

        // Second call: valid
        $this->validator->validate([
            'formular' => 'bs',
            'name' => 'Max',
            'email' => 'max@example.com',
        ]);
        $this->assertEmpty($this->validator->getErrors());
    }

    // =========================================================================
    // getErrors / getFirstError
    // =========================================================================

    public function testGetErrorsReturnsEmptyArrayInitially(): void
    {
        $freshValidator = new AnmeldungValidator();
        $this->assertSame([], $freshValidator->getErrors());
    }

    public function testGetErrorsReturnsAllErrors(): void
    {
        $this->validator->validate([]);
        $errors = $this->validator->getErrors();

        $this->assertIsArray($errors);
        $this->assertGreaterThanOrEqual(3, count($errors)); // formular, name, email
    }

    public function testGetFirstErrorReturnsNullWhenNoErrors(): void
    {
        $this->assertNull($this->validator->getFirstError());
    }

    public function testGetFirstErrorReturnsStringWhenErrorsExist(): void
    {
        $this->validator->validate([]);
        $first = $this->validator->getFirstError();

        $this->assertIsString($first);
        $this->assertNotEmpty($first);
    }
}
