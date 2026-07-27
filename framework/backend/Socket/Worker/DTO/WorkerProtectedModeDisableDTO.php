<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerProtectedModeDisableDTO - worker -> daemon request to leave protected mode.
 *
 * The mirror of {@see WorkerProtectedModeEnableDTO}: once the initiator agent has finished its
 * destructive operation it asks its own master daemon to release the freeze. The daemon hands the
 * request to {@see \Hilos\ProtectedMode\ClusterProtectedMode::requestDisable()}, which lifts the
 * freeze locally when this node leads or forwards it to the current leader otherwise. The frame
 * carries nothing beyond its type — the request is the whole message.
 */
class WorkerProtectedModeDisableDTO extends WorkerDTO
{
    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_PROTECTED_MODE_DISABLE;

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
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (unused; the frame carries only its type)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
