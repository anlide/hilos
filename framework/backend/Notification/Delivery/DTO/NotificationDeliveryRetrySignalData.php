<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Notification\DTO\DeliveryRetrySignalData;
use Hilos\Notification\Delivery\NotificationDeliveryIdentity;

/**
 * Notifications library → the channel that owns the delivery row: take this failed delivery back (HIL-1910).
 *
 * What the three channel retry signals carry ({@see HilosSignalConstants::HILOS_MAIL_DELIVERY_RETRY},
 * {@see HilosSignalConstants::HILOS_SMS_DELIVERY_RETRY} and
 * {@see HilosSignalConstants::HILOS_PUSH_DELIVERY_RETRY}). The library finds the owner by the
 * identity the row was saved with and passes the admin's request on; the owner checks that the
 * coordinates are still its own, resets the row and answers the page.
 *
 * {@see deliveryId}, {@see notificationId}, {@see channel} and {@see routingKey} are a snapshot of the
 * persisted identity ({@see NotificationDeliveryIdentity}), taken before the hop, so the owner can
 * refuse a row that is no longer the one the request named. {@see shardKey} is the reduction of that
 * routing key against the pool width as it is now - the field an indexed channel routes on - and is
 * null for a singleton channel. The routing key itself may be 0, or null for a legacy row.
 *
 * The rest is the admin waiting, copied unchanged from the page's own ask
 * ({@see DeliveryRetrySignalData}, {@see HandoverAskInterface}): whom to answer, on which request,
 * the action the ack is addressed to and the name the answer travels under.
 */
final class NotificationDeliveryRetrySignalData extends BaseDTO implements HandoverAskInterface
{
    /** Payload key: delivery primary id. */
    public const string deliveryId = 'deliveryId';

    /** Payload key: id of the notification the delivery was saved for. */
    public const string notificationId = 'notificationId';

    /** Payload key: channel name saved on the row. */
    public const string channel = 'channel';

    /** Payload key: unreduced source key saved on the row, or null for a legacy row. */
    public const string routingKey = 'routingKey';

    /** Payload key: current one-based pool index of the routing key, or null for a singleton. */
    public const string shardKey = 'shardKey';

    /** Payload key: agent-signal name the owner answers the page under. */
    public const string replySignal = 'replySignal';

    /** Payload key: accept key of the connection that asked. */
    public const string acceptKey = 'acceptKey';

    /** Payload key: client-minted request id of the tracked submit, or null when untracked. */
    public const string requestId = 'requestId';

    /** Payload key: browser action name the ack is addressed to. */
    public const string action = 'action';

    /** Payload key: sentence to speak on success, or null where the gesture has none. */
    public const string successMessage = 'successMessage';

    /**
     * @param int $deliveryId Delivery primary id
     * @param int $notificationId Id of the notification the delivery was saved for
     * @param string $channel Channel name saved on the row
     * @param ?int $routingKey Unreduced source key saved on the row (0 allowed), or null for a legacy row
     * @param ?int $shardKey Current one-based pool index of the routing key, or null for a singleton channel
     * @param string $replySignal Agent signal the owner answers the page under
     * @param string $acceptKey Accept key of the connection that asked, and the origin of the write
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, or null where the gesture has none
     * @throws InvalidFormatException When an id or a shard is not positive, the routing key is negative or the channel is empty
     */
    public function __construct(
        public readonly int $deliveryId,
        public readonly int $notificationId,
        public readonly string $channel,
        public readonly ?int $routingKey,
        public readonly ?int $shardKey,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
        if (
            $deliveryId < 1
            || $notificationId < 1
            || $channel === ''
            || ($routingKey !== null && $routingKey < 0)
            || ($shardKey !== null && $shardKey < 1)
        ) {
            throw new InvalidFormatException(
                'Delivery retry requires positive ids, a channel, a nonnegative routing key and an optional positive shard key',
            );
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Delivery retry ask
     * @throws InvalidFormatException When the identity, the shard or the waiting submit is absent, mistyped or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            deliveryId: self::requireInt($data, self::deliveryId),
            notificationId: self::requireInt($data, self::notificationId),
            channel: self::requireString($data, self::channel),
            routingKey: self::optionalInt($data, self::routingKey),
            shardKey: self::optionalInt($data, self::shardKey),
            replySignal: self::requireString($data, self::replySignal),
            acceptKey: self::requireString($data, self::acceptKey),
            requestId: self::optionalString($data, self::requestId),
            action: self::requireString($data, self::action),
            successMessage: self::optionalString($data, self::successMessage),
        );
    }

    /**
     * @return array<string, int|string|null> Transport payload
     */
    public function toArray(): array
    {
        return [
            self::deliveryId => $this->deliveryId,
            self::notificationId => $this->notificationId,
            self::channel => $this->channel,
            self::routingKey => $this->routingKey,
            self::shardKey => $this->shardKey,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
