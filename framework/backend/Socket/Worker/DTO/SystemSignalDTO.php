<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * SystemSignalDTO - DTO for system signal
 *
 * Represents a system signal sent from daemon to agent.
 */
class SystemSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface
{
    // Field name constants
    public const string SYSTEM_NAME = 'systemName';

    public function __construct(
        public readonly string $systemName,
    ) {
    }

    /**
     * Convert DTO to array
     *
     * @return array DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::SYSTEM_NAME => $this->systemName,
        ];
    }

    /**
     * Create DTO from array
     *
     * @param array $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            systemName: $data[self::SYSTEM_NAME] ?? '',
        );
    }
}
