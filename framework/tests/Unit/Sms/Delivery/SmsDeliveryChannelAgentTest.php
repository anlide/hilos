<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Sms\Delivery;

use Hilos\Auth\Code\DTO\CodeSendStepSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgent;
use Hilos\Sms\Delivery\SmsSendAttempt;
use Hilos\Sms\DTO\SmsSendSignalData;
use Hilos\Sms\SmsMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Transport reports of a watched raw SMS send. */
final class SmsDeliveryChannelAgentTest extends TestCase
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
        $agent = new ScriptedSmsAgent([new ScriptedSmsAttempt(true, false, null)]);
        $this->send($agent, self::TICKET);

        $agent->onTick();
        $agent->onTick();

        self::assertSame(
            [HilosCodeSendAttempt::STATE_SENDING, HilosCodeSendAttempt::STATE_SENT],
            $this->states(),
        );
        self::assertCount(1, $agent->started);
    }

    public function testRetryableRefusalReturnsToQueueBeforeSuccess(): void
    {
        $agent = new ScriptedSmsAgent([
            new ScriptedSmsAttempt(false, false, 'temporary gateway refusal'),
            new ScriptedSmsAttempt(true, false, null),
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
        $agent = new ScriptedSmsAgent([new ScriptedSmsAttempt(false, true, "number rejected\nprivate provider trace")]);
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
        $agent = new ScriptedSmsAgent([new ScriptedSmsAttempt(true, false, null)]);
        $this->send($agent, null);

        $agent->onTick();
        $agent->onTick();

        self::assertSame([], $this->frames());
    }

    private function send(ScriptedSmsAgent $agent, ?string $ticket): void
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
final class ScriptedSmsAgent extends SmsDeliveryChannelAgent
{
    /** @var list<SmsMessage> Messages handed to the provider */
    public array $started = [];

    /** @param list<SmsSendAttempt> $attempts Attempts to hand out in order */
    public function __construct(private array $attempts)
    {
        parent::__construct('1');
    }

    protected function buildAttempt(SmsMessage $message, float $nowMs): SmsSendAttempt
    {
        $this->started[] = $message;

        return array_shift($this->attempts) ?? throw new RuntimeException('No scripted SMS attempt left');
    }

    protected function rawBackoffMs(int $attempts): float
    {
        return 0.0;
    }
}

/** One raw SMS send that settles after its first tick. */
final class ScriptedSmsAttempt implements SmsSendAttempt
{
    private bool $busy = true;

    public function __construct(
        private readonly bool $delivered,
        private readonly bool $permanent,
        private readonly ?string $detail,
    ) {
    }

    public function tick(float $nowMs): void
    {
        $this->busy = false;
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function isDelivered(): bool
    {
        return $this->delivered;
    }

    public function errorDetail(): ?string
    {
        return $this->detail;
    }

    public function isPermanentFailure(): bool
    {
        return $this->permanent;
    }

    public function close(): void
    {
    }
}
