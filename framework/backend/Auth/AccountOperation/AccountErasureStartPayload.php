<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use JsonException;
use stdClass;

/** The root's immutable start intention, including whether its owner must expire the grace period. */
final class AccountErasureStartPayload
{
    private const string PARTICIPANT = 'participant';
    private const string EXPIRE_GRACE = 'expireGrace';

    /**
     * @param bool $expireGrace Whether the personal owner expires the grace period before starting
     * @return AccountOperationAssignmentPayload Exactly the personal participant and its boolean start mode
     * @throws JsonException Never for fixed keys and a boolean; declared by the encoder
     * @throws InvalidJsonException Never for the encoded object; declared by the payload boundary
     * @throws InvalidFormatException Never for the encoded object; declared by the payload boundary
     */
    public static function payloadOf(bool $expireGrace): AccountOperationAssignmentPayload
    {
        return AccountOperationAssignmentPayload::fromJson(json_encode(
            [self::PARTICIPANT => AccountErasureParticipantCatalog::PERSONAL, self::EXPIRE_GRACE => $expireGrace],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param AccountOperationAssignmentPayload $payload Saved intention of the root account's start
     * @return bool Whether the personal owner must expire the grace period
     * @throws JsonException When the saved payload cannot be decoded
     * @throws InvalidFormatException When the payload is not exactly the personal participant and a boolean expireGrace
     */
    public static function expireGraceOf(AccountOperationAssignmentPayload $payload): bool
    {
        $fields = json_decode($payload->toJson(), flags: JSON_THROW_ON_ERROR);
        if (!$fields instanceof stdClass
            || count(get_object_vars($fields)) !== 2
            || ($fields->{self::PARTICIPANT} ?? null) !== AccountErasureParticipantCatalog::PERSONAL
            || !is_bool($fields->{self::EXPIRE_GRACE} ?? null)) {
            throw new InvalidFormatException('An account erasure start must name the personal participant and a boolean expireGrace only.');
        }

        return $fields->{self::EXPIRE_GRACE};
    }
}
