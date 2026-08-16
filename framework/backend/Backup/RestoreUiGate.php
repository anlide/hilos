<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Constants\AppEnv;
use Hilos\Runtime\View\Item\BackupHistory;

/**
 * RestoreUiGate - everything the backup page checks before it asks for a restore.
 *
 * A sibling of {@see RestoreEnvGuard}: pure, no facade reads, no filesystem, decided from
 * the values it is handed. The page collects those values and turns a refusal into the
 * client's correlated action error; nothing about the decision itself lives there, so the
 * whole of it can be tested without a page, a runtime or a socket.
 *
 * The refusals are ordered from the widest to the narrowest, so an operator is told the
 * one thing that actually stops them:
 *
 * 1. **The environment.** Production - and an installation that cannot name its
 *    environment, which is treated as production - has no restore button at all
 *    (owner decision, HIL-276), and the check is repeated here rather than trusted to the
 *    hidden button: a client is not the source of truth about where it runs.
 * 2. **The archive.** It must be in the index, it must have completed successfully, it
 *    must not be known to differ from its digest, and its recorded migration levels must be
 *    replayable into this code ({@see RestoreMigrationGuard}). The engine would refuse a
 *    corrupt archive itself on its verify step, but that refusal costs a frozen node to reach.
 * 3. **The subsystem.** One child, one run: a create or a restore already in flight means
 *    this one cannot start. The agent re-checks it too - between this answer and the signal
 *    it may have taken work on - and this check is what explains the refusal before the
 *    click rather than after it.
 * 4. **The ENV matrix**, in its own words. It is computed by {@see RestoreEnvGuard} from
 *    the archive's environment and this installation's, and repeated verbatim.
 */
final class RestoreUiGate
{
    /** Refusal shown where the surface is withheld entirely; names the way that is left. */
    private const string ENV_REFUSAL = 'Restoring from the UI is disabled on this environment; use the CLI';

    /**
     * Decides whether the page may ask the agent to restore this archive.
     *
     * @param ?AppEnv $targetEnv Environment this installation runs in; null when APP_ENV names none
     * @param string $backupId Archive the client asked for, named back in the not-found refusal
     * @param ?BackupHistory $row Index row of that archive; null when the index carries none
     * @param bool $busy Whether a backup or a restore is already running
     * @param ?RestoreEnvDecisionResult $envVerdict ENV matrix verdict for this pair; null when there is
     *     no pair to judge (no row, or no environment to restore into)
     * @param ?RestoreMigrationDecisionResult $migrationVerdict Migration-index verdict for this archive;
     *     null when there is no row to judge
     * @return RestoreUiDecisionResult Verdict, carrying the operator-facing reason where it refuses
     */
    public static function decide(
        ?AppEnv $targetEnv,
        string $backupId,
        ?BackupHistory $row,
        bool $busy,
        ?RestoreEnvDecisionResult $envVerdict,
        ?RestoreMigrationDecisionResult $migrationVerdict,
    ): RestoreUiDecisionResult {
        if ($targetEnv === null || $targetEnv === AppEnv::PROD) {
            return RestoreUiDecisionResult::refuse(self::ENV_REFUSAL);
        }
        if ($row === null) {
            return RestoreUiDecisionResult::refuse("Backup not found: {$backupId}");
        }
        if (BackupStatus::fromString($row->status) !== BackupStatus::SUCCESS) {
            return RestoreUiDecisionResult::refuse('Only a successful backup can be restored');
        }
        if (BackupChecksumState::fromRecord($row->sha256, $row->verifyOutcome) === BackupChecksumState::MISMATCH) {
            return RestoreUiDecisionResult::refuse('This archive does not match its recorded checksum');
        }
        // Read for its reason rather than its decision, the way the ENV matrix is read below:
        // the migration guard writes the verdict and the words that explain it together.
        $migrationRefusal = $migrationVerdict?->decision === RestoreMigrationDecision::REFUSE
            ? $migrationVerdict->reason
            : null;
        if ($migrationRefusal !== null) {
            return RestoreUiDecisionResult::refuse($migrationRefusal);
        }
        if ($busy) {
            return RestoreUiDecisionResult::refuse('The backup subsystem is busy; wait for the current run to end');
        }
        // A refusing matrix always names why (RestoreEnvGuard writes the two together), so the
        // reason is read rather than the decision: it is the whole point of repeating the verdict.
        $envRefusal = $envVerdict?->decision === RestoreEnvDecision::REFUSE ? $envVerdict->reason : null;
        if ($envRefusal !== null) {
            return RestoreUiDecisionResult::refuse($envRefusal);
        }

        return RestoreUiDecisionResult::allow();
    }
}
