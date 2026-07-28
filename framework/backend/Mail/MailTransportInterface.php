<?php

declare(strict_types=1);

namespace Hilos\Mail;

use Hilos\Mail\Exception\MailBusyException;
use Hilos\Mail\Exception\MailResultUnavailableException;

/**
 * MailTransportInterface - a non-blocking driver for one email send (HIL-197).
 *
 * Modeled on {@see \Hilos\API\AsyncHttpClient}: a send is opened with {@see start()},
 * then pumped one step per {@see tick()} so the worker loop never blocks on the network.
 * The transport carries a single in-flight send at a time; the delivery-channel agent
 * runs a pool of transports for concurrency. When the send settles {@see isBusy()} turns
 * false and {@see hasResult()} true, and the terminal {@see MailSendOutcome} is taken
 * exactly once with {@see consumeResult()}.
 *
 * Implementations never throw on a send failure — the failure settles as a failed
 * outcome carrying a domain sentence. They throw only on contract misuse (starting a
 * busy transport, consuming an absent result).
 */
interface MailTransportInterface
{
    /**
     * Opens a new send for one recipient message.
     *
     * @param EmailMessage $message The recipient message to deliver
     * @param float $nowMs Current time in milliseconds
     * @throws MailBusyException When a previous send is still in flight
     */
    public function start(EmailMessage $message, float $nowMs): void;

    /**
     * Advances the in-flight send by one non-blocking step.
     *
     * @param float $nowMs Current time in milliseconds
     */
    public function tick(float $nowMs): void;

    /**
     * @return bool True while a send is in flight
     */
    public function isBusy(): bool;

    /**
     * @return bool True when a settled outcome is ready to consume
     */
    public function hasResult(): bool;

    /**
     * Takes the settled outcome and clears it.
     *
     * @return MailSendOutcome The terminal outcome of the last send
     * @throws MailResultUnavailableException When no outcome is ready
     */
    public function consumeResult(): MailSendOutcome;

    /**
     * Abandons any in-flight send and releases transport resources.
     */
    public function close(): void;
}
