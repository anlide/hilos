<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DeferredSessionCarryoverQueue;
use Hilos\Auth\Session\DTO\DeferredSessionCarryoverHandoverSignalData;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\DTO\DeferredNoticesSentSignalData;
use Hilos\Backup\Agent\DTO\DeferredSessionsCarriedSignalData;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Hilos;
use Hilos\Notification\DeferredNotificationQueue;
use Hilos\Notification\DTO\DeferredNotificationHandoverSignalData;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Utils\Logger;

/**
 * DeferredQueueHandover - the holder's side of the two deferred restore queues (HIL-846).
 *
 * A restore leaves two things for owners it cannot ask at that moment: the logins it photographed
 * ({@see DeferredSessionCarryoverQueue}) and the letter about its outcome
 * ({@see DeferredNotificationQueue}). Both files stay in the backup directory of the node that
 * wrote them. Each owner used to read its file off its own disk as it started, and in a cluster that
 * is another disk whenever the placement policy put the owner on another node - a loss nobody saw,
 * because an empty queue is the ordinary one.
 *
 * So the agent that writes the files holds them too ({@see BackupAgent}), and this is its side: each
 * batch is offered to its owner as an agent signal ({@see AbstractSessionsLibraryAgent},
 * {@see AbstractNotificationsLibraryAgent}), once a second, until the owner's receipt names it.
 * Signals already cross nodes, so what changed is only that nobody reads a file that may not be on
 * their disk. The holder offers rather than waiting to be asked because an owner may be up already:
 * a cold restore from the command line, or a node coming back to the cluster, gives it no second
 * start to ask from.
 *
 * **A receipt closes a batch; an offer does not.** A batch nobody answered - its owner stopped by the
 * freeze, not placed yet, or answering into a frame that was lost - is offered again on the next
 * pass, and only a receipt removes its file. Delivery is at least once, and both owners survive the
 * repeat: a login that already holds a row is neither carried nor lost, and for a letter about a
 * restore a duplicate is the smaller harm than silence.
 *
 * **Absent and unreachable are two answers.** A project in which no agent declares a hand-over name
 * has no owner to wait for: the batch stays on disk, one line says so, and this process stops
 * offering that queue - otherwise every pass would send a name nothing receives. An owner that is
 * declared but not placed yet is only unreachable, and the next pass is the cure.
 *
 * **Holding too long is said once.** A batch still unanswered past a threshold is worth one line,
 * and the line is armed again only after the queue has been seen empty.
 *
 * Kept out of the agent so the mechanism runs without one: whatever it sends goes through
 * {@see DeferredQueueHandoverSink}, and a test hands it a fake.
 */
final class DeferredQueueHandover
{
    /** @var float Minimum seconds between two passes over the queues, and so between two offers of one batch */
    private const float OFFER_INTERVAL_SECONDS = 1.0;

    /**
     * @var float Seconds a batch may wait for its receipt before the wait is worth a line. An ordinary
     *     hand-over is answered well inside the ten seconds a lift waits for the logins, so a minute
     *     unanswered means the owner is not answering at all rather than answering slowly.
     */
    private const float HELD_BATCH_COMPLAINT_SECONDS = 60.0;

    /** @var string Agent id the holder's lines are filed under: the id of the agent it runs inside */
    private const string LOG_AGENT_ID = HilosAgentType::HILOS_BACKUP;

    /** @var float Timestamp of the last pass, for throttling */
    private float $lastPassAt = 0.0;

    /** @var array<string, string> Id of the batch in flight, by queue name */
    private array $heldBatches = [];

    /** @var array<string, float> Timestamp the batch in flight was first offered at by this process, by queue name */
    private array $heldSince = [];

    /** @var array<string, true> Queues whose long wait has been complained about since they were last empty, by queue name */
    private array $complainedAbout = [];

    /** @var array<string, true> Queues no agent of this project receives, by queue name */
    private array $unreceived = [];

    /**
     * @param DeferredQueueHandoverSink $sink Where the hand-over frames are sent
     */
    public function __construct(
        private readonly DeferredQueueHandoverSink $sink,
    ) {
    }

    /**
     * Offers each queue's batch to its owner, at most once a second.
     *
     * The two queues do not wait for each other: a letter whose library is slow to answer holds back
     * no login, and a queue nothing receives silences only itself.
     *
     * @param float $now Current time in seconds, as microtime(true) reads it
     * @throws InvalidArgumentException When the sink cannot name a hand-over frame
     */
    public function tick(float $now): void
    {
        if ($now - $this->lastPassAt < self::OFFER_INTERVAL_SECONDS) {
            return;
        }
        $this->lastPassAt = $now;

        $this->offerSessions($now);
        $this->offerNotifications($now);
    }

    /**
     * Closes the session batch the sessions library answered for.
     *
     * Only the file of the named batch goes: a receipt that arrives late for a batch already closed
     * removes nothing, and above all not the batch that followed it.
     *
     * @param DeferredSessionsCarriedSignalData $receipt The library's receipt
     */
    public function onSessionsCarried(DeferredSessionsCarriedSignalData $receipt): void
    {
        DeferredSessionCarryoverQueue::release($receipt->batch);
    }

    /**
     * Closes the notice batch the notifications library answered for, on the same terms as
     * {@see onSessionsCarried()}.
     *
     * @param DeferredNoticesSentSignalData $receipt The library's receipt
     */
    public function onNoticesSent(DeferredNoticesSentSignalData $receipt): void
    {
        DeferredNotificationQueue::release($receipt->batch);
    }

    /**
     * Offers the session batch in flight, or the fresh file as a new batch, to the sessions library.
     *
     * @param float $now Current time in seconds
     * @throws InvalidArgumentException When the sink cannot name the hand-over frame
     */
    private function offerSessions(float $now): void
    {
        $queue = DeferredRestoreQueue::Sessions;
        if (isset($this->unreceived[$queue->name])) {
            return;
        }

        $batch = DeferredSessionCarryoverQueue::take();
        if ($batch === null) {
            $this->forgetHeld($queue);

            return;
        }

        if (!self::isReceived(HilosSignalConstants::HILOS_SESSION_CARRYOVER_HANDOVER)) {
            $this->stopOffering($queue, HilosSignalConstants::HILOS_SESSION_CARRYOVER_HANDOVER, $batch->batch);

            return;
        }

        $this->noteHeld($queue, $batch->batch, $now);
        $this->sink->handOverDeferredQueue(
            HilosSignalConstants::HILOS_SESSION_CARRYOVER_HANDOVER,
            new DeferredSessionCarryoverHandoverSignalData($batch->batch, $batch->sessions),
        );
    }

    /**
     * Offers the notice batch in flight, or the fresh file as a new batch, to the notifications library.
     *
     * @param float $now Current time in seconds
     * @throws InvalidArgumentException When the sink cannot name the hand-over frame
     */
    private function offerNotifications(float $now): void
    {
        $queue = DeferredRestoreQueue::Notifications;
        if (isset($this->unreceived[$queue->name])) {
            return;
        }

        $batch = DeferredNotificationQueue::take();
        if ($batch === null) {
            $this->forgetHeld($queue);

            return;
        }

        if (!self::isReceived(HilosSignalConstants::HILOS_NOTIFICATION_HANDOVER)) {
            $this->stopOffering($queue, HilosSignalConstants::HILOS_NOTIFICATION_HANDOVER, $batch->batch);

            return;
        }

        $this->noteHeld($queue, $batch->batch, $now);
        $this->sink->handOverDeferredQueue(
            HilosSignalConstants::HILOS_NOTIFICATION_HANDOVER,
            new DeferredNotificationHandoverSignalData($batch->batch, $batch->drafts),
        );
    }

    /**
     * Remembers the batch being offered, and says once that it has waited too long for its receipt.
     *
     * A batch that follows a released one starts a wait of its own but does not arm the line again:
     * only a queue seen empty does ({@see forgetHeld()}).
     *
     * @param DeferredRestoreQueue $queue Queue the batch belongs to
     * @param string $batch Id of the batch being offered
     * @param float $now Current time in seconds
     */
    private function noteHeld(DeferredRestoreQueue $queue, string $batch, float $now): void
    {
        $key = $queue->name;
        if (($this->heldBatches[$key] ?? null) !== $batch) {
            $this->heldBatches[$key] = $batch;
            $this->heldSince[$key] = $now;

            return;
        }

        $waited = $now - $this->heldSince[$key];
        if (isset($this->complainedAbout[$key]) || $waited < self::HELD_BATCH_COMPLAINT_SECONDS) {
            return;
        }

        $this->complainedAbout[$key] = true;
        $seconds = (int)$waited;
        Logger::logAgentWarning(
            self::LOG_AGENT_ID,
            "The {$queue->label()} batch {$batch} has waited {$seconds} s for its owner to answer",
        );
    }

    /**
     * Forgets the batch of a queue seen empty, and arms its long-wait line again.
     *
     * @param DeferredRestoreQueue $queue Queue seen empty
     */
    private function forgetHeld(DeferredRestoreQueue $queue): void
    {
        unset($this->heldBatches[$queue->name], $this->heldSince[$queue->name], $this->complainedAbout[$queue->name]);
    }

    /**
     * Stops offering a queue no agent of this project receives, saying so once.
     *
     * The batch is left where it is, as the archives beside it are: a process started after the
     * project declares the owner finds it and offers it.
     *
     * @param DeferredRestoreQueue $queue Queue to stop offering
     * @param string $signalName Hand-over name no agent declares
     * @param string $batch Id of the batch left on disk
     */
    private function stopOffering(DeferredRestoreQueue $queue, string $signalName, string $batch): void
    {
        $this->unreceived[$queue->name] = true;
        Logger::logAgentWarning(
            self::LOG_AGENT_ID,
            "The {$queue->label()} batch {$batch} stays on disk: no agent of this project receives {$signalName}",
        );
    }

    /**
     * Whether an agent of the running project declares a hand-over name.
     *
     * Asked of the project's own topology, the declaration routing reads, so a name missing here
     * is a frame that would be dropped unrouted.
     *
     * @param string $signalName Hand-over signal name
     * @return bool True when an agent type declares the name
     */
    private static function isReceived(string $signalName): bool
    {
        return isset(Hilos::appClass()::getAgentSignalRoutes()[$signalName]);
    }
}
