<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * CronSignalDTO - DTO for cron signal.
 *
 * Represents a cron signal sent from daemon to agent.
 */
class CronSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface
{
    // Field name constants
    public const string CRON_NAME = 'cronName';

    /**
     * Creates cron signal DTO.
     *
     * @param string $cronName Cron job name
     */
    public function __construct(
        public readonly string $cronName,
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
            self::CRON_NAME => $this->cronName,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no cron name
     */
    public static function fromArray(array $data): static
    {
        return new static(
            cronName: self::requireString($data, self::CRON_NAME),
        );
    }
}
