<?php

declare(strict_types=1);

namespace Hilos\Sms;

/**
 * SmsTruncation - the outcome of fitting a rendered body to the segment budget (HIL-285).
 *
 * The result of {@see SmsText::truncate()}: the body clamped to one segment and whether a
 * clamp actually happened. The channel agent keeps {@see text} for the send and logs
 * {@see truncated} (with the masked number and template key, never the body) when true, so
 * an over-long message is delivered clamped rather than dropped and the clamp is auditable.
 */
final class SmsTruncation
{
    /**
     * @param string $text Body clamped to the segment budget
     * @param bool $truncated Whether the body was clamped (over the budget) or passed through
     */
    public function __construct(
        public readonly string $text,
        public readonly bool $truncated,
    ) {
    }
}
