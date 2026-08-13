<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerRegisteredDTO - DTO for worker registration confirmation.
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
     * Returns message type.
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
            self::TYPE => $this->getType(),
            self::WORKER_INDEX => $this->workerIndex,
            self::MONOPOLISTIC => $this->monopolistic,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * Both fields are required: worker 0 is a real worker and a lowered
     * monopolistic flag is a real answer, so neither has a value left to stand
     * for its own absence.
     *
     * @param array<string, mixed> $data Source data (workerIndex, monopolistic)
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no worker index or no monopolistic flag
     */
    public static function fromArray(array $data): static
    {
        return new static(
            workerIndex: self::requireInt($data, self::WORKER_INDEX),
            monopolistic: self::requireBool($data, self::MONOPOLISTIC),
        );
    }
}
