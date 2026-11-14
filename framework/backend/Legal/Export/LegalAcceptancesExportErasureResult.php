<?php

declare(strict_types=1);

namespace Hilos\Legal\Export;

use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use JsonException;
use stdClass;

/**
 * The result the legal agent's part of an account erasure leaves in its participant record, and its reading back (HIL-1947).
 *
 * The payload is exactly `{"ownOrders":<int>,"sharedExports":<int>}`: how many export orders of the
 * erased account this step deleted - one at most, as an administrator has one order - and how many
 * finished exports of any administrator it deleted because they may list the account's acceptances.
 * SCAFFOLD: no production caller until the erasure switch (HIL-1952).
 */
final class LegalAcceptancesExportErasureResult
{
    private const string OWN_ORDERS = 'ownOrders';
    private const string SHARED_EXPORTS = 'sharedExports';

    /**
     * @param int $ownOrders Export orders of the erased account this step deleted
     * @param int $sharedExports Finished exports of other administrators this step deleted
     * @return AccountOperationResultPayload Exactly `{"ownOrders":<int>,"sharedExports":<int>}`
     * @throws JsonException Never for two integers; declared by the encoder
     * @throws InvalidJsonException Never for two integers; declared by the payload boundary
     * @throws InvalidFormatException Never for two integers; declared by the payload boundary
     */
    public static function payloadOf(int $ownOrders, int $sharedExports): AccountOperationResultPayload
    {
        return AccountOperationResultPayload::fromJson(json_encode(
            [self::OWN_ORDERS => $ownOrders, self::SHARED_EXPORTS => $sharedExports],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param AccountOperationResultPayload $payload Saved result of the legal agent's erasure part
     * @return array{ownOrders: int, sharedExports: int} The two counts that step recorded
     * @throws JsonException When the saved payload cannot be decoded
     * @throws InvalidFormatException When the payload is not `{"ownOrders":<int>,"sharedExports":<int>}` of non-negative integers
     */
    public static function countsOf(AccountOperationResultPayload $payload): array
    {
        $fields = json_decode($payload->toJson(), flags: JSON_THROW_ON_ERROR);
        if (!$fields instanceof stdClass
            || array_keys(get_object_vars($fields)) !== [self::OWN_ORDERS, self::SHARED_EXPORTS]
            || !is_int($fields->{self::OWN_ORDERS})
            || $fields->{self::OWN_ORDERS} < 0
            || !is_int($fields->{self::SHARED_EXPORTS})
            || $fields->{self::SHARED_EXPORTS} < 0) {
            throw new InvalidFormatException('A legal export erasure result must be {"ownOrders":<int>,"sharedExports":<int>}.');
        }

        return [self::OWN_ORDERS => $fields->{self::OWN_ORDERS}, self::SHARED_EXPORTS => $fields->{self::SHARED_EXPORTS}];
    }
}
