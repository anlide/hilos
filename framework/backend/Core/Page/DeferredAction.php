<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Auth\Throttle\ThrottleGate;
use Hilos\Core\Action\ActionHostInterface;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * One action held mid-dispatch while the anti-abuse layer decides about it (HIL-420).
 *
 * Everything the dispatcher would have carried on its own stack, kept where a later
 * callback can pick it up: the worker is single-threaded, so waiting for the verdict on the
 * stack would stop every other connection this worker serves. The action resumes into the
 * very same steps it was stopped before - access level, action auth, then the handler - so
 * a parked action is delayed, never treated differently.
 *
 * The parsed payload is what makes this a record and not a signal: a DTO and a live host
 * instance do not cross a process boundary, so only the asking worker can hold them, and
 * only the request key travels ({@see ThrottleGate}).
 */
final class DeferredAction
{
    /**
     * @param ActionHostInterface $host Page or agent whose handler the action is destined for
     * @param string $acceptKey Accept key of the connection that sent it
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Parsed action payload
     * @param ?string $requestId Client-minted request id, or null for an untracked action
     * @param float $deadline Unix seconds after which the action runs whether or not a verdict arrived
     * @param int $awaitingVerdicts Verdicts still outstanding; the action runs when the last allow lands
     */
    public function __construct(
        public readonly ActionHostInterface $host,
        public readonly string $acceptKey,
        public readonly string $action,
        public readonly ActionPayloadDTO $dto,
        public readonly ?string $requestId,
        public readonly float $deadline,
        public int $awaitingVerdicts,
    ) {
    }
}
