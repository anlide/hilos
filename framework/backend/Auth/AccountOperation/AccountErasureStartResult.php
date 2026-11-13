<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use JsonException;
use stdClass;

/**
 * The result the start of an account erasure leaves in its participant record, and its reading back (HIL-1939).
 *
 * The payload is exactly `{"start":"started"}` when this step marked the deletion request begun,
 * and `{"start":"lost"}` when it did not: "Keep my account" won, or the request had begun already.
 * SCAFFOLD: no live caller until HIL-1950/HIL-1952.
 */
final class AccountErasureStartResult
{
    private const string START = 'start';
    private const string STARTED = 'started';
    private const string LOST = 'lost';

    /**
     * @param bool $started Whether this step marked the deletion request begun
     * @return AccountOperationResultPayload Exactly `{"start":"started"}` or `{"start":"lost"}`
     * @throws JsonException Never for the two fixed words; declared by the encoder
     * @throws InvalidJsonException Never for the two fixed words; declared by the payload boundary
     * @throws InvalidFormatException Never for the two fixed words; declared by the payload boundary
     */
    public static function payloadOf(bool $started): AccountOperationResultPayload
    {
        return AccountOperationResultPayload::fromJson(json_encode(
            [self::START => $started ? self::STARTED : self::LOST],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param AccountOperationResultPayload $payload Saved result of an account erasure start
     * @return bool Whether that start marked the deletion request begun
     * @throws JsonException When the saved payload cannot be decoded
     * @throws InvalidFormatException When the payload is neither `{"start":"started"}` nor `{"start":"lost"}`
     */
    public static function startedOf(AccountOperationResultPayload $payload): bool
    {
        $fields = json_decode($payload->toJson(), flags: JSON_THROW_ON_ERROR);
        if (!$fields instanceof stdClass
            || array_keys(get_object_vars($fields)) !== [self::START]
            || !in_array($fields->{self::START}, [self::STARTED, self::LOST], true)) {
            throw new InvalidFormatException('An account erasure start result must be {"start":"started"} or {"start":"lost"}.');
        }

        return $fields->{self::START} === self::STARTED;
    }
}
