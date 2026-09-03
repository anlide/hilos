<?php

declare(strict_types=1);

namespace Hilos\Backup\Ship;

use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupHistoryScanner;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupShipOutcome;
use Hilos\Backup\BackupSpaceGuard;
use Hilos\Backup\BackupStatus;
use Hilos\Runtime\View\Item\BackupHistory;

/**
 * BackupShipPlanner - decides which single transfer to run next, and stops when there is none.
 *
 * The runtime index IS the queue: no separate persisted list, so after an agent restart the
 * queue rebuilds itself from the storage scan (files=truth, RT=index). Shaped like
 * {@see BackupSpaceGuard}: rows and a clock in, one ready-made decision out, so the whole
 * ordering is unit-tested without an agent, a link, or a receiver.
 *
 * One transfer at a time, newest backup first: on a narrow link a fresh restore point is worth
 * more than an old one. Attempts stop by themselves once the local archive is gone - a backup
 * rotation removed is not owed a copy.
 */
final class BackupShipPlanner
{
    /**
     * How long a backup waits before it is tried again. A code constant, not env, on the same
     * criterion that left {@see BackupSpaceGuard::ESTIMATE_DEPTH} one: this is how often a
     * failing transfer is re-polled, not a policy anyone deploys differently.
     */
    public const int RETRY_SECONDS = 300;

    /**
     * Prefix under which a mirror step records its attempt, keeping it clear of the backup ids
     * sharing the map. It is what makes a mirror pass terminate: a scope that carries this mark
     * has already been re-stated since the last local delete, so the next call moves on to the
     * next scope and then to null.
     *
     * A mark is read by PRESENCE and not by age, unlike the push retries sharing the map. The
     * two are paced by different things: a push is re-tried on a clock, while a mirror is owed
     * exactly one pass per delete - and a sweep of every scope can outlast any interval on a
     * narrow link, which under an aged mark would make the first scope due again before the
     * last one is reached and re-state the receiver forever. What arms the pass again is a
     * local delete, which drops these marks together with raising the dirty flag.
     */
    public const string MIRROR_ATTEMPT_PREFIX = 'mirror:';

    /**
     * Picks the next transfer.
     *
     * Pushes come before mirrors, and not only because a new restore point matters more than a
     * tidy receiver: a mirror deletes remotely, so running it while pushes are outstanding would
     * spend the link removing files in the same pass that is trying to add them.
     *
     * @param list<BackupHistory> $rows Current backup index rows (all scopes and statuses)
     * @param string $root Local storage root (`BACKUP_DIR`)
     * @param array<string, float> $lastAttemptAt When each backup id was last attempted, and
     *     which {@see MIRROR_ATTEMPT_PREFIX} scopes have had their pass
     * @param bool $mirrorDirty Whether something was deleted locally since the last mirror pass
     * @param float $now Current time as a unix timestamp
     * @param ?string $encryption Fingerprint of the recipient set copies are encrypted to now;
     *     null when this installation ships in the clear
     * @return ?BackupShipPlan The transfer to run next, or null when there is nothing to do
     */
    public function plan(
        array $rows,
        string $root,
        array $lastAttemptAt,
        bool $mirrorDirty,
        float $now,
        ?string $encryption,
    ): ?BackupShipPlan {
        $candidate = $this->nextCandidate($rows, $root, $lastAttemptAt, $now, $encryption);
        if ($candidate !== null) {
            return $candidate;
        }

        if (!$mirrorDirty) {
            return null;
        }

        return $this->nextMirror($root, $lastAttemptAt);
    }

    /**
     * The sidecar step that follows a finished archive step of the same backup.
     *
     * The pair is a sequence, not two independent candidates: until both files are there the
     * backup is not shipped, and the index carries no "archive already across" state to plan the
     * second half from. So the archive step is planned and the sidecar step is derived from it.
     *
     * Derived by swapping the extension rather than by rebuilding the stored name a second time:
     * the two files differ in nothing else, and a second derivation is one waiting to disagree
     * with the first over a backup whose id, environment, or scope reads oddly.
     *
     * @param BackupShipPlan $archiveStep The finished {@see BackupShipStep::PUSH_ARCHIVE} step
     * @return BackupShipPlan The sidecar transfer of the same backup
     */
    public function sidecarStep(BackupShipPlan $archiveStep): BackupShipPlan
    {
        $base = substr(
            $archiveStep->localPath,
            0,
            -strlen(BackupHistoryScanner::ARCHIVE_EXTENSION),
        );

        return new BackupShipPlan(
            BackupShipStep::PUSH_SIDECAR,
            $archiveStep->backupId,
            $archiveStep->scope,
            $base . BackupHistoryScanner::SIDECAR_EXTENSION,
        );
    }

    /**
     * The newest backup owed a copy whose retry interval has elapsed.
     *
     * A backup is owed one when the last attempt did not succeed, and equally when it did but left
     * in a SHAPE the installation no longer ships in. That second half is the whole of what makes
     * turning a key on re-send the store as ciphertext, turning it off re-send it in the clear,
     * and turning it over re-send copies the old key can no longer open - no migration command,
     * and no state beyond the mark the last copy left.
     *
     * @param list<BackupHistory> $rows Current backup index rows
     * @param string $root Local storage root
     * @param array<string, float> $lastAttemptAt When each backup id was last attempted
     * @param float $now Current time as a unix timestamp
     * @param ?string $encryption Fingerprint copies are encrypted to now; null for a clear copy
     * @return ?BackupShipPlan First step of that backup, or null when none is due
     */
    private function nextCandidate(
        array $rows,
        string $root,
        array $lastAttemptAt,
        float $now,
        ?string $encryption,
    ): ?BackupShipPlan {
        $candidates = array_values(array_filter(
            $rows,
            static fn(BackupHistory $row): bool => BackupStatus::fromString($row->status) === BackupStatus::SUCCESS
                && (BackupShipOutcome::fromString($row->shipOutcome) !== BackupShipOutcome::OK
                    || $row->shipEncryption !== $encryption),
        ));

        usort($candidates, static fn(BackupHistory $a, BackupHistory $b): int => strcmp($b->createdAt, $a->createdAt));

        foreach ($candidates as $row) {
            if ($this->tooSoon($lastAttemptAt, $row->getId(), $now)) {
                continue;
            }

            $archivePath = $this->archivePath($row, $root);
            if ($archivePath === null || !is_file($archivePath)) {
                continue;
            }

            $step = $encryption === null ? BackupShipStep::PUSH_ARCHIVE : BackupShipStep::ENCRYPT_ARCHIVE;

            return new BackupShipPlan($step, $row->getId(), (string)$row->scope, $archivePath);
        }

        return null;
    }

    /**
     * Where one index row's archive is stored locally.
     *
     * The stored name is `<id>-<env>-<scope>` and not the id alone
     * ({@see BackupCreator::archiveBaseName()}), so a row that names no scope cannot be
     * addressed on disk at all - which is a row that predates the field or was hand written,
     * not one owed a copy. The environment needs no such check: an index row always names one.
     *
     * @param BackupHistory $row Index row to locate
     * @param string $root Local storage root
     * @return ?string Absolute archive path, or null when the row names no file
     */
    private function archivePath(BackupHistory $row, string $root): ?string
    {
        $scope = BackupScope::fromString($row->scope);
        if ($scope === null) {
            return null;
        }

        return $root . '/' . $scope->value . '/'
            . BackupCreator::archiveBaseName($row->getId(), $row->env, $scope)
            . BackupHistoryScanner::ARCHIVE_EXTENSION;
    }

    /**
     * The next scope directory owed a mirror pass.
     *
     * Scopes are walked in declaration order and a scope with no local directory is skipped: an
     * empty source would ask rsync to delete the whole remote scope, which is a different
     * operation than mirroring what rotation removed.
     *
     * A scope is owed a pass while it carries no mark, however old the marks of its neighbours
     * are ({@see MIRROR_ATTEMPT_PREFIX}), so the sweep ends after one look per scope no matter
     * how long the link takes over it.
     *
     * @param string $root Local storage root
     * @param array<string, float> $lastAttemptAt When each scope was last mirrored
     * @return ?BackupShipPlan Mirror step, or null when every scope has had its pass
     */
    private function nextMirror(string $root, array $lastAttemptAt): ?BackupShipPlan
    {
        foreach (BackupScope::cases() as $scope) {
            if (isset($lastAttemptAt[self::MIRROR_ATTEMPT_PREFIX . $scope->value])) {
                continue;
            }

            $scopeDir = $root . '/' . $scope->value;
            if (!is_dir($scopeDir)) {
                continue;
            }

            return new BackupShipPlan(BackupShipStep::MIRROR, null, $scope->value, $scopeDir);
        }

        return null;
    }

    /**
     * @param array<string, float> $lastAttemptAt When each key was last attempted
     * @param string $key Backup id whose retry interval is being read
     * @param float $now Current time as a unix timestamp
     * @return bool Whether the retry interval has yet to elapse for this key
     */
    private function tooSoon(array $lastAttemptAt, string $key, float $now): bool
    {
        return isset($lastAttemptAt[$key]) && ($now - $lastAttemptAt[$key]) < self::RETRY_SECONDS;
    }
}
