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
                $refusals[] = self::aheadOfCode($connection->index, $archiveIndex, $codeIndex);
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
     * Resolves the migration level one restored connection is to record before it migrates.
     *
     * The gate's second question, and a different one from {@see decide()}: that one asks whether
     * the archive may be restored at all, this one asks what number the restored `migration` table
     * is to carry. Only schema archives ask it - a FULL archive brings its own rows - and the
     * answer has three possible sources, so the order they are weighed in is the rule:
     *
     * 1. the archive's own marker against the operator's `--migration-index`: a disagreement is
     *    refused rather than resolved, because the operator may not overrule the archive's own
     *    record of itself, and lowering it brings back the very replay this marker exists to stop;
     * 2. the marker against the sidecar's recorded level: both are written in the same run from
     *    the same reading, so they cannot honestly disagree - one of the two files is not the one
     *    it claims to be, and there is nothing to guess with a whole database at stake;
     * 3. neither a marker nor an override: refused, with the recipe for producing the number,
     *    because an archive written before the marker existed is restorable, just not silently;
     * 4. a level ahead of the code: refused in the same words {@see decide()} refuses by, since
     *    an operator-supplied number may no more skip the missing downgrade path than an archive.
     *
     * A sidecar whose level is null - written before the field existed - contradicts nothing.
     *
     * Pure, like {@see decide()}: reading the marker out of the extracted dump is the engine's job.
     *
     * @param ?int $marker Level the connection's dump declares; null when it declares none
     * @param ?int $sidecarIndex Level the sidecar recorded for it; null when it was not recorded
     * @param ?int $override Level the operator named for the run; null when they named none
     * @param ?int $codeIndex Migration level this code expects; null when it lists no migrations
     * @param int $connectionIndex Connection index, for the operator-facing text
     * @return RestoreMigrationLevelResult Level to record, or the refusal that stops the run
     */
    public static function resolveLevel(
        ?int $marker,
        ?int $sidecarIndex,
        ?int $override,
        ?int $codeIndex,
        int $connectionIndex,
    ): RestoreMigrationLevelResult {
        if ($marker !== null && $override !== null && $marker !== $override) {
            return RestoreMigrationLevelResult::refuse(sprintf(
                'connection %d: the archive records migration %d, --%s says %d',
                $connectionIndex,
                $marker,
                BackupConstants::MIGRATION_INDEX_OPTION,
                $override,
            ));
        }

        if ($marker !== null && $sidecarIndex !== null && $marker !== $sidecarIndex) {
            return RestoreMigrationLevelResult::refuse(sprintf(
                'connection %d: the archive contradicts its sidecar (dump %d, sidecar %d);'
                . ' one of the two files is not the one it claims to be',
                $connectionIndex,
                $marker,
                $sidecarIndex,
            ));
        }

        if ($marker === null && $override === null) {
            return RestoreMigrationLevelResult::refuse(sprintf(
                'connection %d: this schema archive records no migration level; re-run with --%s=<N>,'
                . ' where N is the highest numeric prefix among the migration files of this tree',
                $connectionIndex,
                BackupConstants::MIGRATION_INDEX_OPTION,
            ));
        }

        $level = $marker ?? $override;
        if ($codeIndex !== null && $level > $codeIndex) {
            return RestoreMigrationLevelResult::refuse(self::aheadOfCode($connectionIndex, $level, $codeIndex));
        }

        return RestoreMigrationLevelResult::allow($level);
    }

    /**
     * Words one connection whose level is ahead of the code's.
     *
     * Shared by both refusals of this gate, so the archive that is ahead and the operator who
     * names a level that is ahead are told the same thing about the same missing downgrade path.
     *
     * @param int $connectionIndex Connection index
     * @param int $archiveIndex Level the archive or the operator names
     * @param int $codeIndex Migration level this code expects
     * @return string One operator-facing refusal line
     */
    private static function aheadOfCode(int $connectionIndex, int $archiveIndex, int $codeIndex): string
    {
        return sprintf(
            'connection %d: archive at migration %d, code expects %d (%d ahead);'
            . ' there is no downgrade path',
            $connectionIndex,
            $archiveIndex,
            $codeIndex,
            $archiveIndex - $codeIndex,
        );
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
