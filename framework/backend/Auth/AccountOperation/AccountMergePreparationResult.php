<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Hilos;
use Hilos\HilosException;
use JsonException;
use stdClass;

/** Immutable reading and writing of an account's merge preparation result (HIL-1988). */
final readonly class AccountMergePreparationResult
{
    private const string PREPARE = 'prepare';
    private const string RESERVED = 'reserved';
    private const string REFUSED = 'refused';
    private const string REFUSAL = 'refusal';
    private const string PASSWORD_IDENTITY_ID = 'passwordIdentityId';
    private const string SECOND_FACTOR_IDS = 'secondFactorIds';

    /**
     * @param ?AccountMergeRefusal $refusal Refusal code, or null for a reserved account
     * @param ?int $passwordIdentityId Reserved password identity, or null when absent
     * @param list<int> $secondFactorIds Reserved confirmed factors in ascending order without duplicates
     */
    private function __construct(
        public ?AccountMergeRefusal $refusal,
        public ?int $passwordIdentityId,
        public array $secondFactorIds,
    ) {
    }

    /**
     * @param ?int $passwordIdentityId Own password identity id, or null when absent
     * @param list<int> $secondFactorIds Own confirmed factor ids, normalized before writing
     * @return AccountOperationResultPayload Reserved preparation with positive ids in ascending order without duplicates
     * @throws InvalidFormatException When an id is not positive or factors are not a list of integers
     * @throws JsonException When the reservation cannot be encoded
     * @throws InvalidJsonException When the payload boundary cannot read the encoded object
     */
    public static function reserved(?int $passwordIdentityId, array $secondFactorIds): AccountOperationResultPayload
    {
        if ($passwordIdentityId !== null && $passwordIdentityId <= 0) {
            throw new InvalidFormatException('The reserved password identity id must be positive or null.');
        }
        if (!array_is_list($secondFactorIds)) {
            throw new InvalidFormatException('The reserved second-factor ids must be a list of positive integers.');
        }
        foreach ($secondFactorIds as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new InvalidFormatException('The reserved second-factor ids must be a list of positive integers.');
            }
        }
        $secondFactorIds = array_values(array_unique($secondFactorIds));
        sort($secondFactorIds);

        return AccountOperationResultPayload::fromJson(json_encode(
            [
                self::PREPARE => self::RESERVED,
                self::PASSWORD_IDENTITY_ID => $passwordIdentityId,
                self::SECOND_FACTOR_IDS => $secondFactorIds,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param AccountMergeRefusal $refusal Reason preparation was refused
     * @return AccountOperationResultPayload Refused preparation with only its refusal code
     * @throws JsonException When the refusal cannot be encoded
     * @throws InvalidJsonException When the payload boundary cannot read the encoded object
     * @throws InvalidFormatException When the payload boundary rejects the encoded object
     */
    public static function refused(AccountMergeRefusal $refusal): AccountOperationResultPayload
    {
        return AccountOperationResultPayload::fromJson(json_encode(
            [self::PREPARE => self::REFUSED, self::REFUSAL => $refusal->value],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param AccountOperationResultPayload $payload Saved preparation result
     * @return self Checked reservation or refusal, preserving the saved ids
     * @throws JsonException When the saved payload cannot be decoded
     * @throws InvalidFormatException When fields, refusal code or normalized positive ids are invalid
     */
    public static function of(AccountOperationResultPayload $payload): self
    {
        $object = json_decode($payload->toJson(), flags: JSON_THROW_ON_ERROR);
        if (!$object instanceof stdClass) {
            throw new InvalidFormatException('An account merge preparation result must be a JSON object.');
        }
        $fields = get_object_vars($object);
        if (($fields[self::PREPARE] ?? null) === self::RESERVED) {
            $keys = [self::PREPARE, self::PASSWORD_IDENTITY_ID, self::SECOND_FACTOR_IDS];
            if (count($fields) !== count($keys) || array_diff($keys, array_keys($fields)) !== []) {
                throw new InvalidFormatException('A reserved merge preparation requires exactly its preparation and identity fields.');
            }
            $passwordIdentityId = $fields[self::PASSWORD_IDENTITY_ID];
            $secondFactorIds = $fields[self::SECOND_FACTOR_IDS];
            if ($passwordIdentityId !== null && (!is_int($passwordIdentityId) || $passwordIdentityId <= 0)) {
                throw new InvalidFormatException('The reserved password identity id must be positive or null.');
            }
            if (!is_array($secondFactorIds) || !array_is_list($secondFactorIds)) {
                throw new InvalidFormatException('The reserved second-factor ids must be a list of positive integers.');
            }
            $previousId = 0;
            foreach ($secondFactorIds as $id) {
                if (!is_int($id) || $id <= $previousId) {
                    throw new InvalidFormatException('The reserved second-factor ids must be positive, ascending and unique.');
                }
                $previousId = $id;
            }

            return new self(null, $passwordIdentityId, $secondFactorIds);
        }
        if (($fields[self::PREPARE] ?? null) === self::REFUSED) {
            $keys = [self::PREPARE, self::REFUSAL];
            if (count($fields) !== count($keys) || array_diff($keys, array_keys($fields)) !== []) {
                throw new InvalidFormatException('A refused merge preparation requires exactly its preparation and refusal code.');
            }
            if (!is_string($fields[self::REFUSAL]) || AccountMergeRefusal::tryFrom($fields[self::REFUSAL]) === null) {
                throw new InvalidFormatException('The account merge preparation refusal code is unknown.');
            }

            return new self(AccountMergeRefusal::from($fields[self::REFUSAL]), null, []);
        }

        throw new InvalidFormatException('An account merge preparation must be reserved or refused.');
    }

    /** @return bool Whether preparation reserved the account */
    public function isReserved(): bool
    {
        return $this->refusal === null;
    }

    /**
     * @param AccountOperation $operation Merge whose preparation is being read
     * @param int $userId Survivor or loser account id
     * @return ?self Saved preparation, or null for another account, an absent assignment or an unanswered one
     * @throws HilosException When the assignment or result reader refuses the lookup
     * @throws JsonException When the saved payload cannot be decoded
     * @throws InvalidFormatException When the saved preparation has an invalid shape
     */
    public static function forAccount(AccountOperation $operation, int $userId): ?self
    {
        $duty = match ($userId) {
            $operation->survivorUserId => AccountMergeDuty::PrepareSurvivor,
            $operation->loserUserId => AccountMergeDuty::PrepareLoser,
            default => null,
        };
        if ($duty === null) {
            return null;
        }
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id,
            AccountMergeStage::Preparing->value,
            $duty->value,
        );
        if ($assignment === null) {
            return null;
        }
        $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);

        return $result === null ? null : self::of($result->payload);
    }
}
