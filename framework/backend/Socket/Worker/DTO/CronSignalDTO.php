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

    public const string CRON_EXPRESSION = 'cronExpression';

    /**
     * Creates cron signal DTO.
     *
     * @param string $cronName Cron job name
     * @param ?string $cronExpression Expression of the rule that fired, or null for a legacy signal
     */
    public function __construct(
        public readonly string $cronName,
        public readonly ?string $cronExpression = null,
    ) {
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, string> DTO data as array
     */
    public function toArray(): array
    {
        $data = [
            self::CRON_NAME => $this->cronName,
        ];
        if ($this->cronExpression !== null) {
            $data[self::CRON_EXPRESSION] = $this->cronExpression;
        }
        return $data;
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the cron name is missing or a field has an invalid type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            cronName: self::requireString($data, self::CRON_NAME),
            cronExpression: self::optionalString($data, self::CRON_EXPRESSION),
        );
    }
}
