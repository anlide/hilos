<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Sms\Delivery;

use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use Hilos\Sms\HilosSmsSender;
use PHPUnit\Framework\TestCase;

/** Source-number routing stays co-located with raw SMS sends. */
final class SmsDeliveryChannelTest extends TestCase
{
    private ?EnvAccessor $previousEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        putenv('SMS_WORKER_COUNT');
        parent::tearDown();
    }

    public function testSourceKeyUsesTheExistingNumberNormalizationAndRawIndex(): void
    {
        $channel = $this->createPartialMock(SmsDeliveryChannel::class, ['resolveAddress']);
        $channel->method('resolveAddress')->willReturn(' +1 555 123 4567 ');
        putenv('SMS_WORKER_COUNT=8');
        self::assertSame(296487516, $channel->routingKeyFor(7, 42));
        self::assertSame(296487516, HilosSmsSender::routingKeyForNumber('+15551234567'));
        self::assertSame(8, $channel->poolSize());
        self::assertSame(5, $channel->ownerIndexFor(296487516));
        self::assertSame(5, $channel->shardKeyFor(7, 42));
        self::assertSame(5, HilosSmsSender::shardKeyForNumber('+1 555 123 4567'));
    }

    public function testChangingWidthReducesTheSameSourceKeyForBothIntakes(): void
    {
        $channel = new SmsDeliveryChannel();
        putenv('SMS_WORKER_COUNT=4');
        self::assertSame(1, $channel->ownerIndexFor(296487516));
        self::assertSame(1, HilosSmsSender::shardKeyForNumber('+15551234567'));
        putenv('SMS_WORKER_COUNT=8');
        self::assertSame(5, $channel->ownerIndexFor(296487516));
        self::assertSame(5, HilosSmsSender::shardKeyForNumber('+15551234567'));
    }

    public function testMissingNumberHasNoSourceKeyOrCompatibilityIndex(): void
    {
        $channel = $this->createPartialMock(SmsDeliveryChannel::class, ['resolveAddress']);
        $channel->method('resolveAddress')->willReturn(null);
        self::assertNull($channel->routingKeyFor(7, 42));
        self::assertNull($channel->shardKeyFor(7, 42));
    }
}
