<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Core\Group\DTO\GroupJoinSignalData;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerGroupJoinDTO - worker -> daemon: record this connection in this group.
 *
 * The master no longer writes a group membership off the client frame it forwards
 * ({@see AbstractGroup}): it knows neither who is behind the socket nor the full name that
 * identity builds, and reading a browser session on the accept loop is forbidden. So the
 * worker that judged the join tells it what to record, and this frame is that sentence. The
 * frame is a thin transport envelope; the field shape lives in the carried
 * {@see GroupJoinSignalData}.
 */
class WorkerGroupJoinDTO extends WorkerDTO
{
    /** @var string Envelope key carrying the join payload */
    public const string FIELD_PAYLOAD = 'payload';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_GROUP_JOIN;

    /**
     * @param GroupJoinSignalData $data Full group name, the connection, and the join params
     */
    public function __construct(
        public readonly GroupJoinSignalData $data,
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
            self::FIELD_PAYLOAD => $this->data->toArray(),
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (payload)
     * @return static DTO instance
     * @throws InvalidArgumentException When the join payload is not an object
     * @throws InvalidFormatException When the join payload is missing the group name or the accept key
     */
    public static function fromArray(array $data): static
    {
        $payload = $data[self::FIELD_PAYLOAD] ?? [];
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Worker group-join frame carries a non-object payload');
        }

        return new static(GroupJoinSignalData::fromArray($payload));
    }
}
