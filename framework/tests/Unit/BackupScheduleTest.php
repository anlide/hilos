<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupSchedule;
use Hilos\Backup\BackupScheduleMechanism;
use Hilos\Backup\BackupScope;
use Hilos\Backup\Exception\BackupScheduleException;
use Hilos\Core\Daemon\Cron\CronRuleOrigin;
use Hilos\Core\Daemon\Cron\Exception\CronRuleNameException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure backup schedule model: parsing, validation, and partitioning.
 *
 * The scheduling wiring (agent cron rules firing in onTick, the daemon registering rules, the
 * named cron signal driving the agent) is exercised at e2e; here we pin the catalog-to-model
 * contract the framework and a project catalog must agree on.
 */
final class BackupScheduleTest extends TestCase
{
    public function testDefaultScheduleIsOneDailyFullAgentEntry(): void
    {
        $entries = BackupSchedule::default()->entries();

        $this->assertCount(1, $entries);
        $this->assertSame('hilos_backup_daily_full', $entries[0]->name);
        $this->assertSame(BackupConstants::DEFAULT_SCHEDULE_NAME, $entries[0]->name);
        $this->assertSame(BackupConstants::DEFAULT_SCHEDULE_CRON, $entries[0]->expression);
        $this->assertSame(BackupScope::FULL, $entries[0]->scope);
        $this->assertSame(BackupScheduleMechanism::AGENT, $entries[0]->mechanism);
        $this->assertSame(CronRuleOrigin::FRAMEWORK, $entries[0]->origin);
    }

    public function testFromArrayParsesEntriesInDeclarationOrder(): void
    {
        $schedule = BackupSchedule::fromArray([
            $this->entry('nightly', '0 3 * * *', 'full', 'agent'),
            $this->entry('hourly-schema', '0 * * * *', 'schema-only', 'daemon'),
        ]);

        $entries = $schedule->entries();
        $this->assertSame(['nightly', 'hourly-schema'], [$entries[0]->name, $entries[1]->name]);
        $this->assertSame(BackupScope::SCHEMA_ONLY, $entries[1]->scope);
    }

    public function testAgentAndDaemonEntriesPartitionByMechanism(): void
    {
        $schedule = BackupSchedule::fromArray([
            $this->entry('a', '0 3 * * *', 'full', 'agent'),
            $this->entry('d', '0 4 * * *', 'schema-seed', 'daemon'),
            $this->entry('a2', '0 5 * * *', 'full', 'agent'),
        ]);

        $this->assertSame(['a', 'a2'], array_map(static fn ($e) => $e->name, $schedule->agentEntries()));
        $this->assertSame(['d'], array_map(static fn ($e) => $e->name, $schedule->daemonEntries()));
        $this->assertSame(CronRuleOrigin::PROJECT, $schedule->agentEntries()[0]->origin);
        $this->assertSame(CronRuleOrigin::PROJECT, $schedule->daemonEntries()[0]->origin);
    }

    public function testMechanismDefaultsToAgentWhenAbsent(): void
    {
        $schedule = BackupSchedule::fromArray([
            [
                BackupConstants::SCHEDULE_NAME => 'no-mechanism',
                BackupConstants::SCHEDULE_CRON => '0 3 * * *',
                BackupConstants::SCHEDULE_SCOPE => 'full',
            ],
        ]);

        $this->assertSame(BackupScheduleMechanism::AGENT, $schedule->entries()[0]->mechanism);
    }

    public function testScopeForNameResolvesTheEntryScope(): void
    {
        $schedule = BackupSchedule::fromArray([
            $this->entry('weekly-schema', '0 3 * * 0', 'schema-seed', 'daemon'),
        ]);

        $this->assertSame(BackupScope::SCHEMA_SEED, $schedule->scopeForName('weekly-schema'));
    }

    public function testScopeForNameReturnsNullForUnknownName(): void
    {
        $this->assertNull(BackupSchedule::default()->scopeForName('does-not-exist'));
    }

    public function testFromArrayRejectsDuplicateNames(): void
    {
        $this->expectException(BackupScheduleException::class);

        BackupSchedule::fromArray([
            $this->entry('dup', '0 3 * * *', 'full', 'agent'),
            $this->entry('dup', '0 4 * * *', 'full', 'daemon'),
        ]);
    }

    public function testEntryRejectsMissingName(): void
    {
        $this->expectException(BackupScheduleException::class);

        BackupSchedule::fromArray([$this->entry('', '0 3 * * *', 'full', 'agent')]);
    }

    public function testEntryRejectsMissingCron(): void
    {
        $this->expectException(BackupScheduleException::class);

        BackupSchedule::fromArray([$this->entry('n', '', 'full', 'agent')]);
    }

    public function testEntryRejectsUnknownScope(): void
    {
        $this->expectException(BackupScheduleException::class);

        BackupSchedule::fromArray([$this->entry('n', '0 3 * * *', 'everything', 'agent')]);
    }

    public function testEntryRejectsUnknownMechanism(): void
    {
        $this->expectException(BackupScheduleException::class);

        BackupSchedule::fromArray([$this->entry('n', '0 3 * * *', 'full', 'sidecar')]);
    }

    public function testProjectScheduleRejectsReservedNameOnTheAgentMechanism(): void
    {
        $this->assertReservedScheduleNameRefused('agent');
    }

    public function testProjectScheduleRejectsReservedNameOnTheDaemonMechanism(): void
    {
        $this->assertReservedScheduleNameRefused('daemon');
    }

    public function testRawOriginDoesNotPromoteACatalogEntryToFramework(): void
    {
        $raw = $this->entry('nightly', '0 3 * * *', 'full', 'agent');
        $raw['origin'] = 'framework';

        $entry = BackupSchedule::fromArray([$raw])->entries()[0];

        $this->assertSame('nightly', $entry->name);
        $this->assertSame(CronRuleOrigin::PROJECT, $entry->origin);
    }

    /** @param string $mechanism Catalog mechanism value under test */
    private function assertReservedScheduleNameRefused(string $mechanism): void
    {
        try {
            BackupSchedule::fromArray([
                $this->entry('hilos_nightly', '0 3 * * *', 'full', $mechanism),
            ]);
            $this->fail('A project schedule must not declare a reserved name');
        } catch (BackupScheduleException $exception) {
            $this->assertInstanceOf(CronRuleNameException::class, $exception->getPrevious());
            $this->assertStringContainsString('hilos_nightly', $exception->getMessage());
        }
    }

    /**
     * Builds a raw catalog schedule row from its four fields.
     *
     * @param string $name Entry name
     * @param string $cron Cron expression
     * @param string $scope Scope value
     * @param string $mechanism Mechanism value
     * @return array<string, string> Raw catalog schedule row
     */
    private function entry(string $name, string $cron, string $scope, string $mechanism): array
    {
        return [
            BackupConstants::SCHEDULE_NAME => $name,
            BackupConstants::SCHEDULE_CRON => $cron,
            BackupConstants::SCHEDULE_SCOPE => $scope,
            BackupConstants::SCHEDULE_MECHANISM => $mechanism,
        ];
    }
}
