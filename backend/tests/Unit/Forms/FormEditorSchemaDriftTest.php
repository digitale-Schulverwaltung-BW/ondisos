<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use PHPUnit\Framework\TestCase;

/**
 * The form-editor tables are defined in three places (schema.sql for new installs, the migration file,
 * migrate.php for upgrades). This keeps them from drifting apart.
 */
class FormEditorSchemaDriftTest extends TestCase
{
    private const TABLES = ['form_resources', 'form_drafts', 'form_revisions'];

    /** @return array<string,string> table => normalized definition */
    private function definitions(string $sql): array
    {
        $out = [];
        foreach (self::TABLES as $table) {
            $this->assertSame(1, preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \((.*?)\) ENGINE=InnoDB/s', $sql, $m), "{$table} missing");
            $out[$table] = strtolower(preg_replace('/\s+/', ' ', trim($m[1])));
        }
        return $out;
    }

    public function testThreeDefinitionsAreIdentical(): void
    {
        $root = __DIR__ . '/../../../..';
        $schema    = $this->definitions((string)file_get_contents($root . '/database/schema.sql'));
        $migration = $this->definitions((string)file_get_contents($root . '/database/migrations/add_form_editor_tables.sql'));
        $migrate   = $this->definitions((string)file_get_contents($root . '/backend/migrate.php'));

        $this->assertSame($schema, $migration, 'schema.sql vs migrations/add_form_editor_tables.sql');
        $this->assertSame($schema, $migrate, 'schema.sql vs backend/migrate.php');
    }
}
