<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification\Delivery;

use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationTypeDescriptor;
use Hilos\Notification\NotificationTypeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the DB-free delivery-channel contract (HIL-196).
 *
 * Locks the pure pieces of the channel foundation: the delivery status set, the
 * deliver-signal payload round-trip, the settings-key convention, the descriptor
 * defaults (singleton vs pooled routing), the extensible channel and type
 * registries (including the HIL-485 mandatory flag), and the HIL-200 channel
 * narrowing on the draft. The DB-backed dispatch and the async agent pipeline are
 * exercised when a concrete channel lands (197+).
 */
final class DeliveryChannelTest extends TestCase
{
    public function testStatusConstantsMatchSqlEnumValues(): void
    {
        self::assertSame('pending', DeliveryStatus::PENDING);
        self::assertSame('sent', DeliveryStatus::SENT);
        self::assertSame('failed', DeliveryStatus::FAILED);
        self::assertSame(['pending', 'sent', 'failed'], DeliveryStatus::ALL);
    }

    public function testStatusIsValidAcceptsKnownAndRejectsUnknown(): void
    {
        foreach (DeliveryStatus::ALL as $status) {
            self::assertTrue(DeliveryStatus::isValid($status));
        }
        self::assertFalse(DeliveryStatus::isValid(''));
        self::assertFalse(DeliveryStatus::isValid('queued'));
        self::assertFalse(DeliveryStatus::isValid('Sent'));
    }

    public function testEnabledSettingKeyFollowsConvention(): void
    {
        self::assertSame('notifications.channel.email.enabled', DeliveryChannelSettings::enabledKey('email'));
        self::assertSame('notifications.channel.telegram.enabled', DeliveryChannelSettings::enabledKey('telegram'));
    }

    public function testDeliverSignalDataRoundTripsWithShardKey(): void
    {
        $pooled = new NotificationDeliverSignalData(notificationId: 12, channel: 'email', shardKey: 7);
        $restored = NotificationDeliverSignalData::fromArray($pooled->toArray());
        self::assertSame(12, $restored->notificationId);
        self::assertSame('email', $restored->channel);
        self::assertSame(7, $restored->shardKey);
    }

    public function testDeliverSignalDataRoundTripsWithoutShardKey(): void
    {
        $singleton = new NotificationDeliverSignalData(notificationId: 3, channel: 'telegram');
        $restored = NotificationDeliverSignalData::fromArray($singleton->toArray());
        self::assertSame(3, $restored->notificationId);
        self::assertSame('telegram', $restored->channel);
        self::assertNull($restored->shardKey);
    }

    public function testSingletonChannelDescriptorDefaults(): void
    {
        $channel = new DeliveryTestSingletonChannel();

        self::assertSame('telegram', $channel->name());
        self::assertSame('notifications.channel.telegram.enabled', $channel->enabledSettingKey());
        self::assertFalse($channel->isPooled());
        self::assertNull($channel->indexField());
        self::assertNull($channel->shardKeyFor(7, 42));
        self::assertSame('tg-7', $channel->resolveAddress(7));
        self::assertNull($channel->resolveAddress(99));
    }

    public function testPooledChannelDescriptorRoutesByShardKey(): void
    {
        $channel = new DeliveryTestPooledChannel();

        self::assertTrue($channel->isPooled());
        self::assertSame(NotificationDeliverSignalData::shardKey, $channel->indexField());
        self::assertSame(7, $channel->shardKeyFor(7, 42));
    }

    public function testChannelRegistryBaseIsEmptyAndSubclassComposes(): void
    {
        self::assertSame([], DeliveryChannelRegistry::all());
        self::assertNull(DeliveryChannelRegistry::get('email'));

        self::assertSame(['email', 'telegram'], array_keys(DeliveryTestChannelRegistry::all()));
        self::assertInstanceOf(DeliveryTestPooledChannel::class, DeliveryTestChannelRegistry::get('email'));
        self::assertNull(DeliveryTestChannelRegistry::get('sms'));
    }

    public function testTypeRegistryTreatsUnknownAsNonMandatory(): void
    {
        self::assertFalse(NotificationTypeRegistry::isMandatory('anything'));
        self::assertNull(NotificationTypeRegistry::get('anything'));
        self::assertFalse((new NotificationTypeDescriptor())->mandatory);
    }

    public function testTypeRegistrySubclassDeclaresMandatoryTypes(): void
    {
        self::assertTrue(DeliveryTestTypeRegistry::isMandatory('security.alert'));
        self::assertFalse(DeliveryTestTypeRegistry::isMandatory('chat.message'));
        self::assertFalse(DeliveryTestTypeRegistry::isMandatory('unregistered'));
    }

    public function testDraftChannelsDefaultsToAllAndNarrows(): void
    {
        $all = new NotificationDraft(userId: 7, type: 'chat.message', title: 'Hi');
        self::assertNull($all->channels);

        $narrowed = new NotificationDraft(
            userId: 7,
            type: 'chat.message',
            title: 'Hi',
            channels: ['email'],
        );
        self::assertSame(['email'], $narrowed->channels);
    }
}

/**
 * Test singleton channel: reachable only for user 7 (telegram-shaped, no pool).
 */
final class DeliveryTestSingletonChannel extends AbstractDeliveryChannel
{
    public function name(): string
    {
        return 'telegram';
    }

    public function deliverSignalName(): string
    {
        return 'hilos_notification_deliver_telegram';
    }

    public function resolveAddress(int $userId): ?string
    {
        return $userId === 7 ? 'tg-7' : null;
    }
}

/**
 * Test pooled channel: always reachable (email-shaped, sharded by recipient).
 */
final class DeliveryTestPooledChannel extends AbstractDeliveryChannel
{
    public function name(): string
    {
        return 'email';
    }

    public function deliverSignalName(): string
    {
        return 'hilos_notification_deliver_email';
    }

    public function resolveAddress(int $userId): ?string
    {
        return 'user-' . $userId . '@example.test';
    }

    public function isPooled(): bool
    {
        return true;
    }
}

/**
 * Test channel registry composing the two fixture channels onto the empty base.
 */
final class DeliveryTestChannelRegistry extends DeliveryChannelRegistry
{
    protected static function channels(): array
    {
        return array_replace(parent::channels(), [
            'email' => new DeliveryTestPooledChannel(),
            'telegram' => new DeliveryTestSingletonChannel(),
        ]);
    }
}

/**
 * Test type registry declaring one mandatory and one non-mandatory type.
 */
final class DeliveryTestTypeRegistry extends NotificationTypeRegistry
{
    protected static function types(): array
    {
        return array_replace(parent::types(), [
            'security.alert' => new NotificationTypeDescriptor(mandatory: true),
            'chat.message' => new NotificationTypeDescriptor(),
        ]);
    }
}
