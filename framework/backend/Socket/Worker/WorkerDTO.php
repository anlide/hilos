<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Core\Exception\MalformedInput;
use Hilos\Core\Exception\NonArrayPayloadException;
use Hilos\HilosException;
use Hilos\Constants\WorkerConstants;
use Hilos\Socket\Worker\Exception\UnknownWorkerMessageTypeException;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\DaemonWorkerSignalDTO;
use Hilos\Socket\Worker\DTO\ProtectedModeReadyDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStoppedDTO;
use Hilos\Socket\Worker\DTO\DbReHydrateCompleteDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydratedDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydrateMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReReadMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncClearedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerGroupJoinDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessConnectionsMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeDisableDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeEnableDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModePassDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeProgressDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeRefreezeDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeVerifyDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSnapshotMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtStalenessMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceReleasedDTO;
use Hilos\Socket\Worker\DTO\WorkerSourceInterestDTO;
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
     * Every way a frame can fail here is a way the input could not be parsed, so each
     * refusal is one that carries {@see MalformedInput}: the reader of the log asks the
     * failure what it is and gets the same answer whether the string never decoded, held
     * the wrong shape, named no type or named one this node does not know. The generic
     * invalid-argument exception used to answer for all four and could not be marked —
     * the same class reports a caller passing nonsense in five other places, where the
     * fault is the code's and not the wire's.
     *
     * @param string $json JSON string
     * @return WorkerDTO Worker DTO instance
     * @throws InvalidJsonException When the frame does not decode as JSON
     * @throws NonArrayPayloadException When the frame decodes into something other than an array
     * @throws InvalidFormatException When the frame names no message type
     * @throws UnknownWorkerMessageTypeException When the frame names a type the registry does not know
     * @throws HilosException When an agent frame's inner signal payload refuses to be restored
     */
    public static function factoryWorkerDTO(string $json): WorkerDTO
    {
        Logger::debug('Parsing worker DTO from JSON: ' . $json);
        $data = self::decodePayload($json);

        $type = $data[self::TYPE] ?? null;
        if (!is_string($type) || $type === '') {
            throw new InvalidFormatException('Message type is missing');
        }

        return match ($type) {
            WorkerRegisterDTO::MESSAGE_TYPE => WorkerRegisterDTO::fromArray($data),
            WorkerAgentStartedDTO::MESSAGE_TYPE => WorkerAgentStartedDTO::fromArray($data),
            WorkerAgentStoppedDTO::MESSAGE_TYPE => WorkerAgentStoppedDTO::fromArray($data),
            WorkerAgentMessageDTO::MESSAGE_TYPE => WorkerAgentMessageDTO::fromArray($data),
            WorkerConstants::MESSAGE_DAEMON_AGENT_MESSAGE => DaemonAgentMessageDTO::fromArray($data),
            DaemonWorkerSignalDTO::MESSAGE_TYPE => DaemonWorkerSignalDTO::fromArray($data),
            WorkerRegisteredDTO::MESSAGE_TYPE => WorkerRegisteredDTO::fromArray($data),
            AgentStartDTO::MESSAGE_TYPE => AgentStartDTO::fromArray($data),
            AgentStopDTO::MESSAGE_TYPE => AgentStopDTO::fromArray($data),
            ProtectedModeReadyDTO::MESSAGE_TYPE => ProtectedModeReadyDTO::fromArray($data),
            WorkerDbSyncCreatedMessageDTO::MESSAGE_TYPE => WorkerDbSyncCreatedMessageDTO::fromArray($data),
            WorkerDbSyncUpdatedMessageDTO::MESSAGE_TYPE => WorkerDbSyncUpdatedMessageDTO::fromArray($data),
            WorkerDbSyncDeletedMessageDTO::MESSAGE_TYPE => WorkerDbSyncDeletedMessageDTO::fromArray($data),
            WorkerDbSyncClearedMessageDTO::MESSAGE_TYPE => WorkerDbSyncClearedMessageDTO::fromArray($data),
            WorkerDbReHydrateMessageDTO::MESSAGE_TYPE => WorkerDbReHydrateMessageDTO::fromArray($data),
            WorkerDbReReadMessageDTO::MESSAGE_TYPE => WorkerDbReReadMessageDTO::fromArray($data),
            WorkerPageAccessReassessMessageDTO::MESSAGE_TYPE => WorkerPageAccessReassessMessageDTO::fromArray($data),
            WorkerPageAccessReassessConnectionsMessageDTO::MESSAGE_TYPE
                => WorkerPageAccessReassessConnectionsMessageDTO::fromArray($data),
            WorkerDbReHydratedDTO::MESSAGE_TYPE => WorkerDbReHydratedDTO::fromArray($data),
            DbReHydrateCompleteDTO::MESSAGE_TYPE => DbReHydrateCompleteDTO::fromArray($data),
            WorkerRtSyncCreatedMessageDTO::MESSAGE_TYPE => WorkerRtSyncCreatedMessageDTO::fromArray($data),
            WorkerRtSyncUpdatedMessageDTO::MESSAGE_TYPE => WorkerRtSyncUpdatedMessageDTO::fromArray($data),
            WorkerRtSyncDeletedMessageDTO::MESSAGE_TYPE => WorkerRtSyncDeletedMessageDTO::fromArray($data),
            WorkerRtSourceRegisteredDTO::MESSAGE_TYPE => WorkerRtSourceRegisteredDTO::fromArray($data),
            WorkerRtSourceReleasedDTO::MESSAGE_TYPE => WorkerRtSourceReleasedDTO::fromArray($data),
            WorkerSourceInterestDTO::MESSAGE_TYPE => WorkerSourceInterestDTO::fromArray($data),
            WorkerGroupJoinDTO::MESSAGE_TYPE => WorkerGroupJoinDTO::fromArray($data),
            WorkerRtSnapshotMessageDTO::MESSAGE_TYPE => WorkerRtSnapshotMessageDTO::fromArray($data),
            WorkerRtStalenessMessageDTO::MESSAGE_TYPE => WorkerRtStalenessMessageDTO::fromArray($data),
            WorkerProtectedModeEnableDTO::MESSAGE_TYPE => WorkerProtectedModeEnableDTO::fromArray($data),
            WorkerProtectedModeDisableDTO::MESSAGE_TYPE => WorkerProtectedModeDisableDTO::fromArray($data),
            WorkerProtectedModeVerifyDTO::MESSAGE_TYPE => WorkerProtectedModeVerifyDTO::fromArray($data),
            WorkerProtectedModeProgressDTO::MESSAGE_TYPE => WorkerProtectedModeProgressDTO::fromArray($data),
            WorkerProtectedModePassDTO::MESSAGE_TYPE => WorkerProtectedModePassDTO::fromArray($data),
            WorkerProtectedModeRefreezeDTO::MESSAGE_TYPE => WorkerProtectedModeRefreezeDTO::fromArray($data),
            default => throw new UnknownWorkerMessageTypeException($type),
        };
    }
}
