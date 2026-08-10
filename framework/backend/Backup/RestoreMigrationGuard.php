<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Database\Migration;

/**
 * RestoreMigrationGuard - the migration-index gate on the restore path.
 *
 * The sidecar records the migration level of every dumped connection; until this gate
 * nothing read it back, so a dump taken at level 32 could be replayed into code that
 * expects 40 and the mismatch surfaced later as unrelated SQL errors.
 *
 * The comparison is against the level the CODE expects
 * ({@see codeMigrationIndex()}), not against the target database's current level: the
 * dump carries its own `migration` table and overwrites whatever was there, so the
 * target's level before the restore decides nothing. It also keeps the gate readable
 * with no database at all - the available migrations are files on disk - which is what
 * lets the cold path refuse before it touches anything.
 *
 * Outcomes, per connection recorded in the sidecar:
 * - equal: nothing to say, nothing to migrate;
 * - archive older than the code: allowed, and the missing migrations are applied after
 *   the import - the same policy the daemon's own startup already follows, rather than
 *   a flag or a question invented for restore alone;
 * - archive newer than the code: refused, always, because there is no downgrade path;
 * - level unknown (a sidecar written before the field, or a tree with no migration
 *   list configured): allowed with the gap reported, on the NO_DIGEST precedent -
 *   "nothing to check" is not "broken".
 *
 * One disagreeing connection refuses the whole run: a partially compatible restore is
 * the same unusable system, only harder to notice.
 *
 * {@see decide()} is a pure function of its inputs, the shape {@see RestoreEnvGuard}
 * established: the CLI preflight and the engine must reach the same answer from the
 * same inputs, and the one method that reads the disk is kept separate so the decision
 * is testable without a filesystem.
 */
final class RestoreMigrationGuard
{
    /**
     * Decides whether an archive's recorded migration levels may be restored into this code.
     *
     * @param list<BackupConnectionMeta> $connections Connections recorded in the archive sidecar
     * @param ?int $codeIndex Migration level this code expects; null when it lists no migrations
     * @return RestoreMigrationDecisionResult Verdict, refusal reason, and the per-connection gaps
     */
    public static function decide(array $connections, ?int $codeIndex): RestoreMigrationDecisionResult
    {
        $gaps = [];
        $refusals = [];
        foreach ($connections as $connection) {
            $archiveIndex = $connection->migrationIndex;
            if ($archiveIndex !== null && $codeIndex !== null && $archiveIndex === $codeIndex) {
                continue;
            }

            $gaps[] = new RestoreMigrationGap($connection->index, $archiveIndex);
            if ($archiveIndex !== null && $codeIndex !== null && $archiveIndex > $codeIndex) {
                $refusals[] = sprintf(
                    'connection %d: archive at migration %d, code expects %d (%d ahead);'
                    . ' there is no downgrade path',
                    $connection->index,
                    $archiveIndex,
                    $codeIndex,
                    $archiveIndex - $codeIndex,
                );
            }
        }

        if ($refusals !== []) {
            return new RestoreMigrationDecisionResult(
                RestoreMigrationDecision::REFUSE,
                implode('; ', $refusals),
                $codeIndex,
                $gaps,
            );
        }

        return new RestoreMigrationDecisionResult(RestoreMigrationDecision::ALLOW, null, $codeIndex, $gaps);
    }

    /**
     * Reads the migration level this installation's code expects.
     *
     * The only non-pure member of the gate, kept here so both restore paths derive the
     * level the same way. Migration files are read from disk, so this answers on a dead
     * system too.
     *
     * @return ?int Highest available migration index; null when no migrations are listed
     */
    public static function codeMigrationIndex(): ?int
    {
        $available = Migration::getAvailableMigrations();

        return $available === [] ? null : max($available);
    }
}
