<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use JsonException;
use stdClass;

/**
 * The result the final of the person's agent in an account erasure leaves in its participant record, and its
 * reading back (HIL-1942).
 *
 * The payload is exactly `{"request":<id>}` naming the deletion request the final completed in its transaction -
 * the operation's begun request at the root, the live one of a folded account - and `{"request":null}` when the
 * account had no request to complete.
 * SCAFFOLD: no live caller until the erasure coordinator delivers the final (HIL-1950), live at the switch (HIL-1952).
 */
final readonly class AccountErasureFinalResult
{
    private const string REQUEST = 'request';

    /**
     * @param ?int $requestId Deletion request the final completed, or null when the account had none
     */
    private function __construct(public ?int $requestId)
    {
    }

    /**
     * @param ?int $requestId Deletion request the final completed, or null when the account had none
     * @return AccountOperationResultPayload Exactly `{"request":<id>}` or `{"request":null}`
     * @throws JsonException Never for one integer or null; declared by the encoder
     * @throws InvalidJsonException Never for one integer or null; declared by the payload boundary
     * @throws InvalidFormatException Never for one integer or null; declared by the payload boundary
     */
    public static function payloadOf(?int $requestId): AccountOperationResultPayload
    {
        return AccountOperationResultPayload::fromJson(json_encode(
            [self::REQUEST => $requestId],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param AccountOperationResultPayload $payload Saved result of a personal erasure final
     * @return self The request the payload names
     * @throws JsonException When the saved payload cannot be decoded
     * @throws InvalidFormatException When the payload is not `{"request":<id>}` of an integer or `{"request":null}`
     */
    public static function fromPayload(AccountOperationResultPayload $payload): self
    {
        $fields = json_decode($payload->toJson(), flags: JSON_THROW_ON_ERROR);
        if (!$fields instanceof stdClass
            || array_keys(get_object_vars($fields)) !== [self::REQUEST]
            || ($fields->{self::REQUEST} !== null && !is_int($fields->{self::REQUEST}))) {
            throw new InvalidFormatException('An account erasure final must be {"request":<id>|null}.');
        }

        return new self($fields->{self::REQUEST});
    }
}
