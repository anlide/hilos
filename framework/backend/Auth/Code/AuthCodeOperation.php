<?php

declare(strict_types=1);

namespace Hilos\Auth\Code;

use Hilos\API\AsyncHttpClient;
use Hilos\Auth\Code\DTO\AuthCodeSendSignalData;
use Hilos\Auth\CodeChannel\CodeChannel;

/**
 * AuthCodeOperation - the mutable per-op state of one in-flight code request (HIL-492).
 *
 * {@see AuthCodeAgent} pipelines many of these at once, pumping each across ticks.
 * It holds the request that started it, the resolved channel, the stage cursor, the
 * current non-blocking client (a fresh one per stage - a client is
 * one-request-per-instance, and probe and send may not even share a path), the handle
 * the probe told the send to quote back, and the op's absolute deadline.
 *
 * The stages are exactly the two questions a channel is asked, in order: can this
 * target be reached, and then - only then - here is a code, deliver it. A channel that
 * answers either question without the network passes through its stage inside one
 * tick and never builds a client.
 */
final class AuthCodeOperation
{
    /** Stage: waiting on the channel's reachability probe. */
    public const int STAGE_PROBE = 1;

    /** Stage: waiting on the channel's delivery of a minted code. */
    public const int STAGE_SEND = 2;

    /** Non-blocking client of the current stage, or null while no request is in flight. */
    public ?AsyncHttpClient $client = null;

    /** Handle the probe returned for the send to quote back, or null when it returned none. */
    public ?string $probeToken = null;

    /**
     * The minted plaintext code, set once the probe passed and the send gate allowed it.
     *
     * It lives here because the mint and the send are two steps and a tick may pass
     * between them. It is never logged and never put on a signal: the only thing it is
     * ever handed to is the channel that delivers it.
     */
    public ?string $code = null;

    /** Server moment the fresh issue's cooldown runs out, in epoch ms, reported on every arm after the mint. */
    public ?int $resendAt = null;

    /**
     * Server moment the code this op left live stops working, in epoch ms, or null while
     * none is live. Read off the challenge rather than off the mint, so the arm that
     * merely reused an earlier code reports that code's remaining life (HIL-486).
     */
    public ?int $expiresAt = null;

    /**
     * Whether this code stands for a REGISTRATION - nobody owns the identifier yet.
     *
     * Decided once, at the probe's settle, and read again when the code has gone out:
     * a registration is what the identifier hold and the durable wait are about, and
     * asking the question twice could answer it differently, since an account can
     * appear on the number in between (HIL-486).
     */
    public bool $registration = false;

    /**
     * @param AuthCodeSendSignalData $request Handed-off request that started this op
     * @param CodeChannel $channel Resolved channel that probes and delivers
     * @param int $stage Current stage ({@see STAGE_PROBE} or {@see STAGE_SEND})
     * @param float $deadlineMs Absolute deadline in milliseconds
     */
    public function __construct(
        public readonly AuthCodeSendSignalData $request,
        public readonly CodeChannel $channel,
        public int $stage,
        public readonly float $deadlineMs,
    ) {
    }

    /**
     * Closes the current stage's socket and drops the client.
     *
     * Dropping it rather than resetting it in place is what makes the next stage's
     * `client === null` mean "nothing in flight", which is the whole of how the tick
     * loop tells a stage that is waiting from one that has yet to start.
     */
    public function closeClient(): void
    {
        $this->client?->reset();
        $this->client = null;
    }
}
