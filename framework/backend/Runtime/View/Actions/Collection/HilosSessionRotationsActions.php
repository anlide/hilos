<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Constants\TimeConstants;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosSessionRotations as StateHilosSessionRotations;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\View\Collection\HilosSessionRotations;
use Hilos\Runtime\View\Item\HilosSessionRotation;

/**
 * Write API for the pending token rotations (HIL-582).
 *
 * Three writes and no more, because a rotation has exactly three moments: it is announced
 * ({@see register()}), it is spent ({@see forget()}), or its moment passed and it is swept
 * ({@see forgetExpired()}). Nothing edits a rotation in place - the token in the row is the
 * one the browser will be given, and a row that could be rewritten between the announcement
 * and the handshake would be a second way to move a session onto a token of someone's
 * choosing, which is the very thing being closed.
 *
 * @extends RtActions<HilosSessionRotation, HilosSessionRotations, StateHilosSessionRotations>
 * @property-read StateHilosSessionRotations $stateCollection
 */
final class HilosSessionRotationsActions extends RtActions
{
    /**
     * Announces a pending rotation the master may trade the ticket for.
     *
     * @param string $ticket One-time ticket sent to the initiating connection
     * @param string $sessionToken Session token the bearer receives on its next handshake
     * @param list<string> $acceptKeysToDrop Accept keys of the session's other connections
     * @param float $expiresAtMs Unix milliseconds after which the ticket is refused
     * @param ?string $pendingAck Ack the initiating connection still owes, carried to the socket that presents the ticket
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws HilosException Whatever a subscriber to the collection's announcement raises
     */
    public function register(
        string $ticket,
        string $sessionToken,
        array $acceptKeysToDrop,
        float $expiresAtMs,
        ?string $pendingAck = null,
    ): void {
        $this->ensureCanWrite();

        $this->addStateToCollection(
            StateHilosSessionRotation::create($ticket, $sessionToken, $acceptKeysToDrop, $expiresAtMs, $pendingAck),
        );
    }

    /**
     * Burns one rotation, whether or not it was there.
     *
     * Called by the master the instant it has traded the ticket, so the value is good for a
     * single handshake even inside its lifetime. The silent no-op on a missing row is the
     * point rather than a convenience: two handshakes racing to spend the same ticket must
     * both end with the row gone and neither with an error.
     *
     * @param string $ticket One-time ticket to burn
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws HilosException Whatever a subscriber to the collection's announcement raises
     */
    public function forget(string $ticket): void
    {
        $this->ensureCanWrite();

        if ($this->stateCollection->get($ticket) === null) {
            return;
        }

        $this->removeStateFromCollection($ticket);
    }

    /**
     * Drops every rotation whose moment has passed.
     *
     * Run from the owning agent's tick. Without it the collection only ever shrinks when a
     * ticket is spent, and the ones that are not - a tab closed between the login and the
     * reconnect - would accumulate for the life of the process. Expired rows are already
     * refused on the handshake, so this reclaims memory rather than closing a hole.
     *
     * @return int Number of rotations dropped
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws HilosException Whatever a subscriber to the collection's announcement raises
     */
    public function forgetExpired(): int
    {
        $this->ensureCanWrite();

        $nowMs = microtime(true) * TimeConstants::MS_PER_SECOND;

        // Collect first: removing a row mutates the collection the loop walks.
        $expired = [];
        foreach ($this->stateCollection as $state) {
            if (!$state->isLiveAt($nowMs)) {
                $expired[] = $state->getId();
            }
        }

        foreach ($expired as $ticket) {
            $this->removeStateFromCollection($ticket);
        }

        return count($expired);
    }
}
