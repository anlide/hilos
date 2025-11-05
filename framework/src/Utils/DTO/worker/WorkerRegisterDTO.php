<?php

declare(strict_types=1);

namespace Hilos\Utils\DTO\Worker;

use Hilos\Utils\DTO\BaseDTO;

/**
 * WorkerRegisterDTO - DTO for worker registration message
 *
 * Used when worker sends registration request to daemon.
 */
class WorkerRegisterDTO extends BaseDTO
{
    // Field name constants
    public const string TYPE = 'type';
    public const string WORKER_INDEX = 'workerIndex';
    public const string MONOPOLISTIC = 'monopolistic';

    // Message type
    public const string MESSAGE_TYPE = 'worker_register';

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
            self::TYPE => self::MESSAGE_TYPE,
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
