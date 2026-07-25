<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use DateTimeImmutable;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupScope;
use Hilos\Constants\EnvConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure, side-effect-free logic of the backup supervisor.
 *
 * The spawn/poll/timeout path drives a live child and is exercised at e2e; here we pin the
 * id-from-timestamp format, the child argv contract (command name + scope option) the
 * supervisor and the project child command must agree on, and the create-path precondition
 * that keeps a misconfigured install from launching a run it could never report on.
 */
final class BackupAgentTest extends TestCase
{
    public function testGenerateBackupIdIsTheSortableTimestampStem(): void
    {
        $id = BackupAgent::generateBackupId(new DateTimeImmutable('2026-07-19T10:30:00'));

        $this->assertSame('2026-07-19_10-30-00', $id);
    }

    public function testGenerateBackupIdZeroPadsSingleDigitParts(): void
    {
        $id = BackupAgent::generateBackupId(new DateTimeImmutable('2026-01-02T03:04:05'));

        $this->assertSame('2026-01-02_03-04-05', $id);
    }

    public function testChildArgsCarryTheCommandNameIdAndScopeOption(): void
    {
        $args = BackupAgent::buildChildArgs('/app/cli.php', '2026-07-19_10-30-00', BackupScope::FULL);

        $this->assertSame(
            ['/app/cli.php', BackupConstants::RUN_COMMAND, '2026-07-19_10-30-00', '--scope=full'],
            $args,
        );
    }

    public function testChildArgsUseTheScopeStorageValue(): void
    {
        $args = BackupAgent::buildChildArgs('/app/cli.php', 'id', BackupScope::SCHEMA_ONLY);

        $this->assertSame('--scope=schema-only', $args[3]);
    }

    public function testNothingIsMissingWhenBothCreateSettingsAreConfigured(): void
    {
        $missing = BackupAgent::missingCreateConfig('/app/data/backup', '/app/cli.php');

        $this->assertSame([], $missing);
    }

    public function testAnEmptyStorageRootIsReportedMissing(): void
    {
        $missing = BackupAgent::missingCreateConfig('', '/app/cli.php');

        $this->assertSame([EnvConstants::BACKUP_DIR->name], $missing);
    }

    public function testAnEmptyCliEntryIsReportedMissing(): void
    {
        $missing = BackupAgent::missingCreateConfig('/app/data/backup', '');

        $this->assertSame([EnvConstants::BACKUP_CLI_ENTRY->name], $missing);
    }

    public function testBothSettingsAreReportedWhenNeitherIsConfigured(): void
    {
        $missing = BackupAgent::missingCreateConfig('', '');

        $this->assertSame(
            [EnvConstants::BACKUP_DIR->name, EnvConstants::BACKUP_CLI_ENTRY->name],
            $missing,
        );
    }

    public function testFailureNoticeCarriesTheIdAndTheReason(): void
    {
        $notice = BackupAgent::failureNotice('2026-07-19_10-30-00', 'child exited with code 1');

        $this->assertSame('Backup 2026-07-19_10-30-00 failed: child exited with code 1', $notice);
    }

    public function testFailureNoticeKeepsOnlyTheFirstStderrLine(): void
    {
        $notice = BackupAgent::failureNotice('id', "dump failed\nmysqldump: not found\nstack trace");

        $this->assertSame('Backup id failed: dump failed', $notice);
    }

    public function testFailureNoticeCapsALongDetail(): void
    {
        $notice = BackupAgent::failureNotice('id', str_repeat('x', 500));

        $this->assertSame(200, mb_strlen(substr($notice, strlen('Backup id failed: '))));
        $this->assertStringEndsWith('…', $notice);
    }

    public function testFailureNoticeStandsAloneWithoutADetail(): void
    {
        $notice = BackupAgent::failureNotice('id', '');

        $this->assertSame('Backup id failed', $notice);
    }
}
