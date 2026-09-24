<?php

declare(strict_types=1);

namespace Hilos\Auth\CodeChannel;

use Hilos\Auth\Code\DTO\CodeSendStepSignalData;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt;

/**
 * CodeChannelSend - what a channel answers about a code it was asked to deliver (HIL-492).
 *
 * The outcome of the second step, read off the transport's own response: delivered,
 * or refused. There is no retry arm on purpose. A code has already been minted by
 * the time a send is attempted, and the send gate counts the MINT - so a retry would
 * either race the person's own second attempt or quietly spend a paid message on a
 * code they are no longer looking at. A failed send is reported to the surface, which
 * offers the resend the person can decide to press.
 *
 * {@see detail} is a domain sentence, and since HIL-826 it reaches the person as well as the
 * log - cut to its first line and capped on the way out
 * ({@see CodeSendStepSignalData::step()}). The rule it replaces said the opposite, and said it
 * for a good reason: a stable reason code cannot leak. What changed is what the leaf asks for
 * - the refusal on the code screen must carry the provider's own words rather than "something
 * went wrong", and no stable code of ours can hold words we did not write. The dialogue behind
 * the sentence still stays in the agent log, and the outcome on the line
 * ({@see HilosCodeSendAttempt::$reason}, HIL-1044) still carries nothing but its stable code.
 */
final readonly class CodeChannelSend
{
    /**
     * @param bool $delivered Whether the transport accepted the code for delivery
     * @param ?string $detail Domain failure sentence for the log and the code screen, null when delivered
     */
    private function __construct(
        public bool $delivered,
        public ?string $detail,
    ) {
    }

    /**
     * Builds the outcome of a code the transport took.
     *
     * @return self Delivered send
     */
    public static function delivered(): self
    {
        return new self(true, null);
    }

    /**
     * Builds the outcome of a code the transport refused.
     *
     * @param string $detail Domain failure sentence for the agent log and the code screen
     * @return self Failed send
     */
    public static function failed(string $detail): self
    {
        return new self(false, $detail);
    }
}
