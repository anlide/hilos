<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Push\Delivery;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Push\Delivery\PushDeliveryChannel;
use PHPUnit\Framework\TestCase;

/**
 * Tests the web-push channel descriptor: identity, reachability, and recipient sharding (HIL-199).
 *
 * The channel names the `push` channel and points at HILOS_PUSH_DELIVER; it is pooled and
 * shards by the recipient id (its natural dimension, with no raw-send intake to co-locate
 * with) narrowed to the push pool range. Reachability answers presence from the recipient's
 * subscriptions rather than a single address; without a DB context it is unresolved. VAPID
 * config exposes the public key as an operational field and the private key as an env-only secret.
 */
final class PushDeliveryChannelTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;
    private ?HilosDbContext $previousDb = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousDb = Hilos::$db;
        putenv('PUSH_WORKER_COUNT');
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        Hilos::$db = $this->previousDb;
        putenv('PUSH_WORKER_COUNT');
        parent::tearDown();
    }

    public function testChannelIdentityIsPushAndPooled(): void
    {
        $channel = new PushDeliveryChannel();

        self::assertSame('push', $channel->name());
        self::assertSame('webpush', $channel->driver());
        self::assertSame(HilosSignalConstants::HILOS_PUSH_DELIVER, $channel->deliverSignalName());
        self::assertTrue($channel->isPooled());
        self::assertSame(NotificationDeliverSignalData::shardKey, $channel->indexField());
        self::assertSame('notifications.channel.push.enabled', $channel->enabledSettingKey());
    }

    public function testAddressIsNullWithoutDbContext(): void
    {
        Hilos::$db = null;
        $channel = new PushDeliveryChannel();

        self::assertNull($channel->resolveAddress(7));
    }

    public function testShardKeyNarrowsTheRecipientIdToThePoolRange(): void
    {
        putenv('PUSH_WORKER_COUNT=8');
        $channel = new PushDeliveryChannel();

        self::assertSame(8, $channel->shardKeyFor(7, 42));
        self::assertSame(1, $channel->shardKeyFor(8, 42));

        $shard = $channel->shardKeyFor(123, 42);
        self::assertGreaterThanOrEqual(1, $shard);
        self::assertLessThanOrEqual(8, $shard);
    }

    public function testShardKeyIsOneWithoutAConfiguredPool(): void
    {
        $channel = new PushDeliveryChannel();

        self::assertSame(1, $channel->shardKeyFor(7, 42));
    }

    public function testConfigFieldsDeclareThePublicFieldAndTheEnvOnlyPrivateSecret(): void
    {
        $fields = [];
        foreach ((new PushDeliveryChannel())->configFields() as $field) {
            $fields[$field->key] = $field;
        }

        self::assertSame(
            [
                PushDeliveryChannel::FIELD_VAPID_PUBLIC,
                PushDeliveryChannel::FIELD_VAPID_PRIVATE,
            ],
            array_keys($fields),
        );

        self::assertFalse($fields[PushDeliveryChannel::FIELD_VAPID_PUBLIC]->secret);
        self::assertSame(EnvConstants::VAPID_PUBLIC, $fields[PushDeliveryChannel::FIELD_VAPID_PUBLIC]->envKey);

        self::assertTrue($fields[PushDeliveryChannel::FIELD_VAPID_PRIVATE]->secret);
        self::assertSame(EnvConstants::VAPID_PRIVATE, $fields[PushDeliveryChannel::FIELD_VAPID_PRIVATE]->envKey);
    }
}
