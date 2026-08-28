<?php

declare(strict_types=1);

namespace Hilos\Notification;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Notification\DTO\NotificationEmitSignalData;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;

/**
 * HilosNotifier - the emit seam of the durable notification model (HIL-102, HIL-771).
 *
 * The facade global {@see Hilos::$notify}, and since HIL-771 a DOOR rather than a writer:
 * {@see emit()} hands the draft to {@see AbstractNotificationsLibraryAgent}, which owns the
 * notification tables, and the row, the live in-app fan and the channel dispatch all happen
 * there.
 *
 * It used to write the row from the calling worker, described as "any worker can call it
 * directly, no owner agent". That held only while the process that called it also happened to
 * host an agent claiming those tables - true by accident of agent placement, false in every
 * other worker - which is the defect the library closes. What a caller loses is the id: emit
 * no longer answers, because the row is written in another process and no product path read
 * the value anyway.
 *
 * Nothing is left beside {@see emit()}. The admin retry was the last writer here and it went
 * the same way: the ADMIN page that submits it now forwards the row to the library too, so the
 * dispatcher has no caller outside the process that owns the journal.
 */
class HilosNotifier
{
    /**
     * Asks the notifications library to persist and deliver one notification.
     *
     * Fire-and-forget by construction, and best-effort in exactly one way: with no signal
     * router in the process (a CLI context) the frame reaches nobody. That is not a silent
     * loss on the paths that matter - every worker has a router - but it is why the two
     * places that emit with the node frozen or the daemon down queue their drafts instead of
     * calling here.
     *
     * @param NotificationDraft $draft The notification to persist and deliver
     * @throws InvalidArgumentException When the emit signal cannot be named or queued
     */
    public function emit(NotificationDraft $draft): void
    {
        Hilos::$sr?->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            signalName: new SignalName(HilosSignalConstants::HILOS_NOTIFICATION_EMIT),
            signalData: new AgentSignalData(data: NotificationEmitSignalData::fromDraft($draft)),
        );
    }
}
