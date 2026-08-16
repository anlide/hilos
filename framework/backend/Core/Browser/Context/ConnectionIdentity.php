<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Context;

use Hilos\Core\Page\PageSignalRouter;

/**
 * ConnectionIdentity - what this worker knows about who is behind one connection (HIL-599).
 *
 * A connection is identified in the worker that owns the WebSocket lifecycle and judged in
 * whatever worker serves its page, so between the two there is a moment when the judging
 * worker has no answer yet - the runtime row is still crossing the RT sync. A plain `?int`
 * cannot say that: its `null` reads as "a guest", and the two call for opposite decisions -
 * a guest is refused now, an unfinished answer is waited for
 * ({@see PageSignalRouter} parks the frame instead of judging it).
 *
 * So the seam answers three states, not two: this user, no user at all, or not yet known.
 * The state is read from the project's own connection registry and never guessed from
 * anything approximate - the age of a connection tells nothing about whether its row
 * arrived ({@see BrowserContext::resolveConnectionIdentity}).
 */
final class ConnectionIdentity
{
    /**
     * @param bool $pending Whether this worker is still waiting to learn who is behind the connection
     * @param ?int $userId Durable user id behind the connection; null for a guest and for a pending answer
     */
    public function __construct(
        public readonly bool $pending,
        public readonly ?int $userId = null,
    ) {
    }

    /**
     * Builds the "not yet known" state: the connection's row has not reached this worker.
     *
     * @return self Pending identity, carrying no user
     */
    public static function pending(): self
    {
        return new self(true);
    }

    /**
     * Builds a settled answer: this user, or nobody at all.
     *
     * @param ?int $userId Durable user id behind the connection, or null when it is a guest
     * @return self Settled identity
     */
    public static function resolved(?int $userId): self
    {
        return new self(false, $userId);
    }
}
