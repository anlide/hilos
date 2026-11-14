<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** The coordinator's assignment address, delivered to its participant owner. */
final class AccountOperationAskSignalData extends BaseDTO implements SignalDataInterface
{
    public const string operationId = 'operationId';
    public const string stage = 'stage';
    public const string assignmentId = 'assignmentId';
    public const string ownerIndex = 'ownerIndex';

    /**
     * @param int $operationId Operation the assignment belongs to
     * @param string $stage Stage the assignment belongs to
     * @param int $assignmentId Stored assignment id
     * @param ?string $ownerIndex Participant instance index, or null for a singleton
     */
    public function __construct(
        public readonly int $operationId,
        public readonly string $stage,
        public readonly int $assignmentId,
        public readonly ?string $ownerIndex = null,
    ) {
    }

    /** @return array<string, mixed> Assignment address for transport */
    public function toArray(): array
    {
        return [
            self::operationId => $this->operationId,
            self::stage => $this->stage,
            self::assignmentId => $this->assignmentId,
            self::ownerIndex => $this->ownerIndex,
        ];
    }

    /**
     * @param array<string, mixed> $data Assignment address from transport
     * @return static Parsed assignment address
     * @throws InvalidFormatException When a required field is missing or a field has the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            operationId: self::requireInt($data, self::operationId),
            stage: self::requireString($data, self::stage),
            assignmentId: self::requireInt($data, self::assignmentId),
            ownerIndex: self::optionalString($data, self::ownerIndex),
        );
    }
}
