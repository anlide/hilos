<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use JsonException;
use stdClass;

/** Immutable nonsecret counts for either of the session holder's merge duties. */
final readonly class AccountMergeSessionsResult
{
    private const string SESSIONS = 'sessions';
    private const string ACCESS_LOG = 'accessLog';

    /**
     * @param int $sessions Number of distinct sessions changed
     * @param int $accessLog Number of access log rows transferred
     * @throws InvalidFormatException When either count is negative
     */
    public function __construct(public int $sessions, public int $accessLog)
    {
        if ($sessions < 0 || $accessLog < 0) {
            throw new InvalidFormatException('Merge session counts must be nonnegative integers.');
        }
    }

    /**
     * @param AccountOperationResultPayload $payload Saved holder result
     * @return self Exactly the two checked counts
     * @throws JsonException When the opaque payload cannot be decoded
     * @throws InvalidFormatException When the keys, types or counts violate the result contract
     */
    public static function of(AccountOperationResultPayload $payload): self
    {
        $fields = json_decode($payload->toJson(), flags: JSON_THROW_ON_ERROR);
        if (!$fields instanceof stdClass
            || count(get_object_vars($fields)) !== 2
            || !isset($fields->{self::SESSIONS}, $fields->{self::ACCESS_LOG})
            || !is_int($fields->{self::SESSIONS}) || !is_int($fields->{self::ACCESS_LOG})) {
            throw new InvalidFormatException('A merge session result requires exactly sessions and accessLog integer counts.');
        }

        return new self($fields->{self::SESSIONS}, $fields->{self::ACCESS_LOG});
    }

    /**
     * @return AccountOperationResultPayload Exactly the session and access log counts
     * @throws JsonException When the counts cannot be encoded
     * @throws InvalidJsonException When the opaque boundary refuses the JSON
     * @throws InvalidFormatException When the opaque boundary refuses the object
     */
    public function toPayload(): AccountOperationResultPayload
    {
        return AccountOperationResultPayload::fromJson(json_encode(
            [self::SESSIONS => $this->sessions, self::ACCESS_LOG => $this->accessLog], JSON_THROW_ON_ERROR,
        ));
    }
}
