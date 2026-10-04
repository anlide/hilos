<?php

declare(strict_types=1);

namespace Hilos\Socket\Command\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * CommandRequestDTO - CLI -> daemon command request over the command socket channel.
 *
 * Carries the correlation id (so a reply can be matched back to its request), the
 * command name, and an open payload map the concrete command interprets. Also used
 * as the COMMAND_REQUEST signal payload when the daemon routes the command to an
 * agent, so it implements SignalDataInterface.
 */
class CommandRequestDTO extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates a command request.
     *
     * @param string $correlationId Correlation id echoed back on the reply
     * @param string $command Command name as it goes on the wire (e.g. CliCommands::CLUSTER_NODES)
     * @param array<string, mixed> $payload Command arguments
     * @param ?string $originNodeId Node holding the console connection, null outside a cluster
     * @param ?SignalDataInterface $parsedPayload Topology-hydrated inner payload DTO, set by
     *     SignalRouter::createCommandPayloadDTO when the command declares a DTO; transient
     *     (not serialized in toArray()/fromArray()), so the receiving agent reads it in-process
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly string $command,
        public readonly array $payload = [],
        public readonly ?string $originNodeId = null,
        public readonly ?SignalDataInterface $parsedPayload = null,
    ) {
    }

    /**
     * Serializes the request to its wire payload.
     *
     * @return array<string, mixed> Wire payload
     */
    public function toArray(): array
    {
        return [
            CommandConstants::FIELD_CORRELATION_ID => $this->correlationId,
            CommandConstants::FIELD_COMMAND => $this->command,
            CommandConstants::FIELD_PAYLOAD => $this->payload,
            CommandConstants::FIELD_ORIGIN_NODE_ID => $this->originNodeId,
        ];
    }

    /**
     * Restores a request from its wire payload.
     *
     * All three fields are required: every sender builds the line through this
     * class's own toArray(), which always writes all three, and a command with
     * no arguments writes an empty map rather than leaving the key out.
     *
     * The hydrated request is transient by design - parsedPayload is filled in
     * later, in process, by SignalRouter::createCommandPayloadDTO().
     *
     * @param array<string, mixed> $data Wire payload
     * @return static Restored request
     * @throws InvalidFormatException When the payload carries no correlation id, command name or argument map
     */
    public static function fromArray(array $data): static
    {
        return new static(
            correlationId: self::requireString($data, CommandConstants::FIELD_CORRELATION_ID),
            command: self::requireString($data, CommandConstants::FIELD_COMMAND),
            payload: self::requireArray($data, CommandConstants::FIELD_PAYLOAD),
            originNodeId: self::optionalString($data, CommandConstants::FIELD_ORIGIN_NODE_ID),
        );
    }

    /**
     * Stamps the daemon's own node id on a request received from an untrusted CLI client.
     *
     * @param ?string $nodeId Node holding the console connection
     * @return self Copy with the same command, arguments, and hydrated payload
     */
    public function withOriginNodeId(?string $nodeId): self
    {
        return new self($this->correlationId, $this->command, $this->payload, $nodeId, $this->parsedPayload);
    }
}
