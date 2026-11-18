<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\ChangeLog;

use Hilos\Backup\Anonymization\LiveTableSchema;
use Hilos\Database\ChangeLog\JournalTriggerColumnTypes;
use Hilos\Database\ChangeLog\JournalTriggerFile;
use Hilos\Database\ChangeLog\JournalTriggerRenderer;
use Hilos\Database\Exception\UnplacedJournalColumnException;
use Hilos\Database\Schema\JournalColumnMode;
use Hilos\Database\Schema\JournalColumnPlacement;
use PHPUnit\Framework\TestCase;

/** The renderer's output is the source file contract later checked at daemon startup. */
final class JournalTriggerRendererTest extends TestCase
{
    public function testFilesUseStableNamesHeadersAndOneStatementPerEvent(): void
    {
        $files = self::render();
        $this->assertSame([
            'hilos_cl_sample_after_insert', 'hilos_cl_sample_after_update', 'hilos_cl_sample_after_delete',
        ], array_map(static fn($file) => $file->name, $files));
        foreach ($files as $file) {
            $this->assertStringStartsWith('-- valid from migration #85' . "\nCREATE TRIGGER", $file->content());
            $this->assertStringContainsString('{{change_log_database}}.`hilos_change_log`', $file->body);
            $this->assertStringNotContainsString('DELIMITER', $file->body);
            $this->assertSame(1, substr_count($file->body, 'CREATE TRIGGER'));
            $this->assertStringEndsWith("END;\n", $file->content());
        }
    }

    /** Every mutation branch keeps both dictionary lookups fully qualified. */
    public function testDictionaryLookupsUseAliasedScalarAssignmentsInEveryBranch(): void
    {
        $files = self::render();
        [$keyChange, $valueChange] = explode('    ELSEIF ', $files[1]->body);
        foreach ([
            [$files[0]->body, 1, 0],
            [$keyChange, 2, 1],
            [$valueChange, 1, 1],
            [$files[2]->body, 1, 1],
        ] as [$body, $tableLookups, $fieldLookups]) {
            $this->assertSame($tableLookups, substr_count($body,
                'SET v_table_id = (SELECT dt_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_table`'
                . " AS dt_lookup WHERE dt_lookup.`name` = 'sample');"));
            foreach (['short', 'long_text', 'personal', 'binary_data'] as $column) {
                $this->assertSame($fieldLookups, substr_count($body,
                    'SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field`'
                    . " AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = '{$column}');"));
            }
            $this->assertSame($tableLookups, substr_count($body, 'SET v_table_id = '));
            $this->assertSame($fieldLookups * 4, substr_count($body, 'SET v_field_id = '));
            $this->assertStringNotContainsString('INTO v_table_id', $body);
            $this->assertStringNotContainsString('INTO v_field_id', $body);
        }
    }

    /** SQL keywords remain quoted identifiers and dictionary string literals. */
    public function testLookupNamesKeepIdentifierAndLiteralQuoting(): void
    {
        $schema = new LiveTableSchema('order', ['id' => false, 'select' => false],
            ['id' => 'int', 'select' => 'int'], [], ['id'], ['PRIMARY' => ['id']], []);
        $placements = self::placements(['id' => JournalColumnMode::RECORD_KEY, 'select' => JournalColumnMode::VALUE]);
        foreach (JournalTriggerRenderer::render($schema, $placements, new JournalTriggerColumnTypes([]), 85) as $file) {
            $this->assertStringContainsString(' ON `order`', $file->body);
            $this->assertStringContainsString("AS dt_lookup WHERE dt_lookup.`name` = 'order');", $file->body);
            if ($file->name !== 'hilos_cl_order_after_insert') {
                $this->assertStringContainsString('OLD.`select`', $file->body);
                $this->assertStringContainsString("AND df_lookup.`name` = 'select');", $file->body);
            }
        }
    }

    /** Names outside the identifier contract are refused before SQL is emitted. */
    public function testQuoteAndBacktickNamesRemainUnsupported(): void
    {
        foreach ([['bad\'table', 'value'], ['bad`table', 'value'], ['sample', 'bad\'field'], ['sample', 'bad`field']] as
            [$table, $column]) {
            $schema = new LiveTableSchema($table, ['id' => false, $column => false],
                ['id' => 'int', $column => 'int'], [], ['id'], ['PRIMARY' => ['id']], []);
            try {
                JournalTriggerRenderer::render($schema,
                    self::placements(['id' => JournalColumnMode::RECORD_KEY, $column => JournalColumnMode::VALUE]),
                    new JournalTriggerColumnTypes([]), 85);
                $this->fail('An unsupported SQL identifier was rendered');
            } catch (UnplacedJournalColumnException $exception) {
                $this->assertStringContainsString('invalid SQL identifier', $exception->getMessage());
            }
        }
    }

    public function testTriggerWritesSignedEpochMilliseconds(): void
    {
        foreach (self::render() as $file) {
            $this->assertStringContainsString('DECLARE v_created_at BIGINT;', $file->body);
            $this->assertStringContainsString(
                "SET v_created_at = TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', UTC_TIMESTAMP(6)) DIV 1000;",
                $file->body,
            );
        }
    }

    public function testUpdateComparesTextByBytesAndOmitsSecretAndNoise(): void
    {
        $update = self::render()[1]->body;
        $this->assertStringContainsString('NOT (BINARY OLD.`short` <=> BINARY NEW.`short`)', $update);
        $this->assertStringContainsString('NOT (BINARY OLD.`long_text` <=> BINARY NEW.`long_text`)', $update);
        $this->assertStringNotContainsString('`secret`', $update);
        $this->assertStringNotContainsString('`noise`', $update);
        $this->assertStringContainsString("'inline', 1, 1", $update);
        $this->assertStringContainsString("'long', 1, 1", $update);
        $this->assertStringContainsString("'fact', 1, 1", $update);
        $this->assertStringContainsString('`hilos_change_log_value`', $update);
        $this->assertStringContainsString('INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table`', $update);
        $this->assertStringNotContainsString('LAST_INSERT_ID(`id`)', $update);
    }

    public function testDeleteSnapshotsAllowedColumnsWithoutSecretValues(): void
    {
        $delete = self::render()[2]->body;
        $this->assertStringContainsString("'inline', 1, 0", $delete);
        $this->assertStringContainsString("'long', 1, 0", $delete);
        $this->assertStringContainsString("'fact', 1, 0, NULL, NULL", $delete);
        $this->assertStringNotContainsString('`secret`', $delete);
        $this->assertStringNotContainsString('`noise`', $delete);
    }

    public function testCompositeKeyChangeProducesDeleteAndCreate(): void
    {
        $update = self::render()[1]->body;
        $this->assertStringContainsString('JSON_ARRAY(OLD.`id`, OLD.`part`)', $update);
        $this->assertStringContainsString('JSON_ARRAY(NEW.`id`, NEW.`part`)', $update);
        $this->assertStringContainsString("'delete');", $update);
        $this->assertStringContainsString("'create');", $update);
        $this->assertStringNotContainsString("VALUES (v_table_id, 'id')", $update);
        $this->assertStringNotContainsString("VALUES (v_table_id, 'part')", $update);
    }

    public function testUnsupportedTypeAndMissingOctetBoundAreRefused(): void
    {
        $schema = self::schema(['id' => 'int', 'location' => 'geometry']);
        $placements = self::placements(['id' => JournalColumnMode::RECORD_KEY, 'location' => JournalColumnMode::VALUE]);
        $this->expectException(UnplacedJournalColumnException::class);
        $this->expectExceptionMessage('sample.location');
        JournalTriggerRenderer::render($schema, $placements, new JournalTriggerColumnTypes([]), 85);
    }

    public function testCharacterOctetBoundChoosesLongStorageAndMissingBoundIsRefused(): void
    {
        $schema = self::schema(['id' => 'int', 'wide' => 'varchar']);
        $placements = self::placements(['id' => JournalColumnMode::RECORD_KEY, 'wide' => JournalColumnMode::VALUE]);
        $files = JournalTriggerRenderer::render($schema, $placements,
            new JournalTriggerColumnTypes(['sample' => ['wide' => 70000]]), 85);
        $this->assertStringContainsString("'long', 1, 1", $files[1]->body);

        $this->expectException(UnplacedJournalColumnException::class);
        $this->expectExceptionMessage('sample.wide');
        JournalTriggerRenderer::render($schema, $placements, new JournalTriggerColumnTypes([]), 85);
    }

    public function testLongNameIsRefusedAndTombstonesPreserveNames(): void
    {
        $this->expectException(UnplacedJournalColumnException::class);
        JournalTriggerRenderer::tombstones(str_repeat('a', 43), 85);
    }

    public function testTombstoneHasOnlyDropStatement(): void
    {
        $files = JournalTriggerRenderer::tombstones('sample', 86);
        $this->assertCount(3, $files);
        $this->assertSame(
            "-- valid from migration #86\nDROP TRIGGER IF EXISTS `hilos_cl_sample_after_update`;\n",
            $files[1]->content(),
        );
        $this->assertTrue($files[1]->tombstone);
    }

    /**
     * @return list<JournalTriggerFile> Rendered fixtures
     */
    private static function render(): array
    {
        $schema = self::schema([
            'id' => 'int', 'part' => 'varchar', 'short' => 'varchar', 'long_text' => 'text',
            'personal' => 'varchar', 'binary_data' => 'blob', 'secret' => 'varchar', 'noise' => 'datetime',
        ], ['id', 'part']);
        $placements = self::placements([
            'id' => JournalColumnMode::RECORD_KEY,
            'part' => JournalColumnMode::RECORD_KEY,
            'short' => JournalColumnMode::VALUE,
            'long_text' => JournalColumnMode::VALUE,
            'personal' => JournalColumnMode::PERSONAL,
            'binary_data' => JournalColumnMode::BINARY,
            'secret' => JournalColumnMode::SECRET,
            'noise' => JournalColumnMode::NOISE,
        ]);
        $types = new JournalTriggerColumnTypes(['sample' => ['part' => 64, 'short' => 1024]]);
        return JournalTriggerRenderer::render($schema, $placements, $types, 85);
    }

    /**
     * @param array<string, string> $types Live SQL types
     * @param list<string> $key Primary key columns
     * @return LiveTableSchema Synthetic table
     */
    private static function schema(array $types, array $key = ['id']): LiveTableSchema
    {
        return new LiveTableSchema('sample', array_fill_keys(array_keys($types), false), $types, [],
            $key, ['PRIMARY' => $key], []);
    }

    /**
     * @param array<string, JournalColumnMode> $modes Placement modes
     * @return array<string, JournalColumnPlacement> Placements in live order
     */
    private static function placements(array $modes): array
    {
        $placements = [];
        foreach ($modes as $column => $mode) {
            $placements[$column] = new JournalColumnPlacement(
                $mode,
                $mode === JournalColumnMode::NOISE ? 'test fixture noise' : null,
            );
        }
        return $placements;
    }
}
