<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Constants\WorkerConstants;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStoppedDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncUpdatedMessageDTO;
use Hilos\Utils\Logger;

/**
 * WorkerDTO - Abstract base class for worker DTOs.
 *
 * Provides common functionality for worker communication DTOs.
 */
abstract class WorkerDTO extends BaseDTO
{
    // Field name constants
    public const string TYPE = 'type';

    /**
     * Get message type.
     *
     * @return string Message type
     */
    abstract public function getType(): string;

    /**
     * Factory method to create WorkerDTO from JSON string.
     *
     * Parses JSON, determines message type, and creates appropriate DTO instance.
     *
     * @param string $json JSON string
     * @return WorkerDTO Worker DTO instance
     * @throws InvalidArgumentException If JSON is invalid or message type is unknown
     */
    public static function factoryWorkerDTO(string $json): WorkerDTO
    {
        Logger::debug('Parsing worker DTO from JSON: ' . $json);
        $data = json_decode($json, true)
            ?? throw new InvalidArgumentException('Invalid JSON provided: ' . json_last_error_msg());

        $type = $data[self::TYPE] ?? '';
        if ($type === '') {
            throw new InvalidArgumentException('Message type is missing');
        }

        return match ($type) {
            WorkerRegisterDTO::MESSAGE_TYPE => WorkerRegisterDTO::fromArray($data),
            WorkerAgentStartedDTO::MESSAGE_TYPE => WorkerAgentStartedDTO::fromArray($data),
            WorkerAgentStoppedDTO::MESSAGE_TYPE => WorkerAgentStoppedDTO::fromArray($data),
            WorkerAgentMessageDTO::MESSAGE_TYPE => WorkerAgentMessageDTO::fromArray($data),
            WorkerConstants::MESSAGE_DAEMON_AGENT_MESSAGE => DaemonAgentMessageDTO::fromArray($data),
            WorkerRegisteredDTO::MESSAGE_TYPE => WorkerRegisteredDTO::fromArray($data),
            AgentStartDTO::MESSAGE_TYPE => AgentStartDTO::fromArray($data),
            AgentStopDTO::MESSAGE_TYPE => AgentStopDTO::fromArray($data),
            WorkerDbSyncCreatedMessageDTO::MESSAGE_TYPE => WorkerDbSyncCreatedMessageDTO::fromArray($data),
            WorkerDbSyncUpdatedMessageDTO::MESSAGE_TYPE => WorkerDbSyncUpdatedMessageDTO::fromArray($data),
            WorkerDbSyncDeletedMessageDTO::MESSAGE_TYPE => WorkerDbSyncDeletedMessageDTO::fromArray($data),
            WorkerRtSyncCreatedMessageDTO::MESSAGE_TYPE => WorkerRtSyncCreatedMessageDTO::fromArray($data),
            WorkerRtSyncUpdatedMessageDTO::MESSAGE_TYPE => WorkerRtSyncUpdatedMessageDTO::fromArray($data),
            WorkerRtSyncDeletedMessageDTO::MESSAGE_TYPE => WorkerRtSyncDeletedMessageDTO::fromArray($data),
            default => throw new InvalidArgumentException("Unknown worker message type: {$type}"),
        };
    }
}
