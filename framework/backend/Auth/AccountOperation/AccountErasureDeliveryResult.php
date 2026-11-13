<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use JsonException;
use stdClass;

/**
 * The immutable count of journal rows deleted by one delivery channel/shard's erasure part (HIL-1945).
 * SCAFFOLD: no live caller until the erasure coordinator delivers the assignment (HIL-1950/HIL-1952).
 */
final class AccountErasureDeliveryResult
{
    private const string DELIVERIES = 'deliveries';

    /**
     * @param int $deliveriesDeleted Delivery journal rows this step deleted
     * @return AccountOperationResultPayload Exactly `{"deliveries":<int>}`
     * @throws JsonException Never for one integer; declared by the encoder
     * @throws InvalidJsonException Never for one integer; declared by the payload boundary
     * @throws InvalidFormatException Never for one integer; declared by the payload boundary
     */
    public static function payloadOf(int $deliveriesDeleted): AccountOperationResultPayload
    {
        return AccountOperationResultPayload::fromJson(json_encode(
            [self::DELIVERIES => $deliveriesDeleted],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param AccountOperationResultPayload $payload Saved result of the delivery erasure part
     * @return int Delivery journal rows that step deleted
     * @throws JsonException When the saved payload cannot be decoded
     * @throws InvalidFormatException When the payload is not exactly one nonnegative integer delivery count
     */
    public static function deliveriesOf(AccountOperationResultPayload $payload): int
    {
        $fields = json_decode($payload->toJson(), flags: JSON_THROW_ON_ERROR);
        if (!$fields instanceof stdClass
            || array_keys(get_object_vars($fields)) !== [self::DELIVERIES]
            || !is_int($fields->{self::DELIVERIES})
            || $fields->{self::DELIVERIES} < 0) {
            throw new InvalidFormatException('An account erasure delivery result must be {"deliveries":<int>}.');
        }

        return $fields->{self::DELIVERIES};
    }
}
