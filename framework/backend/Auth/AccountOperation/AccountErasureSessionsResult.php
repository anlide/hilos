<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use JsonException;
use stdClass;

/**
 * The result the session holder's part of an account erasure leaves in its participant record, and its reading back (HIL-1943).
 *
 * The payload is exactly `{"sessions":<int>,"accessLog":<int>}`: how many different sessions the
 * holder changed - a wait released, a session made a guest's, an "Access closed" card lowered -
 * and how many access log rows it deleted. With nothing to release it is `{"sessions":0,"accessLog":0}`.
 * SCAFFOLD: no production caller until the erasure switch (HIL-1952).
 */
final readonly class AccountErasureSessionsResult
{
    private const string SESSIONS = 'sessions';
    private const string ACCESS_LOG = 'accessLog';

    /**
     * @param int $sessions Different sessions the holder changed
     * @param int $accessLog Access log rows the holder deleted
     */
    public function __construct(
        public int $sessions,
        public int $accessLog,
    ) {
    }

    /**
     * @param AccountOperationResultPayload $payload Saved result of the session holder's erasure part
     * @return self The two counts the payload names
     * @throws JsonException When the saved payload cannot be decoded
     * @throws InvalidFormatException When the payload is not `{"sessions":<int>,"accessLog":<int>}` of non-negative integers
     */
    public static function fromPayload(AccountOperationResultPayload $payload): self
    {
        $fields = json_decode($payload->toJson(), flags: JSON_THROW_ON_ERROR);
        if (!$fields instanceof stdClass
            || array_keys(get_object_vars($fields)) !== [self::SESSIONS, self::ACCESS_LOG]
            || !is_int($fields->{self::SESSIONS})
            || $fields->{self::SESSIONS} < 0
            || !is_int($fields->{self::ACCESS_LOG})
            || $fields->{self::ACCESS_LOG} < 0) {
            throw new InvalidFormatException('A session holder erasure result must be {"sessions":<int>,"accessLog":<int>}.');
        }

        return new self($fields->{self::SESSIONS}, $fields->{self::ACCESS_LOG});
    }

    /**
     * @return AccountOperationResultPayload Exactly `{"sessions":<int>,"accessLog":<int>}`
     * @throws JsonException Never for two integers; declared by the encoder
     * @throws InvalidJsonException Never for two integers; declared by the payload boundary
     * @throws InvalidFormatException Never for two integers; declared by the payload boundary
     */
    public function payload(): AccountOperationResultPayload
    {
        return AccountOperationResultPayload::fromJson(json_encode(
            [self::SESSIONS => $this->sessions, self::ACCESS_LOG => $this->accessLog],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
