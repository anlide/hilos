<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail\Delivery;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\Exception\MailBusyException;
use Hilos\Mail\Exception\MailResultUnavailableException;
use Hilos\Mail\FailedMailTransport;
use Hilos\Mail\MailSendOutcome;
use Hilos\Mail\MailTransportInterface;
use Hilos\Mail\Template\MagicLinkMailTemplate;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests the raw-send pool of the mail agent — input B (HIL-197).
 *
 * A {@see MailSendSignalData} is sent with no delivery row: the agent renders it (inline
 * fields or a template), drives a pooled non-blocking transport, and drops it on success.
 * A permanent failure fails fast; a transient one retries with backoff up to the attempt
 * ceiling; an unknown template key is dropped. The pool honours its own concurrency
 * ceiling, and onStop abandons everything in flight. The notification-delivery intake
 * (input B's sibling) is left to the base pipeline.
 */
final class MailDeliveryChannelAgentTest extends TestCase
{
    public function testRawSendDeliversInlineMessageThenDropsIt(): void
    {
        $transport = new ScriptedMailTransport(2, MailSendOutcome::delivered());
        $agent = new TestableMailAgent([$transport]);

        $this->rawSend($agent, new MailSendSignalData(to: 'user@example.com', shardKey: 1, subject: 'Hi', text: 'Body'));

        $agent->onTick();
        self::assertSame(1, $agent->createdCount);
        self::assertInstanceOf(EmailMessage::class, $transport->started);
        self::assertSame('user@example.com', $transport->started->to);
        self::assertSame('Hi', $transport->started->subject);
        self::assertSame('Body', $transport->started->text);
        self::assertNull($transport->started->html);

        $agent->onTick();
        $agent->onTick();
        self::assertTrue($transport->closed);

        // The op is dropped: no further transport is opened.
        $agent->onTick();
        self::assertSame(1, $agent->createdCount);
    }

    public function testRawSendRendersFromTemplate(): void
    {
        $transport = new ScriptedMailTransport(1, MailSendOutcome::delivered());
        $agent = new TestableMailAgent([$transport]);

        $this->rawSend($agent, new MailSendSignalData(
            to: 'user@example.com',
            shardKey: 1,
            templateKey: MailTemplateCatalogConstants::AUTH_MAGIC_LINK,
            params: [
                MagicLinkMailTemplate::PARAM_LINK => 'https://app.example/x',
                MagicLinkMailTemplate::PARAM_CODE => '246802',
            ],
        ));

        $agent->onTick();

        self::assertInstanceOf(EmailMessage::class, $transport->started);
        self::assertSame('Your sign-in link', $transport->started->subject);
        self::assertStringContainsString('https://app.example/x', $transport->started->text);
    }

    public function testUnknownTemplateKeyIsDropped(): void
    {
        $agent = new TestableMailAgent([]);

        $this->rawSend($agent, new MailSendSignalData(
            to: 'user@example.com',
            shardKey: 1,
            templateKey: 'no.such.template',
            params: ['secret' => 'code-1234'],
        ));

        $agent->onTick();

        self::assertSame(0, $agent->createdCount);
    }

    public function testPermanentFailureIsNotRetried(): void
    {
        // Only one transport is scripted: a retry would ask for a second and blow up.
        $transport = new ScriptedMailTransport(1, MailSendOutcome::failed('recipient mailbox rejected the message', true));
        $agent = new TestableMailAgent([$transport]);

        $this->rawSend($agent, new MailSendSignalData(to: 'user@example.com', shardKey: 1, subject: 'Hi', text: 'Body'));

        $agent->onTick();
        $agent->onTick();
        $agent->onTick();

        self::assertSame(1, $agent->createdCount);
        self::assertTrue($transport->closed);
    }

    public function testTransientFailureRetriesUpToTheCeilingThenDrops(): void
    {
        $transports = [
            new ScriptedMailTransport(1, MailSendOutcome::failed('temporary greylist', false)),
            new ScriptedMailTransport(1, MailSendOutcome::failed('temporary greylist', false)),
            new ScriptedMailTransport(1, MailSendOutcome::failed('temporary greylist', false)),
        ];
        $agent = new TestableMailAgent($transports);

        $this->rawSend($agent, new MailSendSignalData(to: 'user@example.com', shardKey: 1, subject: 'Hi', text: 'Body'));

        // Zero backoff means the retry starts on the same tick the prior attempt fails.
        for ($i = 0; $i < 6; $i++) {
            $agent->onTick();
        }

        self::assertSame(3, $agent->createdCount);
        foreach ($transports as $transport) {
            self::assertTrue($transport->closed);
        }
    }

    public function testConcurrencyCeilingCapsInFlightRawSends(): void
    {
        $transports = [
            new ScriptedMailTransport(2, MailSendOutcome::delivered()),
            new ScriptedMailTransport(2, MailSendOutcome::delivered()),
            new ScriptedMailTransport(1, MailSendOutcome::delivered()),
        ];
        $agent = new TestableMailAgent($transports, maxConcurrent: 2);

        foreach ($transports as $i => $_) {
            $this->rawSend($agent, new MailSendSignalData(to: "user{$i}@example.com", shardKey: 1, subject: 'Hi', text: 'Body'));
        }

        $agent->onTick();
        self::assertSame(2, $agent->createdCount, 'the ceiling caps the first tick to two transports');

        // Drain the first two so the third gets its slot.
        $agent->onTick();
        $agent->onTick();
        self::assertSame(3, $agent->createdCount);
    }

    public function testOnStopClosesInFlightRawSends(): void
    {
        $transport = new ScriptedMailTransport(10, MailSendOutcome::delivered());
        $agent = new TestableMailAgent([$transport]);

        $this->rawSend($agent, new MailSendSignalData(to: 'user@example.com', shardKey: 1, subject: 'Hi', text: 'Body'));
        $agent->onTick();
        self::assertTrue($transport->isBusy());

        $agent->onStop();

        self::assertTrue($transport->closed);
        // The pool is cleared: a later tick opens nothing.
        $agent->onTick();
        self::assertSame(1, $agent->createdCount);
    }

    public function testDeliverSignalIsHandledByTheBasePipelineNotTheRawPool(): void
    {
        $agent = new TestableMailAgent([]);

        // A deliver signal for a foreign channel is dropped by the base intake (no DB touched);
        // the point is that it is not misrouted into the raw-send pool.
        $agent->onSignalAgent(
            new AgentSignalData(new NotificationDeliverSignalData(notificationId: 7, channel: 'sms', shardKey: 1)),
            'src',
            HilosSignalConstants::HILOS_MAIL_DELIVER,
        );
        $agent->onTick();

        self::assertSame(0, $agent->createdCount);
    }

    public function testInvalidMailConfigDropsRawSendInsteadOfCrashingTheTick(): void
    {
        $previousEnv = Hilos::$env;
        putenv('MAIL_SMTP_SECURITY=quantum');
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);

        try {
            $agent = new ConfigProbingMailAgent('1');
            // A bad MAIL_* value resolves to a permanently failing transport, not an exception.
            self::assertInstanceOf(FailedMailTransport::class, $agent->buildTransport());

            $agent->onSignalAgent(
                new AgentSignalData(new MailSendSignalData(to: 'user@example.com', shardKey: 1, subject: 'Hi', text: 'Body')),
                'src',
                HilosSignalConstants::HILOS_MAIL_SEND,
            );

            // Start the send, settle its permanent failure, and drop it — the misconfig never
            // escapes onTick to crash the worker, and the permanent failure is not retried.
            for ($i = 0; $i < 3; $i++) {
                $agent->onTick();
            }

            self::assertSame(2, $agent->transportsBuilt, 'the probe plus one attempt, no retry after a permanent failure');
        } finally {
            Hilos::$env = $previousEnv;
            putenv('MAIL_SMTP_SECURITY');
        }
    }

    /**
     * Enqueues a raw send through the agent's input-B intake.
     *
     * @param TestableMailAgent $agent Agent under test
     * @param MailSendSignalData $signal Raw-send payload
     */
    private function rawSend(TestableMailAgent $agent, MailSendSignalData $signal): void
    {
        $agent->onSignalAgent(new AgentSignalData($signal), 'src', HilosSignalConstants::HILOS_MAIL_SEND);
    }
}

/**
 * A mail agent using the real transport seam so a bad MAIL_* config is exercised end to end,
 * counting how many transports it builds and exposing the seam for a direct assertion.
 */
final class ConfigProbingMailAgent extends MailDeliveryChannelAgent
{
    public int $transportsBuilt = 0;

    /**
     * @return MailTransportInterface The transport the real seam builds from the MAIL_* config
     */
    public function buildTransport(): MailTransportInterface
    {
        return $this->createTransport();
    }

    protected function createTransport(): MailTransportInterface
    {
        $this->transportsBuilt++;

        return parent::createTransport();
    }
}

/**
 * A mail agent whose transport factory is scripted and whose retry backoff is disabled.
 */
final class TestableMailAgent extends MailDeliveryChannelAgent
{
    public int $createdCount = 0;

    /** @var list<MailTransportInterface> Transports handed out one per createTransport() call. */
    private array $transports;

    /**
     * @param list<MailTransportInterface> $transports Transports to hand out in order
     * @param int $maxConcurrent Concurrency ceiling for the raw pool
     */
    public function __construct(array $transports, private readonly int $maxConcurrent = 4)
    {
        parent::__construct('1');
        $this->transports = $transports;
    }

    protected function createTransport(): MailTransportInterface
    {
        $this->createdCount++;
        $transport = array_shift($this->transports);
        if ($transport === null) {
            throw new RuntimeException('no scripted transport left');
        }

        return $transport;
    }

    protected function maxConcurrent(): int
    {
        return $this->maxConcurrent;
    }

    protected function rawBackoffMs(int $attempts): float
    {
        return 0.0;
    }
}

/**
 * A mail transport that settles after a fixed number of ticks with a scripted outcome.
 */
final class ScriptedMailTransport implements MailTransportInterface
{
    public ?EmailMessage $started = null;
    public bool $closed = false;

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

        return $this->outcome;
    }

    public function close(): void
    {
        $this->closed = true;
        $this->busy = false;
    }
}
