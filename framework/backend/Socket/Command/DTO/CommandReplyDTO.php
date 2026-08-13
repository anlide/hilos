<?php

declare(strict_types=1);

namespace Hilos\Socket\Command\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * CommandReplyDTO - daemon -> CLI command reply over the command socket channel.
 *
 * Carries the originating correlation id, an ok/error status, and a payload map
 * (the command result on success, or a message on error). Use {@see ok()} and
 * {@see error()} to build the two shapes. Also used as the COMMAND_REPLY signal
 * payload when an agent answers a routed command, so it implements
 * SignalDataInterface.
 */
class CommandReplyDTO extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates a command reply.
     *
     * @param string $correlationId Correlation id copied from the request
     * @param string $status CommandConstants::STATUS_OK or STATUS_ERROR
     * @param array<string, mixed> $payload Result payload (ok) or error details
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly string $status,
        public readonly array $payload = [],
    ) {
    }

    /**
     * Builds a successful reply carrying a result payload.
     *
     * @param string $correlationId Correlation id copied from the request
     * @param array<string, mixed> $payload Result payload
     * @return self Successful reply
     */
    public static function ok(string $correlationId, array $payload = []): self
    {
        return new self($correlationId, CommandConstants::STATUS_OK, $payload);
    }

    /**
     * Builds a failed reply carrying an error message.
     *
     * @param string $correlationId Correlation id copied from the request
     * @param string $message Human-readable error message
     * @return self Failed reply
     */
    public static function error(string $correlationId, string $message): self
    {
        return new self($correlationId, CommandConstants::STATUS_ERROR, [
            CommandConstants::FIELD_MESSAGE => $message,
        ]);
    }

    /**
     * Reports whether the reply represents success.
     *
     * @return bool True when the status is ok
     */
    public function isOk(): bool
    {
        return $this->status === CommandConstants::STATUS_OK;
    }

    /**
     * Serializes the reply to its wire payload.
     *
     * @return array<string, mixed> Wire payload
     */
    public function toArray(): array
    {
        return [
            CommandConstants::FIELD_CORRELATION_ID => $this->correlationId,
            CommandConstants::FIELD_STATUS => $this->status,
            CommandConstants::FIELD_PAYLOAD => $this->payload,
        ];
    }

    /**
     * Restores a reply from its wire payload.
     *
     * All three fields are required, the status most of all: read as
     * {@see CommandConstants::STATUS_ERROR} when it is missing, it reports a
     * failure the command never had, and {@see isOk()} answers for a run whose
     * outcome never arrived. The payload is required for the same reason its
     * own toArray() always writes it - an empty result is written as an empty
     * map, so an absent key is a truncated reply and not an empty one.
     *
     * @param array<string, mixed> $data Wire payload
     * @return static Restored reply
     * @throws InvalidFormatException When the payload carries no correlation id, status or result map
     */
    public static function fromArray(array $data): static
    {
        return new static(
            correlationId: self::requireString($data, CommandConstants::FIELD_CORRELATION_ID),
            status: self::requireString($data, CommandConstants::FIELD_STATUS),
            payload: self::requireArray($data, CommandConstants::FIELD_PAYLOAD),
        );
    }
}
