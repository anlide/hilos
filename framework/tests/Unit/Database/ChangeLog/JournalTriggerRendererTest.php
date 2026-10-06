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
