<?php

declare(strict_types=1);

namespace Hilos\Socket\Command\DTO;

use Hilos\BaseDTO;
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
     * @param string $command Command name (e.g. CommandConstants::COMMAND_PING)
     * @param array<string, mixed> $payload Command arguments
     * @param ?SignalDataInterface $parsedPayload Topology-hydrated inner payload DTO, set by
     *     SignalRouter::createCommandPayloadDTO when the command declares a DTO; transient
     *     (not serialized in toArray()/fromArray()), so the receiving agent reads it in-process
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly string $command,
        public readonly array $payload = [],
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
        );
    }
}
