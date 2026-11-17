<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\FullRestore\SchemaMetadata;
use Hilos\Backup\FullRestore\StructureDefaults;
use Hilos\Backup\FullRestoreSchemaSnapshot;
use Hilos\Backup\FullRestore\LiveObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class FullRestoreSchemaSnapshotTest extends TestCase
{
    public function testAssertUpSessionValuesAdmitsMatchingValues(): void
    {
        $reliance = 'Connection 0 is imported but not migrated';

        $schemaOn = $this->metadata(checks: 'ON', sqlMode: 'STRICT_TRANS_TABLES', engine: 'InnoDB');
        $schemaOn->assertUpSessionValues('ON', 'STRICT_TRANS_TABLES', 'InnoDB', $reliance);

        $schemaNull = $this->metadata(checks: null, sqlMode: 'STRICT_TRANS_TABLES', engine: 'InnoDB');
        $schemaNull->assertUpSessionValues(null, 'STRICT_TRANS_TABLES', 'InnoDB', $reliance);

        $schemaEmptyMode = $this->metadata(checks: 'ON', sqlMode: '', engine: 'InnoDB');
        $schemaEmptyMode->assertUpSessionValues('ON', '', 'InnoDB', $reliance);

        $this->expectNotToPerformAssertions();
    }

    public function testAssertUpSessionValuesRejectsCheckConstraintChecksChange(): void
    {
        $reliance = 'Connection 0 is imported but not migrated';
        $schema = $this->metadata(checks: 'ON');

        try {
            $schema->assertUpSessionValues('OFF', 'STRICT_TRANS_TABLES', 'InnoDB', $reliance);
            $this->fail('Expected RestoreFailedException was not thrown');
        } catch (RestoreFailedException $e) {
            $this->assertStringContainsString($reliance, $e->getMessage());
            $this->assertStringContainsString('check_constraint_checks was ON, now OFF', $e->getMessage());
            $this->assertTrue($e->databaseTouched());
        }
    }

    public function testAssertUpSessionValuesRejectsSqlModeChange(): void
    {
        $reliance = 'Connection 0 is imported but not migrated';
        $schema = $this->metadata(sqlMode: 'STRICT_TRANS_TABLES');

        try {
            $schema->assertUpSessionValues('ON', '', 'InnoDB', $reliance);
            $this->fail('Expected RestoreFailedException was not thrown');
        } catch (RestoreFailedException $e) {
            $this->assertStringContainsString($reliance, $e->getMessage());
            $this->assertStringContainsString("sql_mode was STRICT_TRANS_TABLES, now ''", $e->getMessage());
        }

        $schemaEmpty = $this->metadata(sqlMode: '');
        try {
            $schemaEmpty->assertUpSessionValues('ON', 'STRICT_TRANS_TABLES', 'InnoDB', $reliance);
            $this->fail('Expected RestoreFailedException was not thrown');
        } catch (RestoreFailedException $e) {
            $this->assertStringContainsString("sql_mode was '', now STRICT_TRANS_TABLES", $e->getMessage());
        }
    }

    public function testAssertUpSessionValuesRejectsDefaultStorageEngineChange(): void
    {
        $reliance = 'Connection 0 is imported but not migrated';
        $schema = $this->metadata(engine: 'InnoDB');

        try {
            $schema->assertUpSessionValues('ON', 'STRICT_TRANS_TABLES', 'MyISAM', $reliance);
            $this->fail('Expected RestoreFailedException was not thrown');
        } catch (RestoreFailedException $e) {
            $this->assertStringContainsString($reliance, $e->getMessage());
            $this->assertStringContainsString('default_storage_engine was InnoDB, now MyISAM', $e->getMessage());
        }
    }

    public function testAssertUpSessionValuesRejectsTransitionToAndFromNull(): void
    {
        $reliance = 'Connection 0 is imported but not migrated';

        $schemaOn = $this->metadata(checks: 'ON');
        try {
            $schemaOn->assertUpSessionValues(null, 'STRICT_TRANS_TABLES', 'InnoDB', $reliance);
            $this->fail('Expected RestoreFailedException was not thrown');
        } catch (RestoreFailedException $e) {
            $this->assertStringContainsString('check_constraint_checks was ON, now unknown', $e->getMessage());
        }

        $schemaNull = $this->metadata(checks: null);
        try {
            $schemaNull->assertUpSessionValues('ON', 'STRICT_TRANS_TABLES', 'InnoDB', $reliance);
            $this->fail('Expected RestoreFailedException was not thrown');
        } catch (RestoreFailedException $e) {
            $this->assertStringContainsString('check_constraint_checks was unknown, now ON', $e->getMessage());
        }
    }

    public function testAssertUpSessionValuesEnforcesExactComparisonWithoutNormalization(): void
    {
        $reliance = 'Connection 0 is imported and UP has run';

        $schemaModes = $this->metadata(sqlMode: 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION');
        try {
            $schemaModes->assertUpSessionValues(
                'ON',
                'NO_ENGINE_SUBSTITUTION,STRICT_TRANS_TABLES',
                'InnoDB',
                $reliance,
            );
            $this->fail('Expected RestoreFailedException was not thrown');
        } catch (RestoreFailedException $e) {
            $this->assertStringContainsString(
                'sql_mode was STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION, now NO_ENGINE_SUBSTITUTION,STRICT_TRANS_TABLES',
                $e->getMessage(),
            );
        }

        $schemaEngine = $this->metadata(engine: 'InnoDB');
        try {
            $schemaEngine->assertUpSessionValues('ON', 'STRICT_TRANS_TABLES', 'innodb', $reliance);
            $this->fail('Expected RestoreFailedException was not thrown');
        } catch (RestoreFailedException $e) {
            $this->assertStringContainsString('default_storage_engine was InnoDB, now innodb', $e->getMessage());
        }
    }

    public function testAssertUpSessionValuesDistinguishesRelianceBeforeAndAfterUp(): void
    {
        $beforeUpReliance = 'Connection 0 is imported but not migrated';
        $afterUpReliance = 'Connection 0 is imported and UP has run';
        $schema = $this->metadata(checks: 'ON');

        try {
            $schema->assertUpSessionValues('OFF', 'STRICT_TRANS_TABLES', 'InnoDB', $beforeUpReliance);
            $this->fail('Expected RestoreFailedException was not thrown');
        } catch (RestoreFailedException $e) {
            $this->assertStringContainsString($beforeUpReliance, $e->getMessage());
            $this->assertStringContainsString('not migrated', $e->getMessage());
        }

        try {
            $schema->assertUpSessionValues('OFF', 'STRICT_TRANS_TABLES', 'InnoDB', $afterUpReliance);
            $this->fail('Expected RestoreFailedException was not thrown');
        } catch (RestoreFailedException $e) {
            $this->assertStringContainsString($afterUpReliance, $e->getMessage());
            $this->assertStringNotContainsString('not migrated', $e->getMessage());
        }
    }

    public function testAssertUpSessionValuesFollowsDiagnosticOrder(): void
    {
        $reliance = 'Connection 0 is imported and UP has run';
        $schema = $this->metadata(checks: 'ON', sqlMode: 'STRICT_TRANS_TABLES', engine: 'InnoDB');

        try {
            $schema->assertUpSessionValues('OFF', 'ANSI', 'MyISAM', $reliance);
            $this->fail('Expected RestoreFailedException was not thrown');
        } catch (RestoreFailedException $e) {
            $this->assertStringContainsString('check_constraint_checks was ON, now OFF', $e->getMessage());
        }

        try {
            $schema->assertUpSessionValues('ON', 'ANSI', 'MyISAM', $reliance);
            $this->fail('Expected RestoreFailedException was not thrown');
        } catch (RestoreFailedException $e) {
            $this->assertStringContainsString('sql_mode was STRICT_TRANS_TABLES, now ANSI', $e->getMessage());
        }
    }

    /**
     * @param string $before Raw declaration prepared for restore
     * @param string $after Declaration read at recheck
     * @param bool $unchanged Whether only the volatile table counter changed
     */
    #[DataProvider('tableCounterChanges')]
    public function testComparisonOmitsOnlyTheTableCounter(string $before, string $after, bool $unchanged): void
    {
        $snapshots = [];
        foreach ([$before, $after] as $sql) {
            $snapshots[] = new FullRestoreSchemaSnapshot([new SchemaMetadata(
                'server', ['sample' => new LiveObject('BASE TABLE', $sql, 'Dynamic')], [], [], [],
                new StructureDefaults('InnoDB', 'utf8mb4', 'utf8mb4_unicode_ci'),
            )], []);
        }
        $comparison = new ReflectionMethod(FullRestoreSchemaSnapshot::class, 'comparison');
        $this->assertSame($unchanged, $comparison->invoke($snapshots[0]) == $comparison->invoke($snapshots[1]));
        $this->assertSame($before, $snapshots[0]->schemas[0]->objects['sample']->createSql);
        $this->assertSame($after, $snapshots[1]->schemas[0]->objects['sample']->createSql);
    }

    /** @return iterable<string, array{string, string, bool}> Counter drift and independent definition changes */
    public static function tableCounterChanges(): iterable
    {
        $table = 'CREATE TABLE `sample` (`id` int NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB';
        yield 'counter only' => [$table . ' AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4',
            $table . ' AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4', true];
        yield 'counter appears' => [$table . ' DEFAULT CHARSET=utf8mb4',
            $table . ' AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4', true];
        yield 'column auto increment changes' => [$table . ' AUTO_INCREMENT=2',
            str_replace(' NOT NULL AUTO_INCREMENT', ' NOT NULL', $table) . ' AUTO_INCREMENT=19', false];
        yield 'default containing counter text changes' => [
            "CREATE TABLE `sample` (`note` varchar(60) DEFAULT 'AUTO_INCREMENT=2') ENGINE=InnoDB AUTO_INCREMENT=2",
            "CREATE TABLE `sample` (`note` varchar(60) DEFAULT 'AUTO_INCREMENT=19') ENGINE=InnoDB AUTO_INCREMENT=19", false];
        yield 'comment containing counter text changes' => [
            $table . " AUTO_INCREMENT=2 COMMENT='AUTO_INCREMENT=2'",
            $table . " AUTO_INCREMENT=19 COMMENT='AUTO_INCREMENT=19'", false];
        yield 'partition definition changes' => [
            $table . ' AUTO_INCREMENT=2 PARTITION BY RANGE (`id`) (PARTITION p0 VALUES LESS THAN (100))',
            $table . ' AUTO_INCREMENT=19 PARTITION BY RANGE (`id`) (PARTITION p0 VALUES LESS THAN (200))', false];
    }

    /**
     * @param string $database Actual selected schema
     * @param string $grant Direct SHOW GRANTS row
     * @param bool $expected Whether the row proves schema-wide TRIGGER
     */
    #[DataProvider('triggerGrants')]
    public function testOnlyDirectGlobalOrExactSchemaGrantsProveTriggerVisibility(string $database, string $grant, bool $expected): void
    {
        $this->assertSame($expected, new ReflectionMethod(FullRestoreSchemaSnapshot::class, 'hasSchemaPrivilege')
            ->invoke(null, [$grant], $database, 'TRIGGER'));
    }

    /** @return iterable<string, array{string, string, bool}> Literal and unsupported SHOW GRANTS spellings */
    public static function triggerGrants(): iterable
    {
        yield 'global trigger' => ['target', 'GRANT TRIGGER ON *.* TO `app`@`%`', true];
        yield 'global all' => ['target', 'GRANT ALL PRIVILEGES ON *.* TO `app`@`%`', true];
        yield 'schema all' => ['target', 'GRANT ALL PRIVILEGES ON `target`.* TO `app`@`%`', true];
        yield 'schema trigger' => ['target', 'GRANT SELECT, TRIGGER ON `target`.* TO `app`@`%`', true];
        yield 'unquoted' => ['target', 'GRANT TRIGGER ON target.* TO `app`@`%`', true];
        yield 'quoted backtick' => ['tar`get', 'GRANT TRIGGER ON `tar``get`.* TO `app`@`%`', true];
        yield 'escaped underscore' => ['tar_get', 'GRANT TRIGGER ON `tar\_get`.* TO `app`@`%`', true];
        yield 'escaped percent' => ['tar%get', 'GRANT TRIGGER ON `tar\%get`.* TO `app`@`%`', true];
        yield 'escaped backslash' => ['tar\get', 'GRANT TRIGGER ON `tar\\\\get`.* TO `app`@`%`', true];
        yield 'wrong schema' => ['target', 'GRANT TRIGGER ON `outside`.* TO `app`@`%`', false];
        yield 'table only' => ['target', 'GRANT TRIGGER ON `target`.`carried` TO `app`@`%`', false];
        yield 'select only' => ['target', 'GRANT SELECT ON `target`.* TO `app`@`%`', false];
        yield 'role' => ['target', 'GRANT `TRIGGER` TO `app`@`%`', false];
        yield 'wildcard only' => ['target', 'GRANT TRIGGER ON `tar%`.* TO `app`@`%`', false];
        yield 'underscore pattern' => ['tar_get', 'GRANT TRIGGER ON `tar_get`.* TO `app`@`%`', false];
        yield 'unparsed syntax' => ['target', 'GRANT TRIGGER ON DATABASE `target` TO `app`@`%`', false];
    }

    private function metadata(
        ?string $checks = 'ON',
        string $sqlMode = 'STRICT_TRANS_TABLES',
        string $engine = 'InnoDB',
    ): SchemaMetadata {
        return new SchemaMetadata(
            server: '["host",3306,"app"]',
            objects: [],
            defaults: [],
            columns: [],
            indexes: [],
            structureDefaults: new StructureDefaults(
                engine: $engine,
                charset: 'utf8mb4',
                collation: 'utf8mb4_general_ci',
                sqlMode: $sqlMode,
            ),
            checkConstraintChecks: $checks,
        );
    }
}
