<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\BackupRestoreRunCommand;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the backup:restore-run child's argv contract.
 *
 * Covers only the parsing branches that return before the engine is entered; the
 * restore behavior itself is exercised by the framework's BackupRestorerIntegrationTest.
 * The name assertion is the contract between the two halves: the supervisor spawns exactly
 * {@see BackupConstants::RESTORE_RUN_COMMAND}, and the child answers to that one name.
 */
final class BackupRestoreRunCommandTest extends TestCase
{
    public function testCommandNameIsTheFrameworkContract(): void
    {
        $this->assertSame(BackupConstants::RESTORE_RUN_COMMAND, new BackupRestoreRunCommand()->getName());
    }

    public function testMissingIdFails(): void
    {
        $code = new BackupRestoreRunCommand()->execute(
            [BackupConstants::FIELD_DECISION => RestoreEnvDecision::ALLOW->value],
            [],
        );

        $this->assertSame(ExitCode::ERROR, $code);
    }

    public function testUnknownScopeFails(): void
    {
        $code = new BackupRestoreRunCommand()->execute(
            [
                BackupConstants::SCOPE_OPTION => 'nonsense',
                BackupConstants::FIELD_DECISION => RestoreEnvDecision::ALLOW->value,
            ],
            ['2026-08-08_03-00-00'],
        );

        $this->assertSame(ExitCode::ERROR, $code);
    }

    public function testMissingDecisionFails(): void
    {
        $code = new BackupRestoreRunCommand()->execute([], ['2026-08-08_03-00-00']);

        $this->assertSame(ExitCode::ERROR, $code);
    }

    public function testUnknownDecisionFails(): void
    {
        $code = new BackupRestoreRunCommand()->execute(
            [BackupConstants::FIELD_DECISION => 'maybe'],
            ['2026-08-08_03-00-00'],
        );

        $this->assertSame(ExitCode::ERROR, $code);
    }

    public function testANonIntegerMigrationIndexFails(): void
    {
        // argv is an external boundary whoever built it, and a cast would turn nonsense into
        // level 0 - a level a schema archive may legitimately be restored at.
        $code = new BackupRestoreRunCommand()->execute(
            [
                BackupConstants::FIELD_DECISION => RestoreEnvDecision::ALLOW->value,
                BackupConstants::MIGRATION_INDEX_OPTION => 'latest',
            ],
            ['2026-08-08_03-00-00'],
        );

        $this->assertSame(ExitCode::ERROR, $code);
    }

    public function testANegativeMigrationIndexFails(): void
    {
        $code = new BackupRestoreRunCommand()->execute(
            [
                BackupConstants::FIELD_DECISION => RestoreEnvDecision::ALLOW->value,
                BackupConstants::MIGRATION_INDEX_OPTION => '-1',
            ],
            ['2026-08-08_03-00-00'],
        );

        $this->assertSame(ExitCode::ERROR, $code);
    }

    public function testAFailureThatNeverTouchedTheDatabaseGetsItsOwnExitCode(): void
    {
        $code = BackupRestoreRunCommand::exitCodeFor(
            RestoreFailedException::beforeDestructive('Archive digest does not match'),
        );

        $this->assertSame(BackupConstants::RESTORE_EXIT_DATABASE_INTACT, $code);
    }

    public function testAFailureInsideTheDestructiveWindowIsAPlainError(): void
    {
        $code = BackupRestoreRunCommand::exitCodeFor(
            RestoreFailedException::afterDestructive('Import failed for connection 1'),
        );

        $this->assertSame(ExitCode::ERROR, $code);
    }

    public function testTheSupervisorReadsBackExactlyWhatTheChildMeant(): void
    {
        foreach ([false, true] as $touched) {
            $failure = $touched
                ? RestoreFailedException::afterDestructive('Import failed')
                : RestoreFailedException::beforeDestructive('Digest mismatch');

            $this->assertSame(
                $touched,
                BackupAgent::restoreTouchedDatabase(BackupRestoreRunCommand::exitCodeFor($failure)),
                'The child chooses the code and the supervisor reads it; the two must not drift',
            );
        }
    }
}
