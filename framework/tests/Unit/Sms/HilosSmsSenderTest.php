<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Sms;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Sms\DTO\SmsSendSignalData;
use Hilos\Sms\HilosSmsSender;
use Hilos\Sms\SmsMessage;
use PHPUnit\Framework\TestCase;

/**
 * Tests the SMS send seam: stable per-number sharding and the raw-send handoff (HIL-285).
 *
 * {@see HilosSmsSender::shardKeyForNumber()} must be stable and whitespace-insensitive so every
 * message to one number lands on the same pool instance, and {@see HilosSmsSender::send()} must
 * queue the raw-send agent signal rather than doing SMS I/O in place.
 */
final class HilosSmsSenderTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;
    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        putenv('SMS_WORKER_COUNT=4');
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
        putenv('SMS_WORKER_COUNT');
        parent::tearDown();
    }

    public function testShardKeyIsStableAndInRange(): void
    {
        $first = HilosSmsSender::shardKeyForNumber('+15551234567');
        $second = HilosSmsSender::shardKeyForNumber('+15551234567');

        self::assertSame($first, $second);
        self::assertGreaterThanOrEqual(1, $first);
        self::assertLessThanOrEqual(4, $first);
    }

    public function testShardKeyIgnoresWhitespace(): void
    {
        self::assertSame(
            HilosSmsSender::shardKeyForNumber('+15551234567'),
            HilosSmsSender::shardKeyForNumber('+1 555 123 4567'),
        );
    }

    public function testSendQueuesRawSendSignal(): void
    {
        $router = new HilosSmsSenderTestSignalRouter();
        Hilos::$sr = $router;

        (new HilosSmsSender())->send(new SmsMessage('+15551234567', 'Your code is 123'));

        self::assertCount(1, $router->captured);
        $signal = $router->captured[0];
        self::assertSame(SignalTypeConstants::AGENT_SIGNAL, $signal['type']);
        self::assertSame(HilosSignalConstants::HILOS_SMS_SEND, $signal['name']);

        $payload = $signal['data']->data;
        self::assertInstanceOf(SmsSendSignalData::class, $payload);
        self::assertSame('+15551234567', $payload->to);
        self::assertSame(HilosSmsSender::shardKeyForNumber('+15551234567'), $payload->shardKey);
        self::assertSame('Your code is 123', $payload->text);
        self::assertNull($payload->templateKey);
    }
}

/**
 * Signal router double that records queued signals instead of routing them.
 */
final class HilosSmsSenderTestSignalRouter extends SignalRouter
{
    /** @var list<array{type: string, name: string, data: AgentSignalData}> */
    public array $captured = [];

    public function queueSignal(
        SignalSourceInterface $signalSource,
        SignalTypeInterface $signalType,
        SignalNameInterface $signalName,
        SignalDataInterface $signalData,
    ): void {
        $this->captured[] = [
            'type' => $signalType->getType(),
            'name' => $signalName->getName(),
            'data' => $signalData,
        ];
    }
}
