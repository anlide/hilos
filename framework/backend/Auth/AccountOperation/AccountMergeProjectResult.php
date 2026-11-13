<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use JsonException;
use stdClass;

/**
 * The result a project owner's merge part leaves in its participant record, and its reading back.
 *
 * The payload is exactly `{"rows":{"<family>":<int>,…}}`: the rows the owner moved from the loser
 * to the survivor per family it names. With nothing moved it is `{"rows":{}}` - `rows` stays a
 * JSON object even when empty.
 * SCAFFOLD: no live caller until the merge coordinator asks its owners (HIL-2000), live at the
 * merge switch (HIL-1999).
 */
final class AccountMergeProjectResult
{
    private const string ROWS = 'rows';

    /**
     * @param array<array-key, mixed> $rowsMoved Owner's answer, checked here: rows moved per family it names
     * @return AccountOperationResultPayload Exactly `{"rows":{…}}`
     * @throws InvalidFormatException When a family is not named by a string or its count is not a non-negative integer
     * @throws JsonException When a family name is not valid UTF-8
     * @throws InvalidJsonException When the encoded payload is not valid JSON
     */
    public static function payloadOf(array $rowsMoved): AccountOperationResultPayload
    {
        foreach ($rowsMoved as $family => $rows) {
            if (!is_string($family) || !is_int($rows) || $rows < 0) {
                throw new InvalidFormatException('An account merge part must count the rows it moved per family in non-negative integers.');
            }
        }

        return AccountOperationResultPayload::fromJson(json_encode(
            [self::ROWS => (object)$rowsMoved],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param AccountOperationResultPayload $payload Saved result of a project owner's merge part
     * @return array<string, int> Rows moved per family the payload names
     * @throws JsonException When the saved payload cannot be decoded
     * @throws InvalidFormatException When the payload is not `{"rows":{…}}` of integers
     */
    public static function rowsMovedOf(AccountOperationResultPayload $payload): array
    {
        $fields = json_decode($payload->toJson(), flags: JSON_THROW_ON_ERROR);
        if (!$fields instanceof stdClass
            || array_keys(get_object_vars($fields)) !== [self::ROWS]
            || !$fields->{self::ROWS} instanceof stdClass
            || array_filter(get_object_vars($fields->{self::ROWS}), static fn(mixed $rows): bool => !is_int($rows)) !== []) {
            throw new InvalidFormatException('An account merge result must be {"rows":{…}}.');
        }

        return get_object_vars($fields->{self::ROWS});
    }
}
