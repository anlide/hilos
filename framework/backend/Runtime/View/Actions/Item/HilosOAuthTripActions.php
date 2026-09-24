<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\View\Item\HilosOAuthTrip as ViewHilosOAuthTrip;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Write operations for one provider sign-in a tab is waiting on (HIL-1044).
 *
 * Two moves a trip makes once it is open: it ends, and it changes the connection its outcome is
 * owed to. Both are the session holder's alone.
 *
 * @extends RtActions<ViewHilosOAuthTrip, StateHilosOAuthTrip>
 * @property-read StateHilosOAuthTrip $state
 */
final class HilosOAuthTripActions extends RtActions
{
    /**
     * Records how the trip ended - if nobody has said so yet.
     *
     * The first ending wins, and that is the whole arbitration: a refusal concluded from a dead
     * agent and a success that agent sent a moment earlier can both arrive, and the tab is told
     * exactly one of them. A later one changes nothing and the caller logs it.
     *
     * @param string $ending An {@see OAuthResultSignalData} reason, or one of the two grant endings
     * @param ?string $email Colliding address, on the re-authentication ending alone
     * @param ?string $linkToken Signed link capability, on the re-authentication ending alone
     * @param ?int $userId User a held grant signs in, on {@see StateHilosOAuthTrip::ENDING_GRANTED}
     * @return bool Whether this was the first ending
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function end(string $ending, ?string $email = null, ?string $linkToken = null, ?int $userId = null): bool
    {
        if ($this->state->ending !== null) {
            return false;
        }

        $this->applyDiffWithSync([
            StateHilosOAuthTrip::ending => $ending,
            StateHilosOAuthTrip::email => $email,
            StateHilosOAuthTrip::linkToken => $linkToken,
            StateHilosOAuthTrip::userId => $userId,
            StateHilosOAuthTrip::updatedAt => TimeHelper::nowMs(),
        ]);

        return true;
    }

    /**
     * Records that a held grant has now been applied, on the session row it signed in.
     *
     * The one rewrite of an ending there is, and it is not a second ending: the outcome stays a
     * sign-in, only where it stands moves from "waiting for the tab" to "done". Keeping the
     * session id is what lets a tab whose answer was lost in flight ask again and be handed a
     * fresh ticket for the same row.
     *
     * @param int $sessionId Id of the session row that is signed in now
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function markSignedIn(int $sessionId): void
    {
        $this->applyDiffWithSync([
            StateHilosOAuthTrip::ending => StateHilosOAuthTrip::ENDING_SIGNED_IN,
            StateHilosOAuthTrip::sessionId => $sessionId,
            StateHilosOAuthTrip::updatedAt => TimeHelper::nowMs(),
        ]);
    }

    /**
     * Owes the outcome to another connection: the tab came back on a new socket and proved it.
     *
     * @param string $acceptKey Accept key of the connection that presented the trip's key
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function readdress(string $acceptKey): void
    {
        $this->applyDiffWithSync([
            StateHilosOAuthTrip::acceptKey => $acceptKey,
            StateHilosOAuthTrip::updatedAt => TimeHelper::nowMs(),
        ]);
    }
}
