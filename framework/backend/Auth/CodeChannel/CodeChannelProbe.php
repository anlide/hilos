<?php

declare(strict_types=1);

namespace Hilos\Auth\CodeChannel;

/**
 * CodeChannelProbe - what a channel answers when asked whether it can reach a target (HIL-492).
 *
 * The question a code channel is asked BEFORE anything is minted: can this identifier
 * be reached over this channel at all. It exists because the honest answer costs a
 * network round-trip on some channels (a messenger has to be asked whether the number
 * is on it) and nothing at all on others (an SMS gateway reaches any well-formed
 * number), and both have to look the same to the agent driving them.
 *
 * Asking first is not caution, it is what makes the flow correct: an unreachable
 * channel must leave NO trace - no challenge row, no spent cooldown - so the person
 * can pick another channel and still get their first code. It is also free, because
 * the Telegram Gateway charges once per request id and the probe's id is the one the
 * send then reuses.
 *
 * {@see token} is the thread between the two steps: whatever the probe learned that
 * the send must quote back (the Gateway's `request_id`). Channels with no such
 * handle leave it null.
 */
final readonly class CodeChannelProbe
{
    /**
     * @param bool $reachable Whether the identifier can be reached over this channel
     * @param ?string $token Handle the send must quote back, or null when the channel needs none
     */
    private function __construct(
        public bool $reachable,
        public ?string $token,
    ) {
    }

    /**
     * Builds the answer of a target this channel can deliver to.
     *
     * @param ?string $token Handle the send must quote back, or null when the channel needs none
     * @return self Reachable probe
     */
    public static function reachable(?string $token = null): self
    {
        return new self(true, $token);
    }

    /**
     * Builds the answer of a target this channel cannot deliver to.
     *
     * Not a failure of the channel: it is the channel working and saying no. The
     * caller neither retries nor mints, it reports the channel unavailable and lets
     * the person choose another one.
     *
     * @return self Unreachable probe
     */
    public static function unreachable(): self
    {
        return new self(false, null);
    }
}
