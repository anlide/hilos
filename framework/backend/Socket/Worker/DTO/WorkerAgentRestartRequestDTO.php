<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\DaemonSection\DTO\DaemonAgentRestartRequestSignalData;
use Hilos\Socket\Worker\WorkerDTO;

/** Registered worker's admitted page request to its own master. */
final class WorkerAgentRestartRequestDTO extends WorkerDTO
{
    public const string FIELD_PAYLOAD = 'payload';
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_AGENT_RESTART_REQUEST;

    /** @param DaemonAgentRestartRequestSignalData $data Restart and server-owned return address */
    public function __construct(public readonly DaemonAgentRestartRequestSignalData $data)
    {
    }

    /** @return string Worker control message type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Typed request envelope */
    public function toArray(): array
    {
        return [self::TYPE => self::MESSAGE_TYPE, self::FIELD_PAYLOAD => $this->data->toArray()];
    }

    /**
     * @param array<string, mixed> $data Worker envelope
     * @return static Restored typed envelope
     * @throws InvalidFormatException When the payload is absent, non-object or missing a request field
     */
    public static function fromArray(array $data): static
    {
        return new static(DaemonAgentRestartRequestSignalData::fromArray(self::requireArray($data, self::FIELD_PAYLOAD)));
    }
}
