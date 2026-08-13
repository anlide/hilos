<?php

declare(strict_types=1);

namespace Hilos\Mail;

use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\Exception\MailBusyException;
use Hilos\Mail\Exception\MailResultUnavailableException;

/**
 * FailedMailTransport - a transport that settles a fixed permanent failure (HIL-197).
 *
 * The stand-in the mail agent hands out when the static MAIL_* config is invalid: rather
 * than let {@see MailTransportConfig::fromEnv()} throw out of the tick loop and crash-loop
 * the worker, {@see MailDeliveryChannelAgent::createTransport()} returns this so every send
 * settles as a permanent failure and is dropped through the normal outcome path. It carries
 * no socket and never touches the network.
 */
final class FailedMailTransport implements MailTransportInterface
{
    /** Settled failure outcome awaiting consumption, or null before start / after consume. */
    private ?MailSendOutcome $result = null;

    /**
     * @param string $reason Domain failure sentence reported for every send
     */
    public function __construct(
        private readonly string $reason = 'mail transport is not configured',
    ) {
    }

    /**
     * Immediately settles the send as a permanent failure.
     *
     * @param EmailMessage $message The recipient message (never sent)
     * @param float $nowMs Current time in milliseconds (unused)
     * @throws MailBusyException When a previous unconsumed failure is still latched
     */
    public function start(EmailMessage $message, float $nowMs): void
    {
        if ($this->result !== null) {
            throw new MailBusyException();
        }

        $this->result = MailSendOutcome::failed($this->reason, true);
    }

    /**
     * @param float $nowMs Current time in milliseconds (unused)
     */
    public function tick(float $nowMs): void
    {
    }

    /**
     * @return bool Always false: the failure settles on start
     */
    public function isBusy(): bool
    {
        return false;
    }

    /**
     * @return bool True once a start has latched the failure
     */
    public function hasResult(): bool
    {
        return $this->result !== null;
    }

    /**
     * @return MailSendOutcome The latched permanent failure
     * @throws MailResultUnavailableException When no send has been started
     */
    public function consumeResult(): MailSendOutcome
    {
        if ($this->result === null) {
            throw new MailResultUnavailableException();
        }

        $result = $this->result;
        $this->result = null;

        return $result;
    }

    /**
     * Drops any latched failure.
     */
    public function close(): void
    {
        $this->result = null;
    }
}
