<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * Payload opening the confirmation step of a protected operation (HIL-495).
 */
final class StepUpStartActionDTO extends ActionPayloadDTO
{
    public const string operation = 'operation';

    /**
     * @param string $operation Protected operation key
     */
    public function __construct(public readonly string $operation)
    {
    }

    /**
     * @return string Step-up start action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_STEP_UP_START;
    }

    /**
     * @param array<string, mixed> $data Action payload
     * @return static Parsed payload
     * @throws InvalidFormatException When the operation field is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(trim(self::requireString($data, self::operation)));
    }

    /**
     * @return array{operation: string} Action payload
     */
    public function toArray(): array
    {
        return [self::operation => $this->operation];
    }
}
