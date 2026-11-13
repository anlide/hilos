<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use JsonException;
use stdClass;

/**
 * The result the data copy agent's part of an account erasure leaves in its participant record, and its reading back (HIL-1946).
 *
 * The payload is exactly `{"orders":<int>}`: how many data copy orders this step deleted - one at
 * most, as a person has one order. With no order to delete it is `{"orders":0}`.
 * SCAFFOLD: no live caller until the erasure coordinator delivers the assignment (HIL-1950/HIL-1952).
 */
final class AccountErasureDataExportResult
{
    private const string ORDERS = 'orders';

    /**
     * @param int $ordersDeleted Data copy orders this step deleted
     * @return AccountOperationResultPayload Exactly `{"orders":<int>}`
     * @throws JsonException Never for one integer; declared by the encoder
     * @throws InvalidJsonException Never for one integer; declared by the payload boundary
     * @throws InvalidFormatException Never for one integer; declared by the payload boundary
     */
    public static function payloadOf(int $ordersDeleted): AccountOperationResultPayload
    {
        return AccountOperationResultPayload::fromJson(json_encode(
            [self::ORDERS => $ordersDeleted],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param AccountOperationResultPayload $payload Saved result of the data copy agent's erasure part
     * @return int Data copy orders that step deleted
     * @throws JsonException When the saved payload cannot be decoded
     * @throws InvalidFormatException When the payload is not `{"orders":<int>}` of a non-negative integer
     */
    public static function ordersOf(AccountOperationResultPayload $payload): int
    {
        $fields = json_decode($payload->toJson(), flags: JSON_THROW_ON_ERROR);
        if (!$fields instanceof stdClass
            || array_keys(get_object_vars($fields)) !== [self::ORDERS]
            || !is_int($fields->{self::ORDERS})
            || $fields->{self::ORDERS} < 0) {
            throw new InvalidFormatException('A data export erasure result must be {"orders":<int>}.');
        }

        return $fields->{self::ORDERS};
    }
}
