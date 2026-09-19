<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\DTO\PageAccessReassessConnectionsSignalData;
use Hilos\Core\Page\DTO\PageAccessReassessSessionSignalData;
use Hilos\Core\Page\DTO\PageAccessReassessUserSignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;

/**
 * Sends open pages back through the subscribe verdict when the ground under them moved.
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
 * It takes two steps rather than one because the two halves live in different processes.
 * The pages of one person are spread across every worker of the node, while who is behind a
 * connection can only be answered where a browser context is mounted, in a worker. So the
 * writing worker only ANNOUNCES ({@see self::forUser()}), the master fans that announcement
 * out to every worker link, and each worker sweeps its own mirror
 * ({@see self::sweepThisWorker()}).
 *
 * There are three criteria and therefore three announcements, because there are three ways the
 * ground moves. A rights CHANGE names the person whose rights were written; a DOWNGRADE -
 * signing out, an expiry, a force-logout - names the CONNECTIONS instead
 * ({@see self::forConnections()}, {@see self::sweepThisWorkerConnections()}), because the
 * identity the person criterion matches on is precisely what a downgrade removes (HIL-652).
 * A PHASE change of the node names a browser SESSION ({@see self::forSession()}): nothing about
 * the person moved, but what their open pages were answered with did - the verification window
 * opening under the operator's tabs is the case (HIL-911). It has no sweep of its own: the master
 * turns the session into accept keys, and the workers sweep those by the connection criterion.
 *
 * Nothing here judges anything. Who may see what is decided by the same code a subscribe
 * is decided by ({@see PageSignalRouter::dispatchPageAccessReassess}), in the worker that
 * owns the page.
 */
final class PageAccessReassessment
{
    /**
     * Announces that one user's rights changed, for every worker of this node to act on.
     *
     * Queues one signal and returns; it resolves nobody and touches no subscription, so it
     * needs no browser context - the announcing worker is not the one that answers. The
     * announcement is deliberately queued rather than delivered: it leaves the worker behind
     * the database sync of the flag that was just written, and a worker re-deciding ahead of
     * that sync would answer against a stale flag.
     *
     * @param int $userId Durable user id whose rights just changed
     * @throws InvalidArgumentException When the announcement cannot be named
     */
    public static function forUser(int $userId): void
    {
        if (Hilos::$sr === null) {
            return;
        }

        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::PAGE_ACCESS_REASSESS_USER),
            signalName: new SignalName(SignalConstants::PAGE_ACCESS_REASSESS_USER),
            signalData: new PageAccessReassessUserSignalData($userId),
        );
    }

    /**
     * Queues one re-decision frame per live page subscription this worker holds for the user.
     *
     * Identity is read through the existing seam ({@see BrowserContext::connectionIdentity})
     * rather than through a project's own "connections of user X" lookup: the mapping from
     * a connection to a person already has one owner, and a reverse lookup would be a second
     * one to keep in step. A connection whose identity has not crossed the RT sync yet is
     * skipped - it cannot be shown to be this user, and its own subscribe frame is parked
     * until the answer arrives anyway.
     *
     * What it walks is the subscription mirror of the worker it runs in - the same
     * worker-local mirror the browser fan-out uses. Reaching every open page of the person
     * is the announcement's job, not this walk's: each worker of the node runs it once over
     * its own mirror.
     *
     * @param int $userId Durable user id whose rights just changed
     * @throws InvalidArgumentException When a queued re-decision cannot be named
     */
    public static function sweepThisWorker(int $userId): void
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

    /**
     * Announces that named connections lost their person, for every worker of this node.
     *
     * The twin of {@see self::forUser()} in every respect but the criterion, and the criterion
     * is the whole point. A downgrade is the removal of the identity the user criterion matches
     * on: announced after the runtime write that un-points the connections, "the pages of user
     * N" matches nothing, and announced before it, the pages match but are judged with an
     * identity about to be destroyed - so the sweep would answer "allow" and re-send the
     * privileged page, which is worse than doing nothing. An accept key names the same socket
     * on both sides of that write.
     *
     * An empty list announces nothing rather than announcing "no connections": a session with
     * no live socket on this node has no open page to re-judge.
     *
     * @param list<string> $acceptKeys Accept keys of the connections that just lost their person
     * @throws InvalidArgumentException When the announcement cannot be named
     */
    public static function forConnections(array $acceptKeys): void
    {
        if (Hilos::$sr === null || $acceptKeys === []) {
            return;
        }

        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::PAGE_ACCESS_REASSESS_CONNECTIONS),
            signalName: new SignalName(SignalConstants::PAGE_ACCESS_REASSESS_CONNECTIONS),
            signalData: new PageAccessReassessConnectionsSignalData($acceptKeys),
        );
    }

    /**
     * Queues one re-decision frame per announced connection this worker actually holds.
     *
     * The twin of {@see self::sweepThisWorker()}, and deliberately blind where that one looks:
     * it never asks {@see BrowserContext::connectionIdentity} who is behind a connection and
     * needs no browser context at all, because the criterion IS the accept key. That is exactly
     * why this criterion survives the write that erased the identity - there is nothing left to
     * ask about, and asking would answer "nobody" for every key in the list.
     *
     * A key this worker holds no subscription under is matched by nobody and costs nothing: the
     * announcement reaches every worker of the node, while what it is intersected with here is
     * this worker's own subscription mirror - the bookkeeping written where the subscribe was
     * dispatched, which is not the same thing as owning the socket (the master does that).
     *
     * @param list<string> $acceptKeys Accept keys of the connections that just lost their person
     * @throws InvalidArgumentException When a queued re-decision cannot be named
     */
    public static function sweepThisWorkerConnections(array $acceptKeys): void
    {
        if (Hilos::$sr === null) {
            return;
        }

        $subscriptions = Hilos::$sr->getPageSubscriptions();
        foreach ($acceptKeys as $acceptKey) {
            $subscription = $subscriptions[$acceptKey] ?? null;
            if ($subscription === null) {
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

    /**
     * Announces that one browser session's open pages were answered against a phase that moved.
     *
     * The third criterion, and the one only the master can resolve: which sockets carry a session
     * is known where the sockets are accepted, so this is queued by the daemon and consumed by the
     * daemon, which hands every worker the by-connection announcement for the accept keys it found
     * ({@see self::forConnections()} explains why that criterion is the one a worker can answer
     * blind). Queued rather than resolved on the spot for the reason {@see self::forUser()} gives:
     * the runtime write of the phase rides the same queue ahead of it, so every worker re-judges
     * against the phase it has just been told about.
     *
     * The callers are the two ways into the verification window: the protected-mode executor
     * opening it for the operator and the circle (HIL-911, HIL-912), and the admission of a browser
     * that presented a pass (HIL-912). Their tabs were answered while the phase was still inactive,
     * and nothing else would answer them again: the backup page's reopen section is built only when
     * a subscription is answered. A tab opened under the freeze is not among them - the client
     * refused its subscribe, so there is nothing here to re-judge, and it subscribes on the frame
     * that lets it in.
     *
     * @param string $sessionTokenHash Hash of the session token whose open pages are to be re-judged
     * @throws InvalidArgumentException When the announcement cannot be named
     */
    public static function forSession(string $sessionTokenHash): void
    {
        if (Hilos::$sr === null) {
            return;
        }

        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::DAEMON),
            signalType: new SignalType(SignalTypeConstants::PAGE_ACCESS_REASSESS_SESSION),
            signalName: new SignalName(SignalConstants::PAGE_ACCESS_REASSESS_SESSION),
            signalData: new PageAccessReassessSessionSignalData($sessionTokenHash),
        );
    }
}
