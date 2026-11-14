<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Generator;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\Exception\TableNotActivatedException;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\Delivery\DeliveryOwnerResolver;
use Hilos\Notification\Delivery\NotificationDeliveryIdentity;
use JsonException;
use stdClass;

/**
 * The portions of an account's notification deliveries the erasure coordinator hands to their
 * channel owners before the person's notifications go (HIL-1940).
 *
 * A portion is an assignment keyed `capture:delivery:<channel>:<owner agent id>:<user id>:<first
 * notification id>`, written to the owner {@see DeliveryOwnerResolver::forDelivery()} names, and
 * its payload is exactly `{"participant":"delivery:<channel>","notificationIds":[…]}`: the numbers
 * of the notifications whose deliveries on that channel are the owner's, each once, ascending. It
 * names notifications and never an address, so an owner still finds its deliveries by their saved
 * numbers once the notifications are deleted. A notification is ready to be deleted when every
 * delivery of it is named by a portion to its resolved owner; one created after a portion, or one
 * whose channel has no owner, stays uncaptured until a later portion names it, and a channel
 * without an owner is never captured: the operation waits on its census wait instead.
 * SCAFFOLD: no production caller until the erasure switch (HIL-1952).
 */
final class AccountErasureDeliveryCapture
{
    private const string PAYLOAD_PARTICIPANT = 'participant';
    private const string PAYLOAD_NOTIFICATION_IDS = 'notificationIds';
    private const int DELIVERIES_PER_PAGE = 1000;

    /**
     * SCAFFOLD: no production caller until the erasure switch (HIL-1952).
     *
     * @param string $channel Channel whose deliveries the portion names
     * @param list<int> $notificationIds Notification numbers, each once, ascending
     * @return AccountOperationAssignmentPayload Exactly `{"participant":"delivery:<channel>","notificationIds":[…]}`
     * @throws InvalidFormatException When the channel is empty or the numbers are empty, repeated or out of order
     * @throws JsonException When the channel is not valid UTF-8
     * @throws InvalidJsonException When the encoded payload is not valid JSON
     */
    public static function payloadOf(string $channel, array $notificationIds): AccountOperationAssignmentPayload
    {
        if ($channel === '' || !self::isAscendingList($notificationIds)) {
            throw self::formRefusal();
        }

        return AccountOperationAssignmentPayload::fromJson(json_encode(
            [self::PAYLOAD_PARTICIPANT => self::participantPrefix() . $channel, self::PAYLOAD_NOTIFICATION_IDS => $notificationIds],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * SCAFFOLD: no production caller until the erasure switch (HIL-1952).
     *
     * @param AccountOperationAssignmentPayload $payload Stored payload of a delivery portion
     * @return string Channel the portion names
     * @throws JsonException When the stored payload cannot be decoded
     * @throws InvalidFormatException When the payload is not the portion's exact form
     */
    public static function channelOf(AccountOperationAssignmentPayload $payload): string
    {
        return substr(self::fieldsOf($payload)->{self::PAYLOAD_PARTICIPANT}, strlen(self::participantPrefix()));
    }

    /**
     * SCAFFOLD: no production caller until the erasure switch (HIL-1952).
     *
     * @param AccountOperationAssignmentPayload $payload Stored payload of a delivery portion
     * @return list<int> Notification numbers the portion names, ascending
     * @throws JsonException When the stored payload cannot be decoded
     * @throws InvalidFormatException When the payload is not the portion's exact form
     */
    public static function notificationIdsOf(AccountOperationAssignmentPayload $payload): array
    {
        return self::fieldsOf($payload)->{self::PAYLOAD_NOTIFICATION_IDS};
    }

    /**
     * The delivery portions of one account in an erasure, at whatever stage each was assigned.
     *
     * SCAFFOLD: no production caller until the erasure switch (HIL-1952).
     *
     * @param int $operationId Erasure whose circle the account belongs to
     * @param int $userId Account whose portions are read
     * @return list<AccountOperationAssignment> Its portions in ascending id order
     * @throws HilosException When the storage reader or item wrapper refuses the lookup
     */
    public static function capturesOf(int $operationId, int $userId): array
    {
        $prefix = self::keyPrefix();
        $captures = [];
        foreach (Hilos::$db->accountOperationAssignments->forOperation($operationId) as $assignment) {
            if ($assignment->userId === $userId && str_starts_with($assignment->assignmentKey, $prefix)) {
                $captures[] = $assignment;
            }
        }

        return $captures;
    }

    /**
     * The account's notifications that are not ready to be deleted: a delivery of each has no
     * owner, or no portion to its owner names it. A notification without deliveries is ready, and
     * an installation without the delivery table has nothing uncaptured.
     *
     * SCAFFOLD: no production caller until the erasure switch (HIL-1952).
     *
     * @param int $operationId Erasure whose circle the account belongs to
     * @param int $userId Account whose notifications are read
     * @return list<int> Notification numbers, each once, ascending
     * @throws DbCollectionNotReadableException When this process does not read the delivery collection
     * @throws NamedDatabaseException When the delivery collection's database binding cannot be resolved
     * @throws HilosException When the portions cannot be read
     * @throws JsonException When a stored portion cannot be decoded
     * @throws InvalidFormatException When a stored portion is not its exact form
     * @throws ObjectCollectionNullException When the delivery collection is detached
     * @throws InvalidArgumentException When a page or a saved routing key cannot be read
     * @throws TableNotActivatedException When the delivery table is mounted but not activated
     * @throws DatabaseException When the delivery query fails
     * @throws EnvException When a channel's pool configuration is unreadable or invalid
     * @throws EmptyValueException When an owner address component is empty
     * @throws ValueTooLongException When an owner address component exceeds its limit
     */
    public static function uncapturedNotificationIdsOf(int $operationId, int $userId): array
    {
        if (!AccountOperationActivation::anyActivated([HilosDbContext::notificationDeliveries])) {
            return [];
        }
        $notificationIds = [];
        foreach (self::uncapturedDeliveriesOf($operationId, $userId) as $delivery => $owner) {
            $notificationIds[$delivery->notificationId] = true;
        }
        $ascending = array_keys($notificationIds);
        sort($ascending);

        return $ascending;
    }

    /**
     * Records one portion per channel and owner for the uncaptured deliveries of every account of
     * the circle, keyed by its first notification number; a repeat records only what a new delivery
     * left uncaptured. Deliveries without an owner are not captured and no other writer takes
     * them. Runs inside the caller's transaction.
     *
     * SCAFFOLD: no production caller until the erasure switch (HIL-1952).
     *
     * @param AccountOperation $operation Open erasure to record on, at its current stage
     * @return list<AccountOperationAssignment> Portions written, by account in circle order, then by channel and owner in delivery order
     * @throws DbCollectionNotReadableException When this process does not read the delivery collection
     * @throws NamedDatabaseException When the delivery collection's database binding cannot be resolved
     * @throws HilosException When a reader or the record refuses
     * @throws JsonException When a portion cannot be encoded or a stored one decoded
     * @throws EnvException When a channel's pool configuration is unreadable or invalid
     */
    public static function recordMissing(AccountOperation $operation): array
    {
        if (!AccountOperationActivation::anyActivated([HilosDbContext::notificationDeliveries])) {
            return [];
        }
        $recorded = [];
        foreach ($operation->circle->toArray() as $userId) {
            $owners = [];
            $notificationIds = [];
            foreach (self::uncapturedDeliveriesOf($operation->id, $userId) as $delivery => $owner) {
                if ($owner === null) {
                    continue;
                }
                $owners[$delivery->channel][$owner->agentId()] = $owner;
                $notificationIds[$delivery->channel][$owner->agentId()][$delivery->notificationId] = true;
            }
            foreach ($owners as $channel => $channelOwners) {
                foreach ($channelOwners as $agentId => $owner) {
                    $ascending = array_keys($notificationIds[$channel][$agentId]);
                    sort($ascending);
                    $recorded[] = Hilos::$db->accountOperationAssignments->actions->record(
                        $operation->id,
                        $operation->stage,
                        self::keyOf((string)$channel, $owner, $userId, $ascending[0]),
                        $owner,
                        $userId,
                        self::payloadOf((string)$channel, $ascending),
                    );
                }
            }
        }

        return $recorded;
    }

    /**
     * Walks the account's saved deliveries page by page and yields every one no portion to its
     * owner names, with that owner.
     *
     * @param int $operationId Erasure whose portions are read
     * @param int $userId Account whose deliveries are walked
     * @return Generator<NotificationDeliveryIdentity, ?AccountOperationOwnerAddress> Uncaptured deliveries in id order, each with its owner or null
     * @throws HilosException When the portions cannot be read
     * @throws JsonException When a stored portion cannot be decoded
     * @throws InvalidFormatException When a stored portion is not its exact form
     * @throws ObjectCollectionNullException When the delivery collection is detached
     * @throws InvalidArgumentException When a page or a saved routing key cannot be read
     * @throws TableNotActivatedException When the delivery table is mounted but not activated
     * @throws DatabaseException When the delivery query fails
     * @throws EnvException When a channel's pool configuration is unreadable or invalid
     * @throws EmptyValueException When an owner address component is empty
     * @throws ValueTooLongException When an owner address component exceeds its limit
     */
    private static function uncapturedDeliveriesOf(int $operationId, int $userId): Generator
    {
        $captured = [];
        foreach (self::capturesOf($operationId, $userId) as $capture) {
            $payload = $capture->payload;
            $channel = self::channelOf($payload);
            $agentId = $capture->owner->agentId();
            foreach (self::notificationIdsOf($payload) as $notificationId) {
                $captured[$channel][$agentId][$notificationId] = true;
            }
        }
        $afterId = 0;
        do {
            $page = Hilos::$db->notificationDeliveries->recipientIdentitiesAfter($userId, $afterId, self::DELIVERIES_PER_PAGE);
            foreach ($page as $delivery) {
                $resolved = DeliveryOwnerResolver::forDelivery($delivery);
                $owner = $resolved === null ? null : new AccountOperationOwnerAddress($resolved->type, $resolved->index);
                if ($owner === null || !isset($captured[$delivery->channel][$owner->agentId()][$delivery->notificationId])) {
                    yield $delivery => $owner;
                }
                $afterId = $delivery->deliveryId;
            }
        } while (count($page) === self::DELIVERIES_PER_PAGE);
    }

    /**
     * @param AccountOperationAssignmentPayload $payload Stored payload of a delivery portion
     * @return stdClass The payload's two fields, checked
     * @throws JsonException When the stored payload cannot be decoded
     * @throws InvalidFormatException When the payload is not the portion's exact form
     */
    private static function fieldsOf(AccountOperationAssignmentPayload $payload): stdClass
    {
        $fields = json_decode($payload->toJson(), flags: JSON_THROW_ON_ERROR);
        if (!$fields instanceof stdClass
            || array_keys(get_object_vars($fields)) !== [self::PAYLOAD_PARTICIPANT, self::PAYLOAD_NOTIFICATION_IDS]
            || !is_string($fields->{self::PAYLOAD_PARTICIPANT})
            || !str_starts_with($fields->{self::PAYLOAD_PARTICIPANT}, self::participantPrefix())
            || $fields->{self::PAYLOAD_PARTICIPANT} === self::participantPrefix()
            || !is_array($fields->{self::PAYLOAD_NOTIFICATION_IDS})
            || !self::isAscendingList($fields->{self::PAYLOAD_NOTIFICATION_IDS})) {
            throw self::formRefusal();
        }

        return $fields;
    }

    /**
     * @param array<mixed> $notificationIds Candidate notification numbers
     * @return bool Whether they are a nonempty list of integers, each greater than the one before
     */
    private static function isAscendingList(array $notificationIds): bool
    {
        if ($notificationIds === [] || !array_is_list($notificationIds)) {
            return false;
        }
        $previous = null;
        foreach ($notificationIds as $notificationId) {
            if (!is_int($notificationId) || ($previous !== null && $notificationId <= $previous)) {
                return false;
            }
            $previous = $notificationId;
        }

        return true;
    }

    /** @return string Start of a channel's census participant, `delivery:`, which the channel follows */
    private static function participantPrefix(): string
    {
        return AccountErasureDeliveryParticipant::KEY . AccountErasureDeliveryParticipant::CHANNEL_SEPARATOR;
    }

    /** @return string Start of every portion's key, `capture:delivery:` */
    private static function keyPrefix(): string
    {
        return AccountErasureCapture::CAPTURE_PREFIX . AccountErasureCapture::ASSIGNMENT_KEY_SEPARATOR . self::participantPrefix();
    }

    /**
     * @param string $channel Channel of the portion
     * @param AccountOperationOwnerAddress $owner Owner the portion is written to
     * @param int $userId Account of the circle
     * @param int $firstNotificationId Lowest notification number the portion names
     * @return string Key `capture:delivery:<channel>:<owner agent id>:<user id>:<first notification id>`
     */
    private static function keyOf(string $channel, AccountOperationOwnerAddress $owner, int $userId, int $firstNotificationId): string
    {
        return implode(
            AccountErasureCapture::ASSIGNMENT_KEY_SEPARATOR,
            [self::keyPrefix() . $channel, $owner->agentId(), (string)$userId, (string)$firstNotificationId],
        );
    }

    /** @return InvalidFormatException The refusal of a portion that is not its exact form */
    private static function formRefusal(): InvalidFormatException
    {
        return new InvalidFormatException(
            'An account erasure delivery capture must be {"participant":"delivery:<channel>","notificationIds":[…]}.',
        );
    }
}
