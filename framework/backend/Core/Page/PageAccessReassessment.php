<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;

/**
 * Sends every open page of one user back through the subscribe verdict (HIL-621).
 *
 * The server half of "a rights change reaches the open tab". The access verdict is
 * reached once, when a page is subscribed, and afterwards only re-checked as a gate on
 * delivery - so a revoke leaves privileged content standing on screen and a grant leaves
 * a 403 standing there, both until the person reloads. This sweep is what ends that: it
 * re-asks the question for every live subscription the user holds, and the answer travels
 * as the subscribe answer it would have been.
 *
 * The sweep is started where the rights are WRITTEN, not on a tick: a re-decision costs a
 * full page answer per open tab, and paying it on every tick would buy the one case
 * nobody announces - a flag written straight into the database - at the price of paying
 * always. The announcement is part of the grant operation, exactly as the handshake
 * re-send already is.
 *
 * Nothing here judges anything. Who may see what is decided by the same code a subscribe
 * is decided by ({@see PageSignalRouter::dispatchPageAccessReassess}), in the worker that
 * owns the page.
 */
final class PageAccessReassessment
{
    /**
     * Queues one re-decision frame per live page subscription held by this user.
     *
     * Identity is read through the existing seam ({@see BrowserContext::connectionIdentity})
     * rather than through a project's own "connections of user X" lookup: the mapping from
     * a connection to a person already has one owner, and a reverse lookup would be a second
     * one to keep in step. A connection whose identity has not crossed the RT sync yet is
     * skipped - it cannot be shown to be this user, and its own subscribe frame is parked
     * until the answer arrives anyway.
     *
     * What it walks is the subscription mirror of the worker it runs in - the same
     * worker-local mirror the browser fan-out uses - so it reaches the pages served by
     * agents in that worker. That is why the call belongs beside the write: the agent
     * that owns the rights is the one whose worker serves the pages those rights govern.
     *
     * @param int $userId Durable user id whose rights just changed
     * @throws InvalidArgumentException When a queued re-decision cannot be named
     */
    public static function forUser(int $userId): void
    {
        if (Hilos::$sr === null || Hilos::$browser === null) {
            return;
        }

        foreach (Hilos::$sr->getPageSubscriptions() as $acceptKey => $subscription) {
            $identity = Hilos::$browser->connectionIdentity($acceptKey);
            if ($identity->pending || $identity->userId !== $userId) {
                continue;
            }

            $page = $subscription[SignalPayloadConstants::SUBSCRIPTION_PAGE_KEY];
            Hilos::$sr->queueSignal(
                signalSource: new SignalSource(SignalSource::WORKER),
                signalType: new SignalType(SignalTypeConstants::PAGE_ACCESS_REASSESS),
                signalName: new SignalName($page),
                signalData: new WebSocketPageSubscribeSignalDTO(
                    acceptKey: $acceptKey,
                    page: $page,
                    params: $subscription[SignalPayloadConstants::SUBSCRIPTION_PARAMS_KEY],
                ),
            );
        }
    }
}
