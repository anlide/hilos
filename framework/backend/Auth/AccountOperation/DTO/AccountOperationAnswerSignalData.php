<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\DTO;

use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** A participant's answer, correlated to exactly one operation, stage and assignment. */
final class AccountOperationAnswerSignalData extends BaseDTO implements SignalDataInterface
{
    public const string operationId = 'operationId';
    public const string stage = 'stage';
    public const string assignmentId = 'assignmentId';
    public const string answer = 'answer';
    public const string refusedUserId = 'refusedUserId';
    public const string refusedTable = 'refusedTable';

    /**
     * @param int $operationId Operation being answered
     * @param string $stage Stage being answered
     * @param int $assignmentId Assignment being answered
     * @param AccountOperationAnswerKind $answer Participant's answer
     * @param ?int $refusedUserId Account whose final was refused; required only for final_refused
     * @param ?string $refusedTable Referencing table when known; allowed only for final_refused
     * @throws InvalidFormatException When refusal details do not belong to the answer
     */
    public function __construct(
        public readonly int $operationId,
        public readonly string $stage,
        public readonly int $assignmentId,
        public readonly AccountOperationAnswerKind $answer,
        public readonly ?int $refusedUserId = null,
        public readonly ?string $refusedTable = null,
    ) {
        if ($this->answer === AccountOperationAnswerKind::FinalRefused) {
            if ($this->refusedUserId === null) {
                throw new InvalidFormatException('A refused account final must name its account');
            }
        } elseif ($this->refusedUserId !== null || $this->refusedTable !== null) {
            throw new InvalidFormatException('Refusal details belong only to a refused account final');
        }
    }

    /** @return array<string, mixed> Correlated participant answer for transport */
    public function toArray(): array
    {
        return [
            self::operationId => $this->operationId,
            self::stage => $this->stage,
            self::assignmentId => $this->assignmentId,
            self::answer => $this->answer->value,
            self::refusedUserId => $this->refusedUserId,
            self::refusedTable => $this->refusedTable,
        ];
    }

    /**
     * @param array<string, mixed> $data Participant answer from transport
     * @return static Parsed participant answer
     * @throws InvalidFormatException When the answer, field types or refusal details are invalid
     */
    public static function fromArray(array $data): static
    {
        $answer = AccountOperationAnswerKind::tryFrom(self::requireString($data, self::answer));
        if ($answer === null) {
            throw new InvalidFormatException('Unknown account operation answer');
        }

        return new static(
            operationId: self::requireInt($data, self::operationId),
            stage: self::requireString($data, self::stage),
            assignmentId: self::requireInt($data, self::assignmentId),
            answer: $answer,
            refusedUserId: self::optionalInt($data, self::refusedUserId),
            refusedTable: self::optionalString($data, self::refusedTable),
        );
    }
}
