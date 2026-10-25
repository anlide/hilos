<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Backup agent → Maintenance page: an addressed outcome and, on a successful pass only, its clear code. */
final class MaintenanceRestoreControlDoneSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param HandoverAnswerSignalData $answer Correlated handover outcome
     * @param ?string $pass Clear code on a successful pass, absent otherwise
     * @throws InvalidFormatException When a successful pass lacks a code or correlation, or any other outcome carries a code
     */
    public function __construct(
        public readonly HandoverAnswerSignalData $answer,
        public readonly ?string $pass,
    ) {
        if ($answer->action === HilosSignalConstants::MAINTENANCE_RESTORE_PASS && $answer->error === null) {
            if ($pass === null || $pass === '' || $answer->requestId === null || $answer->requestId === '') {
                throw new InvalidFormatException('A successful restore pass requires a code and a tracked request');
            }
        } elseif ($pass !== null) {
            throw new InvalidFormatException('A restore outcome carries a code only on a successful pass');
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
