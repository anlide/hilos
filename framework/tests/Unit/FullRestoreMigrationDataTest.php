<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\FullRestore\MigrationOperationKind;
use Hilos\Backup\FullRestore\MigrationOperationReader;
use Hilos\Backup\FullRestore\TableName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Complete direct requirements, separate from runtime execution and current successful history. */
final class FullRestoreMigrationDataTest extends TestCase
{
    /**
     * @param string $sql One execution unit
     * @param list<array{string, string}> $expected Physical schema/table requirements in occurrence order
     */
    #[DataProvider('ordinaryStatements')]
    public function testDirectDataRequirements(string $sql, array $expected): void
    {
        $operations = MigrationOperationReader::read($sql, 'primary');
        $this->assertCount(1, $operations);
        $this->assertSame(MigrationOperationKind::DATA, $operations[0]->kind);
        $this->assertFalse($operations[0]->isProvenanceSource());
        $this->assertSame($expected, array_map(static fn (TableName $table): array => [$table->database, $table->table],
            $operations[0]->references));
    }

    /** @return iterable<string, array{string, array}> Supported ordinary DML and scalar expressions */
    public static function ordinaryStatements(): iterable
    {
        yield 'values' => ["INSERT IGNORE INTO notes(id,label) VALUES(1,'FROM fake'),(2,'JOIN fake')", [['primary', 'notes']]];
        yield 'replace' => ['REPLACE INTO `journal`.`same_name`(id) VALUES(1)', [['journal', 'same_name']]];
        yield 'select insert' => ['INSERT INTO notes(id) SELECT n.id FROM `journal`.`same_name` AS n',
            [['primary', 'notes'], ['journal', 'same_name']]];
        yield 'upsert' => ['INSERT INTO notes(id) VALUES(1) ON DUPLICATE KEY UPDATE id=VALUES(id)', [['primary', 'notes']]];
        yield 'insert set' => ['INSERT INTO notes SET id=1', [['primary', 'notes']]];
        yield 'update' => ["UPDATE notes SET label=COALESCE(label,'FROM fake') WHERE id IN (1,2)", [['primary', 'notes']]];
        yield 'update joins' => ['UPDATE notes n INNER JOIN `journal`.`same_name` j USING(id) SET n.id=j.id WHERE n.id=1',
            [['primary', 'notes'], ['journal', 'same_name']]];
        yield 'delete aliases' => ['DELETE n,j FROM notes n JOIN `journal`.`same_name` j ON n.id=j.id WHERE n.id=1',
            [['primary', 'notes'], ['journal', 'same_name']]];
        yield 'ordinary delete' => ["DELETE FROM notes WHERE `key` IN ('one','two')", [['primary', 'notes']]];
        yield 'select aliases' => ["SELECT 'FROM fake' AS later, n.`FROM` FROM notes n /* JOIN fake */", [['primary', 'notes']]];
        yield 'qualified quoted' => ['SELECT a.id FROM `schema.with.dot`.`table``name` a', [['schema.with.dot', 'table`name']]];
        yield 'comma sources' => ['SELECT a.id FROM notes a, `journal`.`same_name` b WHERE a.id=b.id ORDER BY a.id LIMIT 1',
            [['primary', 'notes'], ['journal', 'same_name']]];
        yield 'scalar select' => ["SELECT 'FROM later', COALESCE(NULL,1)", []];
        yield 'scalar do' => ['DO 1', []];
    }

    /** @param string $sql Unproven or dynamic row access */
    #[DataProvider('opaqueStatements')]
    public function testOpaqueDataCannotBecomeAnEmptyEffect(string $sql): void
    {
        $operations = MigrationOperationReader::read($sql, 'primary');
        $this->assertCount(1, $operations);
        $this->assertSame(MigrationOperationKind::UNKNOWN, $operations[0]->kind);
        $this->assertFalse($operations[0]->isProvenanceSource());
    }

    /** @return iterable<string, array{string}> Refusals deliberately outside the ordinary grammar */
    public static function opaqueStatements(): iterable
    {
        foreach ([
            'UPDATE notes SET id=(SELECT id FROM later)',
            'DELETE FROM notes WHERE EXISTS(SELECT 1 FROM later)',
            'INSERT INTO notes SELECT id FROM (SELECT id FROM later) AS hidden',
            'SELECT COALESCE((SELECT id FROM later),0)',
            'SELECT * FROM JSON_TABLE(notes, path) AS j',
            'SELECT 1 UNION SELECT id FROM later',
            'WITH c AS (SELECT * FROM later) SELECT * FROM c',
            "SELECT 1 INTO OUTFILE 'output'",
            'SET @sql=(SELECT id FROM later)',
            'DO (SELECT id FROM later)',
            'UPDATE notes SET id=1; DELETE FROM later',
            "PREPARE s FROM 'DELETE FROM later'",
            'EXECUTE s',
            'SELECT NEXT VALUE FOR later',
            'SELECT NEXTVAL(later)',
            'SELECT SETVAL(later,1)',
            'DELETE missing FROM notes n',
            'SELECT id FROM notes n JOIN later n ON n.id=n.id',
            'SELECT id FROM notes PARTITION(p)',
            'UPDATE notes SET id=1 RETURNING id',
        ] as $sql) {
            yield $sql => [$sql];
        }
    }

    /** Trigger names never become base-table CREATE or DML provenance. */
    public function testQualifiedConditionalTriggerDropHasAnExplicitEffect(): void
    {
        $operation = MigrationOperationReader::read('DROP TRIGGER IF EXISTS `journal`.`t``name`', 'primary')[0];
        $this->assertSame(MigrationOperationKind::DROP_TRIGGER, $operation->kind);
        $this->assertSame('journal', $operation->trigger->database);
        $this->assertSame('t`name', $operation->trigger->table);
        $this->assertTrue($operation->ifExists);
        $this->assertNull($operation->table);
        $this->assertFalse($operation->isProvenanceSource());
        foreach (['DROP TRIGGER IF EXISTS one, two', 'DROP TRIGGER IF EXISTS one; DROP TABLE later', 'DROP TRIGGER @name'] as $sql) {
            $this->assertSame(MigrationOperationKind::UNKNOWN, MigrationOperationReader::read($sql, 'primary')[0]->kind);
        }
    }
}
