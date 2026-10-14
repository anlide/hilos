<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Index agent → Maintenance page: an addressed outcome and, on success only, its clear code. */
final class ManualMaintenancePassDoneSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param HandoverAnswerSignalData $answer Correlated handover outcome
     * @param ?string $pass Clear code on success, absent on refusal
     * @throws InvalidFormatException When success lacks a code or correlation, or refusal carries a code
     */
    public function __construct(
        public readonly HandoverAnswerSignalData $answer,
        public readonly ?string $pass,
    ) {
        if ($answer->error === null) {
            if ($pass === null || $pass === '' || $answer->requestId === null || $answer->requestId === '') {
                throw new InvalidFormatException('A successful code outcome requires a pass and tracked correlation');
            }
        } elseif ($pass !== null) {
            throw new InvalidFormatException('A refused code outcome cannot carry a pass');
        }
    }

    /** @return array<string, mixed> Addressed internal outcome */
    public function toArray(): array
    {
        return ['answer' => $this->answer->toArray(), 'pass' => $this->pass];
    }

    /**
     * @param array<string, mixed> $data Internal outcome payload
     * @return static Validated outcome
     * @throws InvalidFormatException When fields are missing, mistyped or inconsistent
     */
    public static function fromArray(array $data): static
    {
        return new static(
            HandoverAnswerSignalData::fromArray(self::requireArray($data, 'answer')),
            self::optionalString($data, 'pass'),
        );
    }
}
