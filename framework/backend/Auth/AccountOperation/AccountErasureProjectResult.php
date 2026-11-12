<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Users\AccountErasure;
use JsonException;
use stdClass;

/**
 * The result a project owner's erasure part leaves in its participant record, and its reading back.
 *
 * The payload is exactly `{"rows":{"<family>":<int>,…},"files":[<int>,…]}`: the rows the owner
 * deleted per family it names, and the registry files those rows pointed at. With nothing erased
 * it is `{"rows":{},"files":[]}` - `rows` stays a JSON object even when empty.
 * SCAFFOLD: no live caller until the erasure coordinator opens its operation (HIL-1952).
 */
final class AccountErasureProjectResult
{
    private const string ROWS = 'rows';
    private const string FILES = 'files';

    /**
     * @param AccountErasure $erasure Owner's answer for one account
     * @return AccountOperationResultPayload Exactly `{"rows":{…},"files":[…]}`
     * @throws JsonException When a family name is not valid UTF-8
     * @throws InvalidJsonException When the encoded payload is not valid JSON
     * @throws InvalidFormatException When the encoded payload is not a JSON object
     */
    public static function payloadOf(AccountErasure $erasure): AccountOperationResultPayload
    {
        return AccountOperationResultPayload::fromJson(json_encode(
            [self::ROWS => (object)$erasure->rowsErased, self::FILES => array_values($erasure->fileIds)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param AccountOperationResultPayload $payload Saved result of a project owner's erasure part
     * @return AccountErasure Rows per family and the registry files the payload names
     * @throws JsonException When the saved payload cannot be decoded
     * @throws InvalidFormatException When the payload is not `{"rows":{…},"files":[…]}` of integers
     */
    public static function erasureOf(AccountOperationResultPayload $payload): AccountErasure
    {
        $fields = json_decode($payload->toJson(), flags: JSON_THROW_ON_ERROR);
        if (!$fields instanceof stdClass
            || array_diff(array_keys(get_object_vars($fields)), [self::ROWS, self::FILES]) !== []
            || !isset($fields->{self::ROWS}, $fields->{self::FILES})
            || !$fields->{self::ROWS} instanceof stdClass
            || !is_array($fields->{self::FILES})
            || !array_is_list($fields->{self::FILES})) {
            throw new InvalidFormatException('An account erasure result must be {"rows":{…},"files":[…]}.');
        }
        $rowsErased = get_object_vars($fields->{self::ROWS});
        foreach ([...array_values($rowsErased), ...$fields->{self::FILES}] as $number) {
            if (!is_int($number)) {
                throw new InvalidFormatException('An account erasure result must count rows and name files in integers.');
            }
        }

        return new AccountErasure($rowsErased, $fields->{self::FILES});
    }
}
