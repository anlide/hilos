<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\TableNotActivatedException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Notification\Delivery\DeliveryOwnerResolver;

/**
 * The notification deliveries of an account, whose owners are the channel agents of the saved deliveries.
 *
 * Each distinct owner of the account's deliveries is expected once per channel, under the
 * participant key `delivery:<channel>`; a channel whose deliveries name no owner is one wait.
 * SCAFFOLD: {@see expectationsFor()} has no live caller until the erasure coordinator opens its operation
 * (HIL-1952); the startup coverage gate reads the key and the collections.
 */
final class AccountErasureDeliveryParticipant implements AccountErasureParticipant
{
    public const string KEY = 'delivery';
    /** Between {@see KEY} and the channel in the participant key `delivery:<channel>`, which the delivery capture names too. */
    public const string CHANNEL_SEPARATOR = ':';

    private const int DELIVERIES_PER_PAGE = 1000;

    /** @return string Participant key */
    public function key(): string
    {
        return self::KEY;
    }

    /** @return list<string> The notification delivery collection */
    public function collections(): array
    {
        return [HilosDbContext::notificationDeliveries];
    }

    /**
     * @param int $userId Account of the erasure circle
     * @return list<AccountOperationExpectation> One per channel owner and one wait per ownerless channel, in delivery order
     * @throws ObjectCollectionNullException When the delivery collection is detached
     * @throws InvalidArgumentException When a page or a saved routing key cannot be read
     * @throws TableNotActivatedException When the project has not activated the delivery table
     * @throws DatabaseException When the delivery query fails
     * @throws EnvException When a channel's pool configuration is unreadable or invalid
     * @throws EmptyValueException When an owner address component is empty
     * @throws ValueTooLongException When an owner address component exceeds its limit
     */
    public function expectationsFor(int $userId): array
    {
        $expectations = [];
        $afterId = 0;
        do {
            $page = Hilos::$db->notificationDeliveries->recipientIdentitiesAfter($userId, $afterId, self::DELIVERIES_PER_PAGE);
            foreach ($page as $delivery) {
                $owner = DeliveryOwnerResolver::forDelivery($delivery);
                $expectation = new AccountOperationExpectation(
                    self::KEY . self::CHANNEL_SEPARATOR . $delivery->channel,
                    $userId,
                    $owner === null ? null : new AccountOperationOwnerAddress($owner->type, $owner->index),
                );
                $expectations[$expectation->assignmentKey()] ??= $expectation;
                $afterId = $delivery->deliveryId;
            }
        } while (count($page) === self::DELIVERIES_PER_PAGE);

        return array_values($expectations);
    }
}
