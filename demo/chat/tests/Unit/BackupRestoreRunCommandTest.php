<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\CLI\Commands\BackupRestoreRunCommand;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Constants\ExitCode;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the backup:restore-run child's argv contract.
 *
 * Covers only the parsing branches that return before the engine is entered; the
 * restore behavior itself is exercised by the framework's BackupRestorerIntegrationTest.
 * The name assertion is the activation contract: the supervisor spawns exactly
 * {@see BackupConstants::RESTORE_RUN_COMMAND}, and the feature declaration requires a
 * project command registered under it.
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
