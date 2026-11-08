<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Notification\HilosNotifier;

/**
 * NotificationDeliverSignalData - worker → delivery-agent handoff for one channel send (HIL-196).
 *
 * The dispatcher (folded into {@see HilosNotifier::emit()})
 * queues one of these per resolved channel after persisting the notification and
 * its pending delivery row. It captures that row's primary id alongside its
 * notification and channel rather than carrying rendered content on the wire.
 *
 * {@see shardKey} routes pooled (indexed) channels: a channel that declares
 * `AgentSignalConfigKey::INDEX_FIELD => 'shardKey'` fans to the pool instance for
 * the current reduction of the row's saved routing key. A
 * singleton channel leaves it null and declares no index field.
 */
final class NotificationDeliverSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: captured delivery primary id. */
    public const string deliveryId = 'deliveryId';

    /** Payload key: notification id to deliver. */
    public const string notificationId = 'notificationId';

    /** Payload key: target channel name. */
    public const string channel = 'channel';

    /** Payload key: pool shard key for indexed channels (null for singletons). */
    public const string shardKey = 'shardKey';

    /**
     * @param int $deliveryId Captured delivery primary id
     * @param int $notificationId Notification id to deliver
     * @param string $channel Target channel name
     * @param ?int $shardKey Pool shard key for indexed channels, or null for a singleton channel
     * @throws InvalidFormatException When an id or shard is not positive, or the channel is empty
     */
    public function __construct(
        public readonly int $deliveryId,
        public readonly int $notificationId,
        public readonly string $channel,
        public readonly ?int $shardKey = null,
    ) {
        if ($deliveryId < 1 || $notificationId < 1 || $channel === '' || ($shardKey !== null && $shardKey < 1)) {
            throw new InvalidFormatException('Delivery requires positive ids, a channel and an optional positive shard key');
        }
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::deliveryId => $this->deliveryId,
            self::notificationId => $this->notificationId,
            self::channel => $this->channel,
            self::shardKey => $this->shardKey,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When delivery coordinates are absent, mistyped or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            deliveryId: self::requireInt($data, self::deliveryId),
            notificationId: self::requireInt($data, self::notificationId),
            channel: self::requireString($data, self::channel),
            shardKey: self::optionalInt($data, self::shardKey),
        );
    }
}
