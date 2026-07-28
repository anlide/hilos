<?php

declare(strict_types=1);

namespace Hilos\Mail\Delivery;

use Hilos\Mail\EmailMessage;
use Hilos\Mail\Exception\MailBusyException;
use Hilos\Mail\MailSendOutcome;
use Hilos\Mail\MailTransportInterface;
use Hilos\Notification\Delivery\DeliveryAttempt;

/**
 * MailDeliveryAttempt - one non-blocking email send wrapped for the delivery pipeline (HIL-197).
 *
 * Adapts a {@see MailTransportInterface} to the channel agent's {@see DeliveryAttempt}
 * seam: it opens the send on construction and, on each {@see tick}, pumps the transport
 * one step and latches the settled {@see MailSendOutcome}. The delivery-channel agent
 * owns the pool, retry policy, and delivery-row bookkeeping; this only reflects one
 * transport's progress. A send failure never throws — it settles as an outcome whose
 * {@see errorDetail} the agent records.
 */
final class MailDeliveryAttempt implements DeliveryAttempt
{
    /** Settled outcome, latched once the transport reports a result. */
    private ?MailSendOutcome $outcome = null;

    /**
     * Opens the send on the freshly created transport.
     *
     * @param MailTransportInterface $transport Idle transport dedicated to this one send
     * @param EmailMessage $message Recipient message to deliver
     * @param float $nowMs Current time in milliseconds
     * @throws MailBusyException When the transport is not idle (never for a freshly built transport)
     */
    public function __construct(
        private readonly MailTransportInterface $transport,
        EmailMessage $message,
        float $nowMs,
    ) {
        $this->transport->start($message, $nowMs);
    }

    /**
     * Pumps the transport one step and latches its outcome once the send settles.
     *
     * @param float $nowMs Current time in milliseconds
     */
    public function tick(float $nowMs): void
    {
        $this->transport->tick($nowMs);
        if ($this->outcome === null && !$this->transport->isBusy() && $this->transport->hasResult()) {
            $this->outcome = $this->transport->consumeResult();
        }
    }

    /**
     * @return bool True while the transport has not settled
     */
    public function isBusy(): bool
    {
        return $this->transport->isBusy();
    }

    /**
     * @return bool True when the settled send was accepted for delivery
     */
    public function isDelivered(): bool
    {
        return $this->outcome?->delivered ?? false;
    }

    /**
     * @return ?string The settled failure sentence, or null when delivered or unsettled
     */
    public function errorDetail(): ?string
    {
        return $this->outcome?->errorDetail;
    }

    /**
     * Releases the underlying transport (socket, handle).
     */
    public function close(): void
    {
        $this->transport->close();
    }
}
