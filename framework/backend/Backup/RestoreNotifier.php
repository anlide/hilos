<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Auth\Session\SessionCarrier;
use Hilos\Auth\Session\SessionIdentityRef;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Hilos;
use Hilos\Notification\DeferredNotificationQueue;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Users\AdminAudience;
use Hilos\Utils\Logger;
use Throwable;

/**
 * RestoreNotifier - announces how a restore ended to the people who administer the node (HIL-279).
 *
 * One terminal notification per run, raised by both entrances to the engine: the agent's hot path
 * ({@see BackupAgent}) and the cold CLI path that runs with the daemon down. The two paths differ
 * only in what they can say about the initiator, so the composing lives here and neither writes a
 * draft of its own.
 *
 * **Recipients are read from the restored database**, because after the swap there is no other
 * one: {@see AdminAudience} answers who administers the installation, and the initiator is added
 * beside them - they are waiting for the answer and need not be an administrator of the database
 * that just landed.
 *
 * **The initiator is carried across as identities, never as a user id.** The same numeric id in
 * the archive of another installation belongs to a different human being, so the id from before
 * the swap would address a stranger. The caller photographs the initiator's (type, identifier)
 * pairs before the import and hands them here; they are looked up in the restored database
 * afterwards, exactly as sessions are carried over ({@see SessionCarrier}). Not found means the
 * person does not exist in this database, and they simply drop out of the recipients.
 *
 * **Nothing is sent from here** (HIL-771). Both entrances write where nobody can be reached - the
 * hot one under the restore freeze, with every agent but the initiator stopped, the cold one with
 * the daemon down - so the finished draft is left in {@see DeferredNotificationQueue} and the
 * notifications library sends it when it next starts. Emitting straight to the seam would drop the
 * letter on both paths, which is the whole reason the queue exists.
 *
 * Best-effort throughout: a restore that has already happened is not undone by a notification
 * that could not be written, and the half-broken database a failed import leaves behind is
 * precisely where the write may fail.
 */
final class RestoreNotifier
{
    /** Agent id the announcement's own failures are logged under. */
    private const string LOG_AGENT_ID = 'backup-restore-notifier';

    /** Longest failure reason carried into the notification, in characters. */
    private const int DETAIL_LIMIT = 200;

    /** Appended to the failure reason when it cut the line short. */
    private const string DETAIL_ELLIPSIS = '…';

    /** Where the reader is sent for everything the one line had to leave out. */
    private const string DETAILS_HINT = 'Details are on the backups page.';

    /**
     * Announces the outcome of one restore run to the administrators and the initiator.
     *
     * @param string $backupId Id of the archive that was replayed
     * @param BackupScope $scope Scope the archive was captured under
     * @param bool $success Whether the run came back whole (child succeeded and barrier closed)
     * @param ?string $failureDetail Raw failure detail when it did not, or null
     * @param string $startedAt SQL datetime the run started
     * @param int $durationSeconds How long the run took, in seconds
     * @param bool $rehydrateComplete Whether every process confirmed re-reading the replaced database
     * @param list<SessionIdentityRef> $initiatorIdentities Initiator identities photographed before the swap
     */
    public function notifyOutcome(
        string $backupId,
        BackupScope $scope,
        bool $success,
        ?string $failureDetail,
        string $startedAt,
        int $durationSeconds,
        bool $rehydrateComplete,
        array $initiatorIdentities,
    ): void {
        try {
            $this->emitOutcome(
                $backupId,
                $scope,
                $success,
                $failureDetail,
                $startedAt,
                $durationSeconds,
                $rehydrateComplete,
                $initiatorIdentities,
            );
        } catch (Throwable $e) {
            Logger::logAgentError(
                self::LOG_AGENT_ID,
                "Restore {$backupId} could not be announced: {$e->getMessage()}",
            );
        }
    }

    /**
     * Reduces a raw failure detail to the single line a notification carries.
     *
     * A child's stderr can be a wall of text with paths and table names in it, while what goes out
     * over an external SMTP into other people's mailboxes has to be one sentence; the whole detail
     * stays in the agent log and on the run's row in the admin. Pure so the wording and the cap are
     * unit-testable, and separate from {@see BackupAgent::failureNotice()} on purpose - that one
     * words a failed create, and sharing it would mean either notification carrying the other's
     * sentence the day one of them is reworded.
     *
     * @param string $detail Raw failure detail
     * @return string First line of the detail, capped; empty when the detail says nothing
     */
    public static function failureLine(string $detail): string
    {
        $firstLine = trim(explode("\n", $detail, 2)[0]);
        if (mb_strlen($firstLine) > self::DETAIL_LIMIT) {
            $firstLine = mb_substr($firstLine, 0, self::DETAIL_LIMIT - 1) . self::DETAIL_ELLIPSIS;
        }

        return $firstLine;
    }

    /**
     * Composes the draft and emits it once per recipient.
     *
     * @param string $backupId Id of the archive that was replayed
     * @param BackupScope $scope Scope the archive was captured under
     * @param bool $success Whether the run came back whole
     * @param ?string $failureDetail Raw failure detail when it did not, or null
     * @param string $startedAt SQL datetime the run started
     * @param int $durationSeconds How long the run took, in seconds
     * @param bool $rehydrateComplete Whether every process confirmed re-reading the replaced database
     * @param list<SessionIdentityRef> $initiatorIdentities Initiator identities photographed before the swap
     */
    private function emitOutcome(
        string $backupId,
        BackupScope $scope,
        bool $success,
        ?string $failureDetail,
        string $startedAt,
        int $durationSeconds,
        bool $rehydrateComplete,
        array $initiatorIdentities,
    ): void {
        $recipients = $this->recipients($initiatorIdentities);
        if ($recipients === []) {
            Logger::logAgentInfo(
                self::LOG_AGENT_ID,
                "Restore {$backupId} has nobody to announce to: this installation declares no administrators",
            );

            return;
        }

        $failureSummary = $this->failureSummary($success, $failureDetail);
        $title = $success ? 'Restore completed' : 'Restore failed';
        $body = match (true) {
            $success => "Backup {$backupId} ({$scope->value}) was restored in {$durationSeconds}s.",
            $failureSummary === null => self::DETAILS_HINT,
            default => $failureSummary . ' ' . self::DETAILS_HINT,
        };
        $data = [
            'backupId' => $backupId,
            'scope' => $scope->value,
            'outcome' => $success ? 'succeeded' : 'failed',
            'startedAt' => $startedAt,
            'durationSeconds' => $durationSeconds,
            'initiatedBy' => $initiatorIdentities === [] ? 'cli' : 'ui',
            'rehydrateComplete' => $rehydrateComplete,
            'failureSummary' => $failureSummary,
        ];

        foreach ($recipients as $userId) {
            // Queued rather than emitted, because this line is written where the door has nobody
            // behind it (HIL-771): under the freeze every agent but the initiator is stopped, and
            // on the cold CLI path the daemon is down entirely. The notifications library sends
            // them when it next starts.
            DeferredNotificationQueue::defer(new NotificationDraft(
                userId: $userId,
                type: $success ? BackupNotificationType::RESTORE_SUCCEEDED : BackupNotificationType::RESTORE_FAILED,
                title: $title,
                severity: $success ? NotificationSeverity::SUCCESS : NotificationSeverity::ERROR,
                body: $body,
                data: $data,
            ));
        }
    }

    /**
     * Reduces a run's failure to the one line the notification repeats, or to nothing.
     *
     * Nothing is a real answer twice over: a successful run has no failure, and a failed one can
     * arrive with a detail that says nothing once its first line is taken. Both leave the reader
     * with the page to look at, which is where the whole story is anyway.
     *
     * @param bool $success Whether the run came back whole
     * @param ?string $failureDetail Raw failure detail when it did not, or null
     * @return ?string One-line summary, or null when there is nothing to say
     */
    private function failureSummary(bool $success, ?string $failureDetail): ?string
    {
        if ($success || $failureDetail === null) {
            return null;
        }

        $line = self::failureLine($failureDetail);

        return $line === '' ? null : $line;
    }

    /**
     * Names everyone the outcome goes to: the administrators, then the initiator.
     *
     * The order is the point of the deduplication - an administrator who started the restore
     * themselves keeps their place in the audience instead of being appended twice.
     *
     * @param list<SessionIdentityRef> $initiatorIdentities Initiator identities photographed before the swap
     * @return list<int> Recipient user ids in the restored database, each appearing once
     */
    private function recipients(array $initiatorIdentities): array
    {
        $userIds = Hilos::adminAudienceClass()::all();

        $initiator = $this->resolveInitiator($initiatorIdentities);
        if ($initiator !== null) {
            $userIds[] = $initiator;
        }

        return array_values(array_unique($userIds));
    }

    /**
     * Finds the initiator in the restored database by the identities photographed before the swap.
     *
     * The first pair that resolves wins: the pairs all belong to one person, so a second lookup
     * would only cost a query to confirm what the first one said.
     *
     * @param list<SessionIdentityRef> $initiatorIdentities Initiator identities photographed before the swap
     * @return ?int Initiator's user id in the restored database, or null when they are not in it
     */
    private function resolveInitiator(array $initiatorIdentities): ?int
    {
        foreach ($initiatorIdentities as $reference) {
            $userId = Hilos::$db->identities->findByIdentity($reference->type, $reference->identifier)?->userId;
            if ($userId !== null) {
                return $userId;
            }
        }

        return null;
    }
}
