<?php

declare(strict_types=1);

namespace Hilos\Mail\Delivery;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Mail\EmailMessage;

/**
 * RawMailSend - the mutable state of one queued raw-send op (HIL-197).
 *
 * Agent-internal bookkeeping for input B ({@see MailDeliveryChannelAgent}): a raw send
 * ({@see HilosSignalConstants::HILOS_MAIL_SEND}, Auth codes and magic links) has no
 * durable delivery row, so its message, attempt count, in-flight attempt, and retry
 * schedule live here in memory only. The template key is kept for the failure log —
 * the message body and template params are never logged. Not part of any contract; the
 * agent owns and drops these.
 *
 * The progress ticket is kept for the same reason and used for the opposite one (HIL-826):
 * it is the only thing this subsystem knows about who ordered the letter, and it exists so
 * that every step of the send can be reported back without the mail agent ever learning whose
 * code screen is waiting. Null on a letter nobody is watching, which is most of them.
 */
final class RawMailSend
{
    /** Number of send attempts started so far (for the retry ceiling). */
    public int $attempts = 0;

    /** The in-flight attempt, or null while queued or between retries. */
    public ?MailDeliveryAttempt $attempt = null;

    /** Earliest time (ms) the next attempt may start, for retry backoff. */
    public float $nextAttemptMs = 0.0;

    /**
     * @param EmailMessage $message The rendered recipient message to send
     * @param ?string $templateKey Template key for the failure log, or null for an inline message
     * @param ?string $progressTicket Ticket this send's steps are reported against, or null when
     *                                nobody is watching the letter go
     */
    public function __construct(
        public readonly EmailMessage $message,
        public readonly ?string $templateKey,
        public readonly ?string $progressTicket = null,
    ) {
    }
}
