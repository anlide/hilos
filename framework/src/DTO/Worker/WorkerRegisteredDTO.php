<?php

declare(strict_types=1);

namespace Hilos\DTO\Worker;

use Hilos\Constants\WorkerConstants;

/**
 * WorkerRegisteredDTO - DTO for worker registration confirmation
 *
 * Used when daemon confirms worker registration.
 */
class WorkerRegisteredDTO extends WorkerDTO
{
    // Field name constants
    public const string TYPE = 'type';
    public const string WORKER_INDEX = 'workerIndex';
    public const string MONOPOLISTIC = 'monopolistic';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_WORKER_REGISTERED;

    /**
     * Get message type
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    public function __construct(
        public readonly int $workerIndex,
        public readonly bool $monopolistic = false,
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
            self::TYPE => $this->getType(),
            self::WORKER_INDEX => $this->workerIndex,
            self::MONOPOLISTIC => $this->monopolistic,
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
            workerIndex: $data[self::WORKER_INDEX] ?? 0,
            monopolistic: $data[self::MONOPOLISTIC] ?? false,
        );
    }
}
