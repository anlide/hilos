<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * CronSignalDTO - DTO for cron signal
 *
 * Represents a cron signal sent from daemon to agent.
 */
class CronSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface
{
    // Field name constants
    public const string CRON_NAME = 'cronName';

    public function __construct(
        public readonly string $cronName,
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
            self::CRON_NAME => $this->cronName,
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
            cronName: $data[self::CRON_NAME] ?? '',
        );
    }
}
