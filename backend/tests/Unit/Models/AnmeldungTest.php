<?php
declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Anmeldung;
use App\Models\CompleteAnmeldung;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class AnmeldungTest extends TestCase
{
    // =========================================================================
    // Anmeldung::fromArray
    // =========================================================================

    private function baseRow(): array
    {
        return [
            'id' => '1',
            'formular' => 'bs',
            'formular_version' => '1.2',
            'name' => 'Max Mustermann',
            'email' => 'max@example.com',
            'status' => 'neu',
            'data' => '{"feld1":"wert1"}',
            'created_at' => '2026-01-15 10:00:00',
            'updated_at' => null,
            'deleted' => '0',
            'deleted_at' => null,
        ];
    }

    public function testFromArrayCreatesInstance(): void
    {
        $anmeldung = Anmeldung::fromArray($this->baseRow());

        $this->assertInstanceOf(Anmeldung::class, $anmeldung);
        $this->assertSame(1, $anmeldung->id);
        $this->assertSame('bs', $anmeldung->formular);
        $this->assertSame('1.2', $anmeldung->formularVersion);
        $this->assertSame('Max Mustermann', $anmeldung->name);
        $this->assertSame('max@example.com', $anmeldung->email);
        $this->assertSame('neu', $anmeldung->status);
        $this->assertSame(['feld1' => 'wert1'], $anmeldung->data);
        $this->assertFalse($anmeldung->deleted);
        $this->assertNull($anmeldung->deletedAt);
        $this->assertNull($anmeldung->updatedAt);
    }

    public function testFromArrayHandlesNullableFields(): void
    {
        $row = $this->baseRow();
        $row['formular_version'] = null;
        $row['name'] = null;
        $row['email'] = null;
        $row['data'] = null;

        $anmeldung = Anmeldung::fromArray($row);

        $this->assertNull($anmeldung->formularVersion);
        $this->assertNull($anmeldung->name);
        $this->assertNull($anmeldung->email);
        $this->assertNull($anmeldung->data);
    }

    public function testFromArrayParsesJsonData(): void
    {
        $row = $this->baseRow();
        $row['data'] = '{"name":"Erika","age":25,"hobbies":["lesen","sport"]}';

        $anmeldung = Anmeldung::fromArray($row);

        $this->assertIsArray($anmeldung->data);
        $this->assertSame('Erika', $anmeldung->data['name']);
        $this->assertSame(25, $anmeldung->data['age']);
        $this->assertSame(['lesen', 'sport'], $anmeldung->data['hobbies']);
    }

    public function testFromArrayParsesCreatedAt(): void
    {
        $anmeldung = Anmeldung::fromArray($this->baseRow());

        $this->assertInstanceOf(DateTimeImmutable::class, $anmeldung->createdAt);
        $this->assertSame('2026-01-15', $anmeldung->createdAt->format('Y-m-d'));
    }

    public function testFromArrayParsesUpdatedAt(): void
    {
        $row = $this->baseRow();
        $row['updated_at'] = '2026-02-01 09:30:00';

        $anmeldung = Anmeldung::fromArray($row);

        $this->assertInstanceOf(DateTimeImmutable::class, $anmeldung->updatedAt);
        $this->assertSame('2026-02-01', $anmeldung->updatedAt->format('Y-m-d'));
    }

    public function testFromArrayParsesDeletedAt(): void
    {
        $row = $this->baseRow();
        $row['deleted'] = '1';
        $row['deleted_at'] = '2026-03-01 12:00:00';

        $anmeldung = Anmeldung::fromArray($row);

        $this->assertTrue($anmeldung->deleted);
        $this->assertInstanceOf(DateTimeImmutable::class, $anmeldung->deletedAt);
    }

    public function testFromArrayDefaultsStatusToNeu(): void
    {
        $row = $this->baseRow();
        unset($row['status']);

        $anmeldung = Anmeldung::fromArray($row);

        $this->assertSame('neu', $anmeldung->status);
    }

    public function testFromArrayDefaultsDeletedToFalse(): void
    {
        $row = $this->baseRow();
        unset($row['deleted']);

        $anmeldung = Anmeldung::fromArray($row);

        $this->assertFalse($anmeldung->deleted);
    }

    // =========================================================================
    // Anmeldung::isComplete
    // =========================================================================

    private function makeAnmeldung(
        ?string $name = 'Max Mustermann',
        ?string $email = 'max@example.com',
    ): Anmeldung {
        return new Anmeldung(
            id: 1,
            formular: 'bs',
            formularVersion: null,
            name: $name,
            email: $email,
            status: 'neu',
            data: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: null,
            deleted: false,
            deletedAt: null,
        );
    }

    public function testIsCompleteReturnsTrueWhenNameAndEmailPresent(): void
    {
        $anmeldung = $this->makeAnmeldung('Max Mustermann', 'max@example.com');
        $this->assertTrue($anmeldung->isComplete());
    }

    public function testIsCompleteReturnsFalseWhenNameIsNull(): void
    {
        $anmeldung = $this->makeAnmeldung(null, 'max@example.com');
        $this->assertFalse($anmeldung->isComplete());
    }

    public function testIsCompleteReturnsFalseWhenEmailIsNull(): void
    {
        $anmeldung = $this->makeAnmeldung('Max Mustermann', null);
        $this->assertFalse($anmeldung->isComplete());
    }

    public function testIsCompleteReturnsFalseForInvalidEmail(): void
    {
        $anmeldung = $this->makeAnmeldung('Max Mustermann', 'not-an-email');
        $this->assertFalse($anmeldung->isComplete());
    }

    // =========================================================================
    // Anmeldung::toComplete
    // =========================================================================

    public function testToCompleteSucceedsForCompleteAnmeldung(): void
    {
        $anmeldung = $this->makeAnmeldung('Max Mustermann', 'max@example.com');
        $complete = $anmeldung->toComplete();

        $this->assertInstanceOf(CompleteAnmeldung::class, $complete);
        $this->assertSame('Max Mustermann', $complete->name);
        $this->assertSame('max@example.com', $complete->email);
        $this->assertSame(1, $complete->id);
        $this->assertSame('bs', $complete->formular);
    }

    public function testToCompleteThrowsForIncompleteAnmeldung(): void
    {
        $anmeldung = $this->makeAnmeldung(null, 'max@example.com');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot convert incomplete');

        $anmeldung->toComplete();
    }

    public function testToCompleteThrowsForInvalidEmail(): void
    {
        $anmeldung = $this->makeAnmeldung('Max', 'not-valid');

        $this->expectException(\InvalidArgumentException::class);
        $anmeldung->toComplete();
    }

    // =========================================================================
    // Safe getter methods
    // =========================================================================

    public function testGetDisplayNameReturnsNameWhenPresent(): void
    {
        $anmeldung = $this->makeAnmeldung('Erika Muster', 'erika@example.com');
        $this->assertSame('Erika Muster', $anmeldung->getDisplayName());
    }

    public function testGetDisplayNameReturnsUnbekanntWhenNull(): void
    {
        $anmeldung = $this->makeAnmeldung(null, null);
        $this->assertSame('Unbekannt', $anmeldung->getDisplayName());
    }

    public function testGetDisplayEmailReturnsEmailWhenPresent(): void
    {
        $anmeldung = $this->makeAnmeldung('Max', 'max@example.com');
        $this->assertSame('max@example.com', $anmeldung->getDisplayEmail());
    }

    public function testGetDisplayEmailReturnsPlaceholderWhenNull(): void
    {
        $anmeldung = $this->makeAnmeldung('Max', null);
        $this->assertSame('-', $anmeldung->getDisplayEmail());
    }

    public function testGetDisplayVersionReturnsVersionWhenPresent(): void
    {
        $anmeldung = new Anmeldung(
            id: 1,
            formular: 'bs',
            formularVersion: '2.5',
            name: 'Max',
            email: 'max@example.com',
            status: 'neu',
            data: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: null,
            deleted: false,
            deletedAt: null,
        );
        $this->assertSame('2.5', $anmeldung->getDisplayVersion());
    }

    public function testGetDisplayVersionReturnsDefaultWhenNull(): void
    {
        $anmeldung = new Anmeldung(
            id: 1,
            formular: 'bs',
            formularVersion: null,
            name: 'Max',
            email: 'max@example.com',
            status: 'neu',
            data: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: null,
            deleted: false,
            deletedAt: null,
        );
        $this->assertSame('v1.0', $anmeldung->getDisplayVersion());
    }

    // =========================================================================
    // CompleteAnmeldung - constructor validation
    // =========================================================================

    public function testCompleteAnmeldungConstructsWithValidData(): void
    {
        $complete = new CompleteAnmeldung(
            id: 10,
            formular: 'bk',
            formularVersion: '1.0',
            name: 'Erika Muster',
            email: 'erika@example.com',
            status: 'neu',
            data: ['foo' => 'bar'],
            createdAt: new DateTimeImmutable(),
            updatedAt: null,
            deleted: false,
            deletedAt: null,
        );

        $this->assertSame(10, $complete->id);
        $this->assertSame('Erika Muster', $complete->name);
        $this->assertSame('erika@example.com', $complete->email);
    }

    public function testCompleteAnmeldungThrowsForInvalidEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email');

        new CompleteAnmeldung(
            id: 1,
            formular: 'bs',
            formularVersion: null,
            name: 'Max',
            email: 'not-an-email',
            status: 'neu',
            data: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: null,
            deleted: false,
            deletedAt: null,
        );
    }

    public function testCompleteAnmeldungThrowsForEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Name cannot be empty');

        new CompleteAnmeldung(
            id: 1,
            formular: 'bs',
            formularVersion: null,
            name: '   ',  // only whitespace
            email: 'max@example.com',
            status: 'neu',
            data: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: null,
            deleted: false,
            deletedAt: null,
        );
    }

    public function testCompleteAnmeldungThrowsForEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Name cannot be empty');

        new CompleteAnmeldung(
            id: 1,
            formular: 'bs',
            formularVersion: null,
            name: '',
            email: 'max@example.com',
            status: 'neu',
            data: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: null,
            deleted: false,
            deletedAt: null,
        );
    }
}
