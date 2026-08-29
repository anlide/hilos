<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerLogWriteLevelDTO - the level a worker writes its log from, told to the master (HIL-761).
 *
 * A message of its own rather than a field on the registration: registration says what a worker
 * is, and it happens once, while this says what the whole installation is set to and can change
 * at any point in a node's life. Sent by every worker, whose values agree because they read one
 * setting; the master writes a line only when what it is told differs from what it holds.
 */
class WorkerLogWriteLevelDTO extends WorkerDTO
{
    // Field name constants
    public const string TYPE = 'type';
    public const string LEVEL = 'level';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_WORKER_LOG_WRITE_LEVEL;

    /**
     * Creates the write-level report.
     *
     * @param string $level Level name the worker writes from, e.g. `WARNING`
     */
    public function __construct(
        public readonly string $level,
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
            self::LEVEL => $this->level,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * The level is required: a report of the write level with no level in it is not a report of
     * anything, and there is no value a missing one could stand for - INFO is a real answer.
     *
     * @param array<string, mixed> $data Source data (level)
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no level
     */
    public static function fromArray(array $data): static
    {
        return new static(
            level: self::requireString($data, self::LEVEL),
        );
    }
}
