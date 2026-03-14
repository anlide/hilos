<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Socket\Worker\WorkerDTO;

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

    /**
     * Creates worker registration confirmation DTO.
     *
     * @param int $workerIndex Worker index assigned by daemon
     * @param bool $monopolistic Whether worker is monopolistic
     */
    public function __construct(
        public readonly int $workerIndex,
        public readonly bool $monopolistic = false,
    ) {
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
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
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (workerIndex, monopolistic)
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
