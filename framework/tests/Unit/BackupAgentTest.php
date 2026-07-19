<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use DateTimeImmutable;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupScope;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure, side-effect-free logic of the backup supervisor.
 *
 * The spawn/poll/timeout path drives a live child and is exercised at e2e; here we pin the
 * id-from-timestamp format and the child argv contract (command name + scope option) the
 * supervisor and the project child command must agree on.
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
}
