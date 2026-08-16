<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CliCommands;
use Hilos\Core\CLI\Commands\BackupTestAgeCommand;
use Hilos\Core\CLI\Commands\BackupTestPruneCommand;
use Hilos\Core\CLI\Commands\BackupTestRunScheduleCommand;
use Hilos\Core\CLI\Commands\BackupTestShipCommand;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use PHPUnit\Framework\TestCase;

/**
 * Pins the four backup test-CLI commands to their names and their test-only contract, so a
 * reader (and the CLI registration) cannot drift, and so none of them can run on production
 * (each extends {@see TestOnlyCommand}, whose final execute() enforces the non-prod guard).
 */
final class BackupTestCommandsTest extends TestCase
{
    public function testCommandsExposeTheirCliNames(): void
    {
        $this->assertSame(CliCommands::BACKUP_TEST_AGE, new BackupTestAgeCommand()->getName());
        $this->assertSame(CliCommands::BACKUP_TEST_PRUNE, new BackupTestPruneCommand()->getName());
        $this->assertSame(CliCommands::BACKUP_TEST_RUN_SCHEDULE, new BackupTestRunScheduleCommand()->getName());
        $this->assertSame(CliCommands::BACKUP_TEST_SHIP, new BackupTestShipCommand()->getName());
    }

    public function testCommandsAreTestOnly(): void
    {
        $this->assertInstanceOf(TestOnlyCommand::class, new BackupTestAgeCommand());
        $this->assertInstanceOf(TestOnlyCommand::class, new BackupTestPruneCommand());
        $this->assertInstanceOf(TestOnlyCommand::class, new BackupTestRunScheduleCommand());
        $this->assertInstanceOf(TestOnlyCommand::class, new BackupTestShipCommand());
    }
}
