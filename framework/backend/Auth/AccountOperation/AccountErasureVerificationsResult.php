<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use JsonException;
use stdClass;

/**
 * The result the users library's part of an account erasure leaves in its participant record, and its reading back (HIL-1944).
 *
 * The payload is exactly `{"verifications":<int>}`: how many code rows this step deleted - those on
 * the addresses the personal capture names and those carrying the person's user id, together. The
 * addresses themselves never enter the result. With no code to delete it is `{"verifications":0}`.
 * SCAFFOLD: no live caller until the erasure coordinator delivers the assignment (HIL-1950/HIL-1952).
 */
final class AccountErasureVerificationsResult
{
    private const string VERIFICATIONS = 'verifications';

    /**
     * @param int $deleted Code rows this step deleted
     * @return AccountOperationResultPayload Exactly `{"verifications":<int>}`
     * @throws JsonException Never for one integer; declared by the encoder
     * @throws InvalidJsonException Never for one integer; declared by the payload boundary
     * @throws InvalidFormatException Never for one integer; declared by the payload boundary
     */
    public static function payloadOf(int $deleted): AccountOperationResultPayload
    {
        return AccountOperationResultPayload::fromJson(json_encode(
            [self::VERIFICATIONS => $deleted],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param AccountOperationResultPayload $payload Saved result of the users library's erasure part
     * @return int Code rows that step deleted
     * @throws JsonException When the saved payload cannot be decoded
     * @throws InvalidFormatException When the payload is not `{"verifications":<int>}` of a non-negative integer
     */
    public static function deletedOf(AccountOperationResultPayload $payload): int
    {
        $fields = json_decode($payload->toJson(), flags: JSON_THROW_ON_ERROR);
        if (!$fields instanceof stdClass
            || array_keys(get_object_vars($fields)) !== [self::VERIFICATIONS]
            || !is_int($fields->{self::VERIFICATIONS})
            || $fields->{self::VERIFICATIONS} < 0) {
            throw new InvalidFormatException('A users library erasure result must be {"verifications":<int>}.');
        }

        return $fields->{self::VERIFICATIONS};
    }
}
