<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\ChangeLog;

use Hilos\Backup\Anonymization\LiveTableSchema;
use Hilos\Database\ChangeLog\JournalTriggerColumnTypes;
use Hilos\Database\ChangeLog\JournalTriggerFile;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerRenderer;
use Hilos\Database\DatabaseException;
use Hilos\Database\Schema\JournalColumnMode;
use Hilos\Database\Schema\JournalColumnPlacement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure file preflight: none of these cases has a database connection on which DDL could run. */
final class JournalTriggerInstallerTest extends TestCase
{
    private string $path;

    /** @var list<JournalTriggerFile> */
    private array $plan;

    /** Prepares a complete generated fixture in a temporary directory. */
    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/hilos-trigger-installer-' . getmypid();
        mkdir($this->path);
        JournalTriggerFiles::setPath($this->path);
        $this->plan = JournalTriggerRenderer::render(
            new LiveTableSchema('sample', ['id' => false], ['id' => 'int'], [], ['id'], ['PRIMARY' => ['id']], []),
            ['id' => new JournalColumnPlacement(JournalColumnMode::RECORD_KEY)],
            new JournalTriggerColumnTypes([]),
            85,
        );
        JournalTriggerFiles::write($this->plan);
    }

    /** Removes only this case's temporary SQL files. */
    protected function tearDown(): void
    {
        foreach (glob($this->path . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->path);
    }

    public function testAnOlderHeaderIsPreservedWhenItsBodyIsStillCanonical(): void
    {
        $file = $this->plan[0];
        $old = new JournalTriggerFile($file->name, $file->body, 42, false);
        file_put_contents($this->filePath($file), $old->content());
        $read = JournalTriggerFiles::readAll($this->plan, 85);
        $this->assertSame(42, $read[0]->migrationIndex);
        $this->assertSame($old->content(), file_get_contents($this->filePath($file)));
        $this->assertCount(3, $read);
    }

    public function testMissingAndUnrecognizedFilesAreReportedTogether(): void
    {
        unlink($this->filePath($this->plan[0]));
        file_put_contents($this->path . '/custom.sql', 'SELECT 1;');
        try {
            JournalTriggerFiles::readAll($this->plan, 85);
            $this->fail('The entire file set must be checked');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString($this->plan[0]->name, $e->getMessage());
            $this->assertStringContainsString('custom.sql', $e->getMessage());
        }
    }

    #[DataProvider('invalidFiles')]
    public function testPreflightRefusesEveryKindOfDrift(string $before, string $after): void
    {
        $file = $this->plan[2];
        file_put_contents($this->filePath($file), str_replace($before, $after, $file->content()));
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage($file->name);
        JournalTriggerFiles::readAll($this->plan, 85);
    }

    /** @return iterable<string, array{string, string}> Header and SQL corruption cases */
    public static function invalidFiles(): iterable
    {
        yield 'future migration' => ['#85', '#86'];
        yield 'overflow migration' => ['#85', '#999999999999999999999999999999'];
        yield 'negative migration' => ['#85', '#-1'];
        yield 'missing header' => ['-- valid from migration #85', '-- handmade'];
        yield 'name' => ['TRIGGER `hilos_cl_sample_after_delete`', 'TRIGGER `custom`'];
        yield 'table' => ['ON `sample`', 'ON `other`'];
        yield 'event' => ['AFTER DELETE', 'AFTER INSERT'];
        yield 'timing' => ['AFTER DELETE', 'BEFORE DELETE'];
        yield 'body' => ['UTC_TIMESTAMP(6)', 'NOW(6)'];
        yield 'second statement' => ["END;\n", "END;\nDROP TABLE sample;\n"];
        yield 'hardcoded database' => ['{{change_log_database}}', '`installation`'];
    }

    public function testTombstonesRequireTheGeneratedDropStatement(): void
    {
        $plan = JournalTriggerRenderer::tombstones('sample', 85);
        JournalTriggerFiles::write($plan);
        $this->assertTrue(JournalTriggerFiles::readAll($plan, 85)[0]->tombstone);
        file_put_contents($this->filePath($plan[1]), str_replace('IF EXISTS ', '', $plan[1]->content()));
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage($plan[1]->name);
        JournalTriggerFiles::readAll($plan, 85);
    }

    public function testLiveComparisonIgnoresDefinerButChecksEveryCoordinateAndBody(): void
    {
        $file = $this->plan[0];
        $sql = $file->sql('main-change-log');
        $body = substr($sql, strpos($sql, 'BEGIN'), -1);
        $row = [
            'TRIGGER_NAME' => $file->name,
            'EVENT_MANIPULATION' => 'INSERT',
            'EVENT_OBJECT_TABLE' => 'sample',
            'ACTION_TIMING' => 'AFTER',
            'ACTION_STATEMENT' => $body,
            'DEFINER' => 'server_user@localhost',
        ];
        $this->assertTrue($file->matches($row, 'main-change-log'));
        foreach (['TRIGGER_NAME', 'EVENT_MANIPULATION', 'EVENT_OBJECT_TABLE', 'ACTION_TIMING', 'ACTION_STATEMENT'] as $key) {
            $changed = $row;
            $changed[$key] .= 'changed';
            $this->assertFalse($file->matches($changed, 'main-change-log'), $key);
        }
        $this->assertFalse($file->matches($row, 'other-change-log'));
    }

    /**
     * @param JournalTriggerFile $file File from the fixture plan
     * @return string Temporary source filename
     */
    private function filePath(JournalTriggerFile $file): string
    {
        return $this->path . '/' . $file->name . '.sql';
    }
}
