<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\DTO;

use Hilos\Auth\AccountOperation\AccountMergePersonalJoin;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Source-to-survivor transport of the loser's rows of the project's personal sets, addressed to the
 * survivor's personal receive assignment.
 *
 * The rows are the project's JSON object ({@see AccountMergePersonalJoin::personalRowsToGive()}):
 * the frame checks only that its keys are named, so a list or a key PHP reads as an integer is
 * refused. An empty object travels as an empty array.
 */
final class AccountMergePersonalHandoverSignalData extends BaseDTO implements SignalDataInterface
{
    public const string operationId = 'operationId';
    public const string stage = 'stage';
    public const string assignmentId = 'assignmentId';
    public const string ownerIndex = 'ownerIndex';
    public const string rows = 'rows';

    /** Exact transport fields in their order. */
    private const array FIELDS = [self::operationId, self::stage, self::assignmentId, self::ownerIndex, self::rows];

    /**
     * @param int $operationId Original merge operation
     * @param string $stage Immutable go stage
     * @param int $assignmentId Survivor personal receive assignment
     * @param string $ownerIndex Canonical decimal survivor id
     * @param array<string, mixed> $rows The loser's rows as its agent gave them
     * @throws InvalidFormatException When the address or the rows are malformed
     */
    public function __construct(
        public readonly int $operationId,
        public readonly string $stage,
        public readonly int $assignmentId,
        public readonly string $ownerIndex,
        public readonly array $rows,
    ) {
        if ($operationId <= 0 || $assignmentId <= 0 || $stage !== AccountMergeStage::Go->value
            || (int)$ownerIndex <= 0 || (string)(int)$ownerIndex !== $ownerIndex) {
            throw new InvalidFormatException('The personal merge handover requires its go operation and survivor receive address.');
        }
        foreach (array_keys($rows) as $key) {
            if (!is_string($key)) {
                throw new InvalidFormatException('The personal merge handover requires its rows as an object with named keys.');
            }
        }
    }

    /** @return array<string, mixed> Exact transport payload */
    public function toArray(): array
    {
        return [
            self::operationId => $this->operationId,
            self::stage => $this->stage,
            self::assignmentId => $this->assignmentId,
            self::ownerIndex => $this->ownerIndex,
            self::rows => $this->rows,
        ];
    }

    /**
     * @param array<string, mixed> $data Exact source frame fields
     * @return static Typed rows addressed to the personal receive assignment
     * @throws InvalidFormatException When keys, types or coordinates are invalid
     */
    public static function fromArray(array $data): static
    {
        if (count($data) !== count(self::FIELDS) || array_diff(self::FIELDS, array_keys($data)) !== []) {
            throw new InvalidFormatException('The personal merge handover requires exactly its declared fields.');
        }

        return new static(
            self::requireInt($data, self::operationId),
            self::requireString($data, self::stage),
            self::requireInt($data, self::assignmentId),
            self::requireString($data, self::ownerIndex),
            self::requireArray($data, self::rows),
        );
    }
}
