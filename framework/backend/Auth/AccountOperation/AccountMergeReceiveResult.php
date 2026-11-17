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

/** Immutable nonsecret receipt for the survivor's separate go/receive assignment. */
final readonly class AccountMergeReceiveResult
{
    public const string receive = 'receive';
    public const string RECEIVED = 'received';

    /** @return self Successful receipt without account identifiers or protection data */
    public static function received(): self
    {
        return new self();
    }

    /**
     * @param AccountOperationResultPayload $payload Saved opaque receipt
     * @return self Exact received fact
     * @throws InvalidJsonException When the saved receipt is malformed JSON
     * @throws InvalidFormatException When the saved receipt has a different shape or value
     */
    public static function of(AccountOperationResultPayload $payload): self
    {
        return self::fromJson($payload->toJson());
    }

    /**
     * @param string $json Saved nonsecret receipt
     * @return self Strict received fact
     * @throws InvalidJsonException When the receipt is malformed JSON
     * @throws InvalidFormatException When the receipt is not the exact received object
     */
    public static function fromJson(string $json): self
    {
        try {
            $object = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidJsonException('The merge receive result must be valid JSON.', previous: $failure);
        }
        if (!$object instanceof stdClass) {
            throw new InvalidFormatException('The merge receive result must be a JSON object.');
        }

        return self::fromArray(get_object_vars($object));
    }

    /**
     * @param array<string, mixed> $data Receipt fields at the JSON boundary
     * @return self Exact received fact
     * @throws InvalidFormatException When keys, type or value differ from the receipt contract
     */
    public static function fromArray(array $data): self
    {
        if (array_keys($data) !== [self::receive] || $data[self::receive] !== self::RECEIVED) {
            throw new InvalidFormatException('An account merge receive result must be {"receive":"received"}.');
        }

        return self::received();
    }

    /**
     * Reads the assignment's immutable identity even after the operation has advanced or finished.
     *
     * @param AccountOperation $operation Merge whose survivor receipt is read
     * @return ?self Exact saved receipt, or null before its assignment or result exists
     * @throws HilosException When the operation, assignment, result or payload refuses the read
     */
    public static function forOperation(AccountOperation $operation): ?self
    {
        if ($operation->kind !== AccountOperationKind::Merge
            || $operation->survivorUserId === null || $operation->survivorUserId <= 0
            || $operation->loserUserId === null || $operation->loserUserId <= 0
            || $operation->survivorUserId === $operation->loserUserId) {
            throw new InvalidFormatException('A merge receive receipt requires two distinct canonical accounts.');
        }
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Receive->value,
        );
        if ($assignment === null) {
            return null;
        }
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$operation->survivorUserId);
        if ($assignment->operationId !== $operation->id || $assignment->stage !== AccountMergeStage::Go->value
            || $assignment->assignmentKey !== AccountMergeDuty::Receive->value || $assignment->userId !== $operation->survivorUserId
            || !$assignment->owner->equals($owner) || !$assignment->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))) {
            throw new InvalidFormatException('A merge receive assignment must name its canonical survivor owner and an empty payload.');
        }
        $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($result === null) {
            return null;
        }
        if ($result->assignmentId !== $assignment->id || $result->ownerAgentId !== $owner->agentId()) {
            throw new InvalidFormatException('A merge receive result must belong to its exact assignment and survivor owner.');
        }

        return self::of($result->payload);
    }

    /**
     * @return AccountOperationResultPayload Exact nonsecret receipt at the existing opaque boundary
     * @throws JsonException When the receipt cannot be encoded
     * @throws InvalidJsonException When the opaque boundary refuses the encoded JSON
     * @throws InvalidFormatException When the opaque boundary refuses the object shape
     */
    public function toPayload(): AccountOperationResultPayload
    {
        return AccountOperationResultPayload::fromJson($this->toJson());
    }

    /**
     * @return string Exact nonsecret receipt JSON
     * @throws JsonException When the receipt cannot be encoded
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, string> Only the received fact */
    public function toArray(): array
    {
        return [self::receive => self::RECEIVED];
    }
}
