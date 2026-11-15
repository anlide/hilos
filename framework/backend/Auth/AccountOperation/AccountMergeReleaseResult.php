<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Hilos;
use Hilos\HilosException;
use JsonException;
use stdClass;

/** Strict immutable release result for either side of a canceled merge. */
final class AccountMergeReleaseResult
{
    private const string RELEASE = 'release';
    private const string RELEASED = 'released';

    /**
     * @return AccountOperationResultPayload Exactly `{"release":"released"}`
     * @throws JsonException Never for the fixed words; declared by the encoder
     * @throws InvalidJsonException Never for the fixed words; declared by the payload boundary
     * @throws InvalidFormatException Never for the fixed words; declared by the payload boundary
     */
    public static function released(): AccountOperationResultPayload
    {
        return AccountOperationResultPayload::fromJson(json_encode(
            [self::RELEASE => self::RELEASED],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param AccountOperationResultPayload $payload Saved participant result
     * @return bool True for the exact release form
     * @throws JsonException When the saved payload cannot be decoded
     * @throws InvalidFormatException When the saved result is not exactly `{"release":"released"}`
     */
    public static function releasedOf(AccountOperationResultPayload $payload): bool
    {
        $fields = json_decode($payload->toJson(), flags: JSON_THROW_ON_ERROR);
        if (!$fields instanceof stdClass
            || array_keys(get_object_vars($fields)) !== [self::RELEASE]
            || $fields->{self::RELEASE} !== self::RELEASED) {
            throw new InvalidFormatException('An account merge release result must be {"release":"released"}.');
        }

        return true;
    }

    /**
     * @param AccountOperation $operation Merge whose release is read
     * @param int $userId Account on either immutable side
     * @return bool Whether this side has its saved release result
     * @throws HilosException When the assignment or result reader refuses the lookup
     * @throws JsonException When the saved payload cannot be decoded
     * @throws InvalidFormatException When the release assignment address or result form is invalid
     */
    public static function forAccount(AccountOperation $operation, int $userId): bool
    {
        $duty = match ($userId) {
            $operation->loserUserId => AccountMergeDuty::ReleaseLoser,
            $operation->survivorUserId => AccountMergeDuty::ReleaseSurvivor,
            default => null,
        };
        if ($duty === null) {
            return false;
        }
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id, AccountMergeStage::Cancelling->value, $duty->value,
        );
        if ($assignment === null) {
            return false;
        }
        if ($assignment->ownerType !== HilosAgentType::HILOS_USER
            || $assignment->ownerIndex !== (string)$userId || $assignment->userId !== $userId) {
            throw new InvalidFormatException('An account merge release assignment must name its own account owner.');
        }
        $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);

        return $result !== null && self::releasedOf($result->payload);
    }
}
