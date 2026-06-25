<?php

declare(strict_types=1);

namespace Hilos\Socket\Command\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\CommandConstants;

/**
 * CommandRequestDTO - CLI -> daemon command request over the command socket channel.
 *
 * Carries the correlation id (so a reply can be matched back to its request), the
 * command name, and an open payload map the concrete command interprets.
 */
class CommandRequestDTO extends BaseDTO
{
    /**
     * Creates a command request.
     *
     * @param string $correlationId Correlation id echoed back on the reply
     * @param string $command Command name (e.g. CommandConstants::COMMAND_PING)
     * @param array<string, mixed> $payload Command arguments
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly string $command,
        public readonly array $payload = [],
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
     * @param array<string, mixed> $data Wire payload
     * @return static Restored request
     */
    public static function fromArray(array $data): static
    {
        return new static(
            correlationId: (string) ($data[CommandConstants::FIELD_CORRELATION_ID] ?? ''),
            command: (string) ($data[CommandConstants::FIELD_COMMAND] ?? ''),
            payload: is_array($data[CommandConstants::FIELD_PAYLOAD] ?? null)
                ? $data[CommandConstants::FIELD_PAYLOAD]
                : [],
        );
    }
}
