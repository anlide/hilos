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

/** Immutable nonsecret cleanup fact for the loser's separate go/cleanup assignment. */
final readonly class AccountMergeCleanupResult
{
    public const string cleanup = 'cleanup';
    public const string CLEANED = 'cleaned';

    /** @return self Successful cleanup fact without account identifiers or protection data */
    public static function cleaned(): self
    {
        return new self();
    }

    /**
     * @param AccountOperationResultPayload $payload Saved opaque cleanup fact
     * @return self Exact cleaned fact
     * @throws InvalidJsonException When the saved cleanup fact is malformed JSON
     * @throws InvalidFormatException When the saved cleanup fact has a different shape or value
     */
    public static function of(AccountOperationResultPayload $payload): self
    {
        return self::fromJson($payload->toJson());
    }

    /**
     * @param string $json Saved nonsecret cleanup fact
     * @return self Strict cleaned fact
     * @throws InvalidJsonException When the cleanup fact is malformed JSON
     * @throws InvalidFormatException When the cleanup fact is not the exact cleaned object
     */
    public static function fromJson(string $json): self
    {
        try {
            $object = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidJsonException('The merge cleanup result must be valid JSON.', previous: $failure);
        }
        if (!$object instanceof stdClass) {
            throw new InvalidFormatException('The merge cleanup result must be a JSON object.');
        }

        return self::fromArray(get_object_vars($object));
    }

    /**
     * @param array<string, mixed> $data Cleanup fields at the JSON boundary
     * @return self Exact cleaned fact
     * @throws InvalidFormatException When keys, type or value differ from the cleanup fact contract
     */
    public static function fromArray(array $data): self
    {
        if (array_keys($data) !== [self::cleanup] || $data[self::cleanup] !== self::CLEANED) {
            throw new InvalidFormatException('An account merge cleanup result must be {"cleanup":"cleaned"}.');
        }

        return self::cleaned();
    }

    /**
     * Reads the assignment's immutable identity even after the operation has advanced or finished.
     *
     * @param AccountOperation $operation Merge whose loser cleanup fact is read
     * @return ?self Exact saved cleanup fact, or null before its assignment or result exists
     * @throws HilosException When the operation, assignment, result or payload refuses the read
     */
    public static function forOperation(AccountOperation $operation): ?self
    {
        if ($operation->kind !== AccountOperationKind::Merge
            || $operation->survivorUserId === null || $operation->survivorUserId <= 0
            || $operation->loserUserId === null || $operation->loserUserId <= 0
            || $operation->survivorUserId === $operation->loserUserId) {
            throw new InvalidFormatException('A merge cleanup fact requires two distinct canonical accounts.');
        }
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Cleanup->value,
        );
        if ($assignment === null) {
            return null;
        }
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$operation->loserUserId);
        if ($assignment->operationId !== $operation->id || $assignment->stage !== AccountMergeStage::Go->value
            || $assignment->assignmentKey !== AccountMergeDuty::Cleanup->value || $assignment->userId !== $operation->loserUserId
            || !$assignment->owner->equals($owner) || !$assignment->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))) {
            throw new InvalidFormatException('A merge cleanup assignment must name its canonical loser owner and an empty payload.');
        }
        $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($result === null) {
            return null;
        }
        if ($result->assignmentId !== $assignment->id || $result->ownerAgentId !== $owner->agentId()) {
            throw new InvalidFormatException('A merge cleanup result must belong to its exact assignment and loser owner.');
        }

        return self::of($result->payload);
    }

    /**
     * @return AccountOperationResultPayload Exact nonsecret cleanup fact at the existing opaque boundary
     * @throws JsonException When the cleanup fact cannot be encoded
     * @throws InvalidJsonException When the opaque boundary refuses the encoded JSON
     * @throws InvalidFormatException When the opaque boundary refuses the object shape
     */
    public function toPayload(): AccountOperationResultPayload
    {
        return AccountOperationResultPayload::fromJson($this->toJson());
    }

    /**
     * @return string Exact nonsecret cleanup fact JSON
     * @throws JsonException When the cleanup fact cannot be encoded
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, string> Only the cleaned fact */
    public function toArray(): array
    {
        return [self::cleanup => self::CLEANED];
    }
}
