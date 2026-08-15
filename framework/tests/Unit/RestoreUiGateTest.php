<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\RestoreEnvDecision;
use Hilos\Backup\RestoreEnvDecisionResult;
use Hilos\Backup\RestoreUiGate;
use Hilos\Constants\AppEnv;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\View\Item\BackupHistory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for everything the backup page checks before asking for a restore.
 *
 * This is where the page's validation is tested at all: the page itself is a signal seam with
 * no unit of its own, so the gate is deliberately the piece that holds the decisions. Each
 * refusal gets its own test rather than a data provider, because each is a separate owner
 * decision and a failure should name the one that broke.
 */
final class RestoreUiGateTest extends TestCase
{
    private const string BACKUP_ID = '2026-08-15_10-30-00';

    public function testProductionRefusesTheUiEntirely(): void
    {
        $result = RestoreUiGate::decide(AppEnv::PROD, self::BACKUP_ID, $this->storedBackup(), busy: false, envVerdict: $this->allowed());

        $this->assertFalse($result->allowed);
        $this->assertSame('Restoring from the UI is disabled on this environment; use the CLI', $result->reason);
    }

    public function testAnInstallationThatCannotNameItsEnvironmentIsTreatedAsProduction(): void
    {
        $result = RestoreUiGate::decide(null, self::BACKUP_ID, $this->storedBackup(), busy: false, envVerdict: null);

        $this->assertFalse($result->allowed);
        $this->assertSame('Restoring from the UI is disabled on this environment; use the CLI', $result->reason);
    }

    public function testTheEnvironmentIsRefusedBeforeTheArchiveIsEvenLookedFor(): void
    {
        // On production the answer must not depend on the id: a surface that says "not found"
        // for one id and something else for another is a surface that answers questions.
        $result = RestoreUiGate::decide(AppEnv::PROD, self::BACKUP_ID, null, busy: true, envVerdict: null);

        $this->assertSame('Restoring from the UI is disabled on this environment; use the CLI', $result->reason);
    }

    public function testAnArchiveThatIsNotIndexedIsRefusedByName(): void
    {
        $result = RestoreUiGate::decide(AppEnv::DEV, self::BACKUP_ID, null, busy: false, envVerdict: null);

        $this->assertFalse($result->allowed);
        $this->assertSame('Backup not found: 2026-08-15_10-30-00', $result->reason);
    }

    public function testARecordedFailureCannotBeRestored(): void
    {
        $row = $this->storedBackup([StateBackupHistory::status => 'error']);

        $result = RestoreUiGate::decide(AppEnv::DEV, self::BACKUP_ID, $row, busy: false, envVerdict: $this->allowed());

        $this->assertFalse($result->allowed);
        $this->assertSame('Only a successful backup can be restored', $result->reason);
    }

    public function testAnArchiveKnownToDifferFromItsDigestIsRefused(): void
    {
        $row = $this->storedBackup([
            StateBackupHistory::sha256 => 'deadbeef',
            StateBackupHistory::verifyOutcome => 'mismatch',
        ]);

        $result = RestoreUiGate::decide(AppEnv::DEV, self::BACKUP_ID, $row, busy: false, envVerdict: $this->allowed());

        $this->assertFalse($result->allowed);
        $this->assertSame('This archive does not match its recorded checksum', $result->reason);
    }

    public function testAVerifiedArchiveIsNotMistakenForACorruptOne(): void
    {
        $row = $this->storedBackup([
            StateBackupHistory::sha256 => 'deadbeef',
            StateBackupHistory::verifyOutcome => 'ok',
        ]);

        $result = RestoreUiGate::decide(AppEnv::DEV, self::BACKUP_ID, $row, busy: false, envVerdict: $this->allowed());

        $this->assertTrue($result->allowed);
    }

    public function testAnArchiveThatWasNeverHashedIsNotRefused(): void
    {
        // No digest is the shape of every archive written before checksums existed, and refusing
        // those would leave the whole accumulated history unrestorable from the page.
        $result = RestoreUiGate::decide(AppEnv::DEV, self::BACKUP_ID, $this->storedBackup(), busy: false, envVerdict: $this->allowed());

        $this->assertTrue($result->allowed);
    }

    public function testABusySubsystemRefusesTheRun(): void
    {
        $result = RestoreUiGate::decide(AppEnv::DEV, self::BACKUP_ID, $this->storedBackup(), busy: true, envVerdict: $this->allowed());

        $this->assertFalse($result->allowed);
        $this->assertSame('The backup subsystem is busy; wait for the current run to end', $result->reason);
    }

    public function testARefusingEnvMatrixIsRepeatedInItsOwnWords(): void
    {
        $verdict = new RestoreEnvDecisionResult(
            RestoreEnvDecision::REFUSE,
            'a non-production archive must not overwrite production',
        );

        $result = RestoreUiGate::decide(AppEnv::DEV, self::BACKUP_ID, $this->storedBackup(), busy: false, envVerdict: $verdict);

        $this->assertFalse($result->allowed);
        $this->assertSame('a non-production archive must not overwrite production', $result->reason);
    }

    public function testAnArchiveNeedingAnonymizationIsStillAllowedThrough(): void
    {
        // The engine performs the anonymization pass; it is not a question the button asks.
        $verdict = new RestoreEnvDecisionResult(
            RestoreEnvDecision::REQUIRE_ANONYMIZATION,
            'production archive must be anonymized before restoring into a non-production environment',
        );

        $result = RestoreUiGate::decide(AppEnv::DEV, self::BACKUP_ID, $this->storedBackup(), busy: false, envVerdict: $verdict);

        $this->assertTrue($result->allowed);
        $this->assertNull($result->reason);
    }

    public function testEveryNonProductionEnvironmentOffersTheRestore(): void
    {
        foreach ([AppEnv::STAGING, AppEnv::DEV, AppEnv::LOCAL, AppEnv::TEST] as $env) {
            $result = RestoreUiGate::decide($env, self::BACKUP_ID, $this->storedBackup(), busy: false, envVerdict: $this->allowed());

            $this->assertTrue($result->allowed, "Restore must be offered on {$env->value}");
        }
    }

    /**
     * @return RestoreEnvDecisionResult Plainly allowing ENV matrix verdict
     */
    private function allowed(): RestoreEnvDecisionResult
    {
        return new RestoreEnvDecisionResult(RestoreEnvDecision::ALLOW);
    }

    /**
     * Builds the index row of a stored backup, the way the gate is handed one.
     *
     * @param array<string, mixed> $overrides Fields replacing the successful, never-hashed default
     * @return BackupHistory Index row view over the seeded state
     */
    private function storedBackup(array $overrides = []): BackupHistory
    {
        $state = StateBackupHistory::fromRow($overrides + [
            StateBackupHistory::id => self::BACKUP_ID,
            StateBackupHistory::createdAt => '2026-08-15T10:30:00+00:00',
            StateBackupHistory::env => 'dev',
            StateBackupHistory::scope => 'full',
            StateBackupHistory::status => 'success',
        ]);

        return new BackupHistory($state);
    }
}
