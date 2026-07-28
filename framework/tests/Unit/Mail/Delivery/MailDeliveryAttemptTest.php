<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail\Delivery;

use Hilos\Mail\Delivery\MailDeliveryAttempt;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\Exception\MailBusyException;
use Hilos\Mail\Exception\MailResultUnavailableException;
use Hilos\Mail\MailSendOutcome;
use Hilos\Mail\MailTransportInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests that the delivery-attempt adapter pumps its transport and latches the outcome (HIL-197).
 *
 * The attempt opens the send on construction, stays busy until the transport settles,
 * then reports {@see MailDeliveryAttempt::isDelivered()} / errorDetail from the single
 * consumed {@see MailSendOutcome}. A send failure surfaces as a domain sentence, never
 * an exception, and the outcome is consumed exactly once.
 */
final class MailDeliveryAttemptTest extends TestCase
{
    public function testStaysBusyUntilTheTransportSettles(): void
    {
        $transport = new FakeMailTransport(2, MailSendOutcome::delivered());
        $message = new EmailMessage(to: 'user@example.com', subject: 'Hi', text: 'Body');
        $attempt = new MailDeliveryAttempt($transport, $message, 0.0);

        self::assertSame($message, $transport->started);
        self::assertTrue($attempt->isBusy());
        self::assertFalse($attempt->isDelivered());

        $attempt->tick(1.0);
        self::assertTrue($attempt->isBusy());

        $attempt->tick(2.0);
        self::assertFalse($attempt->isBusy());
        self::assertTrue($attempt->isDelivered());
        self::assertNull($attempt->errorDetail());
    }

    public function testFailedSendSurfacesTheDomainSentence(): void
    {
        $transport = new FakeMailTransport(1, MailSendOutcome::failed('recipient mailbox rejected the message', true));
        $attempt = new MailDeliveryAttempt(
            $transport,
            new EmailMessage(to: 'user@example.com', subject: 'Hi', text: 'Body'),
            0.0,
        );

        $attempt->tick(1.0);

        self::assertFalse($attempt->isBusy());
        self::assertFalse($attempt->isDelivered());
        self::assertSame('recipient mailbox rejected the message', $attempt->errorDetail());
    }

    public function testOutcomeIsConsumedExactlyOnce(): void
    {
        $transport = new FakeMailTransport(1, MailSendOutcome::delivered());
        $attempt = new MailDeliveryAttempt(
            $transport,
            new EmailMessage(to: 'user@example.com', subject: 'Hi', text: 'Body'),
            0.0,
        );

        $attempt->tick(1.0);
        self::assertSame(1, $transport->consumeCount);

        $attempt->tick(2.0);
        self::assertSame(1, $transport->consumeCount);
        self::assertTrue($attempt->isDelivered());
    }

    public function testCloseReleasesTheTransport(): void
    {
        $transport = new FakeMailTransport(1, MailSendOutcome::delivered());
        $attempt = new MailDeliveryAttempt(
            $transport,
            new EmailMessage(to: 'user@example.com', subject: 'Hi', text: 'Body'),
            0.0,
        );

        $attempt->close();

        self::assertTrue($transport->closed);
    }
}

/**
 * A scripted mail transport that settles after a fixed number of ticks.
 */
final class FakeMailTransport implements MailTransportInterface
{
    public ?EmailMessage $started = null;
    public bool $closed = false;
    public int $consumeCount = 0;

    private bool $busy = false;
    private bool $ready = false;
    private int $ticks = 0;

    /**
     * @param int $settleAfter Number of ticks before the send settles
     * @param MailSendOutcome $outcome Outcome reported once settled
     */
    public function __construct(
        private readonly int $settleAfter,
        private readonly MailSendOutcome $outcome,
    ) {
    }

    public function start(EmailMessage $message, float $nowMs): void
    {
        if ($this->busy) {
            throw new MailBusyException('transport already sending');
        }
        $this->started = $message;
        $this->busy = true;
        $this->ready = false;
        $this->ticks = 0;
    }

    public function tick(float $nowMs): void
    {
        if (!$this->busy) {
            return;
        }
        if (++$this->ticks >= $this->settleAfter) {
            $this->busy = false;
            $this->ready = true;
        }
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function hasResult(): bool
    {
        return $this->ready;
    }

    public function consumeResult(): MailSendOutcome
    {
        if (!$this->ready) {
            throw new MailResultUnavailableException('no settled outcome');
        }
        $this->ready = false;
        $this->consumeCount++;

        return $this->outcome;
    }

    public function close(): void
    {
        $this->closed = true;
        $this->busy = false;
    }
}
