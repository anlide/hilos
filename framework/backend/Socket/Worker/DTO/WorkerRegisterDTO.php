<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerRegisterDTO - DTO for worker registration message.
 *
 * Used when worker sends registration request to daemon.
 */
class WorkerRegisterDTO extends WorkerDTO
{
    // Field name constants
    public const string TYPE = 'type';
    public const string WORKER_INDEX = 'workerIndex';
    public const string MONOPOLISTIC = 'monopolistic';

    // Message type
    public const string MESSAGE_TYPE = 'worker_register';

    /**
     * Creates worker registration DTO.
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
     * Get message type.
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
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
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (workerIndex, monopolistic)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            workerIndex: $data[self::WORKER_INDEX] ?? 0,
            monopolistic: $data[self::MONOPOLISTIC] ?? false,
        );
    }
}
