<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\DTO;

use Hilos\Auth\AccountOperation\AccountMergeAdmissionManifest;
use Hilos\Auth\AccountOperation\AccountMergeHandover;
use Hilos\Auth\AccountOperation\AccountMergeHandoverCopy;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Source-to-survivor transport of the saved private copy and its original admission. */
final class AccountMergeHandoverSignalData extends BaseDTO implements SignalDataInterface
{
    public const string operationId = 'operationId';
    public const string stage = 'stage';
    public const string assignmentId = 'assignmentId';
    public const string ownerIndex = 'ownerIndex';
    public const string handover = 'handover';
    public const string copy = 'copy';
    public const string admission = 'admission';

    /**
     * @param int $operationId Original merge operation
     * @param string $stage Immutable go stage
     * @param int $assignmentId Survivor receive assignment
     * @param string $ownerIndex Canonical decimal survivor id
     * @param AccountMergeHandover $handover Source snapshots and original revisions
     * @throws InvalidFormatException When the address and handover disagree
     */
    public function __construct(
        public readonly int $operationId,
        public readonly string $stage,
        public readonly int $assignmentId,
        public readonly string $ownerIndex,
        public readonly AccountMergeHandover $handover,
    ) {
        if ($operationId <= 0 || $assignmentId <= 0 || $stage !== AccountMergeStage::Go->value
            || $operationId !== $handover->copy->operationId
            || $ownerIndex !== (string)$handover->copy->survivorUserId
            || $assignmentId === $handover->copy->assignmentId) {
            throw new InvalidFormatException('The merge handover requires distinct give and receive addresses for its survivor and go operation.');
        }
    }

    /** @return array<string, mixed> Exact private transport payload */
    public function toArray(): array
    {
        return [
            self::operationId => $this->operationId,
            self::stage => $this->stage,
            self::assignmentId => $this->assignmentId,
            self::ownerIndex => $this->ownerIndex,
            self::handover => [self::copy => $this->handover->copy->toArray(), self::admission => $this->handover->admission->toArray()],
        ];
    }

    /**
     * @param array<string, mixed> $data Exact source frame fields
     * @return static Typed handover addressed to the receive assignment
     * @throws InvalidFormatException When keys, types or coordinates are invalid
     */
    public static function fromArray(array $data): static
    {
        self::requireKeys($data, [self::operationId, self::stage, self::assignmentId, self::ownerIndex, self::handover]);
        $handover = self::requireArray($data, self::handover);
        self::requireKeys($handover, [self::copy, self::admission]);

        return new static(
            self::requireInt($data, self::operationId),
            self::requireString($data, self::stage),
            self::requireInt($data, self::assignmentId),
            self::requireString($data, self::ownerIndex),
            new AccountMergeHandover(
                AccountMergeHandoverCopy::fromArray(self::requireArray($handover, self::copy)),
                AccountMergeAdmissionManifest::fromArray(self::requireArray($handover, self::admission)),
            ),
        );
    }

    /**
     * @param array $data Object fields at the transport boundary
     * @param list<string> $keys Exact allowed keys
     * @throws InvalidFormatException When a required key is absent or an extra key is present
     */
    private static function requireKeys(array $data, array $keys): void
    {
        if (count($data) !== count($keys) || array_diff($keys, array_keys($data)) !== []) {
            throw new InvalidFormatException('The merge handover requires exactly its declared fields.');
        }
    }
}
