<?php

declare(strict_types=1);

namespace Hilos\Core\Group;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Group\DTO\GroupLeaveAllSignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Runtime\View\Actions\Item\HilosConnectionActions;

/**
 * GroupMembership - the framework rule that a group membership does not outlive the person behind a connection (HIL-1284).
 *
 * One rule rather than a concern of every group: a group addressed by a person was copied
 * without a way out more than once, and the next one would be too. So the rule hangs on the
 * change of person ({@see HilosConnectionActions::bindUser()}) and not on any group, and it
 * ends EVERY membership the connection held - a group needs to declare nothing to be covered.
 * The connection is let back in by whoever lets it in today: the bell by the client, the
 * profile sections by the page's answer for the new person.
 */
final class GroupMembership
{
    /**
     * Ends every group membership of one connection, everywhere it was written.
     *
     * The mirror of this worker is cleared at once; the master and, through it, every other
     * worker and every other node learn it from the `group_leave_all` frame. The frame is queued
     * now rather than after the commit: it has to leave ahead of the handshake answer of the same
     * session frame and of every join queued after it, and a rolled-back change of person costs
     * no more than a second join.
     *
     * @param string $acceptKey Connection whose person changed
     * @throws InvalidArgumentException When the leave-all announcement cannot be named
     */
    public static function leaveAll(string $acceptKey): void
    {
        if ($acceptKey === '') {
            return;
        }

        Hilos::$sr?->unsubscribeFromAllGroups($acceptKey);
        Hilos::$sr?->queueSignal(
            signalSource: new SignalSource(SignalSource::RT),
            signalType: new SignalType(SignalTypeConstants::GROUP_LEAVE_ALL),
            signalName: new SignalName(SignalTypeConstants::GROUP_LEAVE_ALL),
            signalData: new GroupLeaveAllSignalData([$acceptKey]),
        );
    }
}
