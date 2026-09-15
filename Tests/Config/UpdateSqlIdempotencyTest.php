<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * MYO-378: Config/update/*.sql must survive being replayed against a schema
 * where they already ran, because CommerceAgents::update() no longer trusts
 * $currentVersion to skip files it believes are already applied (that value
 * comes from Thelia\Module\ModuleManagement, which can record a version
 * ahead of what the schema actually has -- see the module README, "Schema
 * migrations" section).
 */
class UpdateSqlIdempotencyTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function updateFiles(): array
    {
        $files = glob(__DIR__.'/../../Config/update/*.sql');
        self::assertNotFalse($files);
        self::assertNotEmpty($files, 'update file discovery must not silently find nothing');

        return $files;
    }

    /**
     * Strips `-- ...` line comments so an explanatory comment mentioning a
     * statement keyword in prose (e.g. "no IF NOT EXISTS for foreign keys,
     * unlike ADD COLUMN/ADD INDEX above") cannot be mistaken for the SQL
     * itself.
     */
    private static function withoutLineComments(string $sql): string
    {
        return (string) preg_replace('/--.*$/m', '', $sql);
    }

    public function testCreateTableStatementsAreGuarded(): void
    {
        foreach (self::updateFiles() as $file) {
            $sql = self::withoutLineComments((string) file_get_contents($file));

            preg_match_all('/CREATE TABLE\s+(?!IF NOT EXISTS)/i', $sql, $matches);

            self::assertSame(
                [],
                $matches[0],
                basename($file).' has a CREATE TABLE without IF NOT EXISTS: replaying it against an already-migrated schema would fail with "table already exists" (MYO-378).',
            );
        }
    }

    public function testAddColumnStatementsAreGuarded(): void
    {
        foreach (self::updateFiles() as $file) {
            $sql = self::withoutLineComments((string) file_get_contents($file));

            preg_match_all('/ADD COLUMN\s+(?!IF NOT EXISTS)/i', $sql, $matches);

            self::assertSame(
                [],
                $matches[0],
                basename($file).' has an ADD COLUMN without IF NOT EXISTS: replaying it against an already-migrated schema would fail with "duplicate column" (MYO-378).',
            );
        }
    }

    public function testAddIndexStatementsAreGuarded(): void
    {
        foreach (self::updateFiles() as $file) {
            $sql = self::withoutLineComments((string) file_get_contents($file));

            preg_match_all('/ADD INDEX\s+(?!IF NOT EXISTS)/i', $sql, $matches);

            self::assertSame(
                [],
                $matches[0],
                basename($file).' has an ADD INDEX without IF NOT EXISTS: replaying it against an already-migrated schema would fail with "duplicate key name" (MYO-378).',
            );
        }
    }

    /**
     * ADD CONSTRAINT (foreign keys) has no IF NOT EXISTS form in MariaDB, so a
     * bare `ADD CONSTRAINT` outside of a CREATE TABLE (which is atomically
     * guarded by IF NOT EXISTS already) must instead be wrapped in a guarded
     * DELIMITER $$ ... procedure that checks information_schema first -- see
     * 0.3.2.sql.
     */
    public function testStandaloneAddConstraintStatementsAreGuardedByAProcedure(): void
    {
        foreach (self::updateFiles() as $file) {
            $sql = self::withoutLineComments((string) file_get_contents($file));

            if (!preg_match('/^\s*ALTER TABLE.*\bADD CONSTRAINT\b/ims', $sql)) {
                continue;
            }

            self::assertMatchesRegularExpression(
                '/DELIMITER \$\$.*information_schema.*DELIMITER ;/is',
                $sql,
                basename($file).' has a standalone ALTER TABLE ... ADD CONSTRAINT: replaying it against an already-migrated schema would fail with "duplicate key name" since MariaDB has no ADD CONSTRAINT IF NOT EXISTS (MYO-378) -- guard it with an information_schema check inside a DELIMITER $$ procedure, as 0.3.2.sql does.',
            );
        }
    }
}
