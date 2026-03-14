<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * CronSignalDTO - DTO for cron signal.
 *
 * Represents a cron signal sent from daemon to agent.
 */
class CronSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface
{
    /** @var string Field name for cron job in serialized data */
    public const string CRON_NAME = 'cronName';

    /**
     * Creates cron signal DTO.
     *
     * @param string $cronName Cron job name (e.g. ChatCronConstants::CLEANUP_HISTORY)
     */
    public function __construct(
        public readonly string $cronName,
    ) {
    }

    /**
     * Convert DTO to array for transport.
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
     * @param array<string, mixed> $data Source data (cronName key)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            cronName: $data[self::CRON_NAME] ?? '',
        );
    }
}
