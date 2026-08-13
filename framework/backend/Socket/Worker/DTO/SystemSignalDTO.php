<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * SystemSignalDTO - DTO for system signal.
 *
 * Represents a system signal sent from daemon to agent.
 */
class SystemSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface
{
    // Field name constants
    public const string SYSTEM_NAME = 'systemName';

    /**
     * Creates system signal DTO.
     *
     * @param string $systemName System signal name
     */
    public function __construct(
        public readonly string $systemName,
    ) {
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, string> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::SYSTEM_NAME => $this->systemName,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (systemName key)
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no system signal name
     */
    public static function fromArray(array $data): static
    {
        return new static(
            systemName: self::requireString($data, self::SYSTEM_NAME),
        );
    }
}
