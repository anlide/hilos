<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Sms\Delivery;

use Hilos\Auth\Code\DTO\CodeSendStepSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use Hilos\Sms\Delivery\SmsRawSendAgent;
use Hilos\Sms\Delivery\SmsSendAttempt;
use Hilos\Sms\DTO\SmsSendSignalData;
use Hilos\Sms\SmsMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Raw-only SMS transport, progress, pool bounds and intake refusals without a database. */
final class SmsRawSendAgentTest extends TestCase
{
    private const string TICKET = 'a1b2c3d4e5f60718';

    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousRouter;
    }

    public function testWatchedSmsReportsSendingThenSent(): void
    {
        $agent = new ScriptedRawSmsAgent([new ScriptedRawSmsAttempt(true, false, null)]);
        $this->send($agent, self::TICKET);

        $agent->onTick();
        $agent->onTick();

        self::assertSame(
            [HilosCodeSendAttempt::STATE_SENDING, HilosCodeSendAttempt::STATE_SENT],
            $this->states(),
        );
        self::assertCount(1, $agent->started);
        self::assertSame('+15551234567', $agent->started[0]->to);
        self::assertSame('Code 123456', $agent->started[0]->text);
    }

    public function testRetryableRefusalReturnsToQueueBeforeSuccess(): void
    {
        $agent = new ScriptedRawSmsAgent([
            new ScriptedRawSmsAttempt(false, false, 'temporary gateway refusal'),
            new ScriptedRawSmsAttempt(true, false, null),
        ]);
        $this->send($agent, self::TICKET);

        for ($tick = 0; $tick < 3; $tick++) {
            $agent->onTick();
        }

        self::assertSame(
            [
                HilosCodeSendAttempt::STATE_SENDING,
                HilosCodeSendAttempt::STATE_QUEUED,
                HilosCodeSendAttempt::STATE_SENDING,
                HilosCodeSendAttempt::STATE_SENT,
            ],
            $this->states(),
        );
    }

    public function testTerminalRefusalCarriesTheProvidersFirstLine(): void
    {
        $agent = new ScriptedRawSmsAgent([new ScriptedRawSmsAttempt(false, true, "number rejected\nprivate provider trace")]);
        $this->send($agent, self::TICKET);

        $agent->onTick();
        $agent->onTick();

        $frames = $this->frames();
        self::assertSame([HilosCodeSendAttempt::STATE_SENDING, HilosCodeSendAttempt::STATE_FAILED], array_map(
            static fn(CodeSendStepSignalData $frame): ?string => $frame->state,
            $frames,
        ));
        self::assertSame('number rejected', $frames[1]->detail);
    }

    public function testUnwatchedSmsReportsNothing(): void
    {
        $agent = new ScriptedRawSmsAgent([new ScriptedRawSmsAttempt(true, false, null)]);
        $this->send($agent, null);

        $agent->onTick();
        $agent->onTick();

        self::assertSame([], $this->frames());
    }

    public function testConcurrencyStartsFourAndWaitsForTransportCompletion(): void
    {
        $attempts = [
            new ScriptedRawSmsAttempt(true, false, null, ticksRemaining: 3),
            new ScriptedRawSmsAttempt(true, false, null, ticksRemaining: 3),
            new ScriptedRawSmsAttempt(true, false, null, ticksRemaining: 3),
            new ScriptedRawSmsAttempt(true, false, null, ticksRemaining: 3),
            new ScriptedRawSmsAttempt(true, false, null),
        ];
        $agent = new ScriptedRawSmsAgent($attempts);
        for ($send = 0; $send < 5; $send++) {
            $this->send($agent, null);
        }

        $agent->onTick();
        self::assertCount(4, $agent->started);
        $agent->onTick();
        $agent->onTick();
        self::assertCount(4, $agent->started);
        self::assertSame(0, $attempts[0]->closeCount);

        $agent->onTick();
        self::assertCount(5, $agent->started);
        self::assertSame(1, $attempts[0]->closeCount);
        self::assertSame(0, $attempts[4]->closeCount);
        $agent->onTick();
        self::assertSame(1, $attempts[4]->closeCount);
    }

    public function testTransientFailuresStopAfterThreeAttempts(): void
    {
        $attempts = [
            new ScriptedRawSmsAttempt(false, false, 'gateway still unavailable'),
            new ScriptedRawSmsAttempt(false, false, 'gateway still unavailable'),
            new ScriptedRawSmsAttempt(false, false, 'gateway still unavailable'),
            new ScriptedRawSmsAttempt(true, false, null),
        ];
        $agent = new ScriptedRawSmsAgent($attempts);
        $this->send($agent, self::TICKET);

        for ($tick = 0; $tick < 6; $tick++) {
            $agent->onTick();
        }

        $frames = $this->frames();
        self::assertSame([
            HilosCodeSendAttempt::STATE_SENDING,
            HilosCodeSendAttempt::STATE_QUEUED,
            HilosCodeSendAttempt::STATE_SENDING,
            HilosCodeSendAttempt::STATE_QUEUED,
            HilosCodeSendAttempt::STATE_SENDING,
            HilosCodeSendAttempt::STATE_FAILED,
        ], array_map(static fn(CodeSendStepSignalData $frame): ?string => $frame->state, $frames));
        self::assertSame('gateway still unavailable', $frames[5]->detail);
        self::assertCount(3, $agent->started);
        self::assertSame([1, 1, 1, 0], array_map(static fn(ScriptedRawSmsAttempt $attempt): int => $attempt->closeCount, $attempts));
    }

    public function testStopClosesEveryAttemptAndDiscardsQueuedSends(): void
    {
        $attempts = [];
        for ($send = 0; $send < 5; $send++) {
            $attempts[] = new ScriptedRawSmsAttempt(true, false, null, ticksRemaining: 10);
        }
        $agent = new ScriptedRawSmsAgent($attempts);
        for ($send = 0; $send < 5; $send++) {
            $this->send($agent, null);
        }
        $agent->onTick();
        self::assertCount(4, $agent->started);

        $agent->onStop();
        $agent->onStop();
        $agent->onTick();

        self::assertSame([1, 1, 1, 1, 0], array_map(static fn(ScriptedRawSmsAttempt $attempt): int => $attempt->closeCount, $attempts));
        self::assertCount(4, $agent->started);
        self::assertSame([], $this->frames());
    }

    /**
     * @param string $name Incoming signal name
     * @param SignalDataInterface $payload Incoming inner payload
     * @param class-string<AgentException> $exceptionClass Expected intake refusal
     */
    #[DataProvider('foreignSignals')]
    public function testRejectsForeignAndDurableSignals(string $name, SignalDataInterface $payload, string $exceptionClass): void
    {
        $agent = new ScriptedRawSmsAgent([]);

        try {
            $agent->onSignalAgent(new AgentSignalData($payload), 'test', $name);
            self::fail('The raw-only worker must refuse this frame');
        } catch (AgentException $failure) {
            self::assertSame($exceptionClass, $failure::class);
        }
        $agent->onTick();
        $agent->onStop();

        self::assertSame([], $agent->started);
        self::assertSame([], $this->frames());
    }

    /** @return iterable<string, array{string, SignalDataInterface, class-string<AgentException>}> Refused name and payload pairs */
    public static function foreignSignals(): iterable
    {
        $raw = new SmsSendSignalData(to: '+15551234567', shardKey: 1, text: 'Code 123456');
        $durable = new NotificationDeliverSignalData(deliveryId: 1, notificationId: 2, channel: SmsDeliveryChannel::NAME, shardKey: 1);

        yield 'raw payload under durable name' => [HilosSignalConstants::HILOS_SMS_DELIVER, $raw, AgentUnknownSignalException::class];
        yield 'durable payload under raw name' => [HilosSignalConstants::HILOS_SMS_SEND, $durable, InvalidAgentSignalPayloadException::class];
        yield 'durable name and payload' => [HilosSignalConstants::HILOS_SMS_DELIVER, $durable, AgentUnknownSignalException::class];
        yield 'foreign name with raw payload' => ['foreign_sms_signal', $raw, AgentUnknownSignalException::class];
        yield 'foreign name with durable payload' => ['foreign_sms_signal', $durable, AgentUnknownSignalException::class];
    }

    private function send(ScriptedRawSmsAgent $agent, ?string $ticket): void
    {
        $agent->onSignalAgent(
            new AgentSignalData(new SmsSendSignalData(to: '+15551234567', shardKey: 1, text: 'Code 123456', progressTicket: $ticket)),
            'test',
            HilosSignalConstants::HILOS_SMS_SEND,
        );
    }

    /** @return list<string|null> Reported states in order */
    private function states(): array
    {
        return array_map(static fn(CodeSendStepSignalData $frame): ?string => $frame->state, $this->frames());
    }

    /** @return list<CodeSendStepSignalData> Reports from the SMS agent */
    private function frames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof CodeSendStepSignalData) {
                $frames[] = $signal->data->data;
            }
        }

        return $frames;
    }
}

/** SMS agent whose raw send attempts settle on the test's clock. */
final class ScriptedRawSmsAgent extends SmsRawSendAgent
{
    /** @var list<SmsMessage> Messages handed to the provider */
    public array $started = [];

    /** @param list<SmsSendAttempt> $attempts Attempts to hand out in order */
    public function __construct(private array $attempts)
    {
        parent::__construct('1');
    }

    /**
     * @param SmsMessage $message Rendered recipient message
     * @param float $nowMs Current time in milliseconds (unused)
     * @return SmsSendAttempt Next scripted transport
     */
    protected function buildAttempt(SmsMessage $message, float $nowMs): SmsSendAttempt
    {
        $this->started[] = $message;

        return array_shift($this->attempts) ?? throw new RuntimeException('No scripted SMS attempt left');
    }

    /**
     * @param int $attempts Attempts made so far (unused)
     * @return float Immediate retry without waiting
     */
    protected function rawBackoffMs(int $attempts): float
    {
        return 0.0;
    }
}

/** One raw SMS send that settles after the configured number of ticks. */
final class ScriptedRawSmsAttempt implements SmsSendAttempt
{
    public int $closeCount = 0;

    /**
     * @param bool $delivered Whether the settled attempt succeeds
     * @param bool $permanent Whether a failed attempt refuses retries
     * @param ?string $detail Provider failure sentence
     * @param int $ticksRemaining Ticks until the attempt settles
     */
    public function __construct(
        private readonly bool $delivered,
        private readonly bool $permanent,
        private readonly ?string $detail,
        private int $ticksRemaining = 1,
    ) {
    }

    /** @param float $nowMs Current time in milliseconds (unused) */
    public function tick(float $nowMs): void
    {
        $this->ticksRemaining = max(0, $this->ticksRemaining - 1);
    }

    /** @return bool Whether more scripted ticks are needed */
    public function isBusy(): bool
    {
        return $this->ticksRemaining > 0;
    }

    /** @return bool Configured delivery outcome */
    public function isDelivered(): bool
    {
        return $this->delivered;
    }

    /** @return ?string Configured provider failure sentence */
    public function errorDetail(): ?string
    {
        return $this->detail;
    }

    /** @return bool Configured retry verdict */
    public function isPermanentFailure(): bool
    {
        return $this->permanent;
    }

    /** Records every release so the pool's cleanup can be checked. */
    public function close(): void
    {
        $this->closeCount++;
    }
}
