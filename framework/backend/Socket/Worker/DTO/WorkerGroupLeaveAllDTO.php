<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Group\DTO\GroupLeaveAllSignalData;
use Hilos\Core\Group\GroupMembership;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerGroupLeaveAllDTO - these connections changed person: drop every group they hold (HIL-1284).
 *
 * Travels both ways over the worker link, as {@see WorkerPageAccessReassessConnectionsMessageDTO}
 * does: the worker whose connection changed person emits it to its own daemon
 * ({@see GroupMembership::leaveAll()}), and the daemon, having cleared its own registry on
 * receipt, fans the same frame out to the other workers of the node, each of which clears its
 * mirror. The frame is a thin transport envelope; the field shape lives in the carried
 * {@see GroupLeaveAllSignalData}.
 */
class WorkerGroupLeaveAllDTO extends WorkerDTO
{
    /** @var string Envelope key carrying the leave-all payload */
    public const string FIELD_PAYLOAD = 'payload';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_GROUP_LEAVE_ALL;

    /**
     * @param GroupLeaveAllSignalData $data Connections leaving every group
     */
    public function __construct(
        public readonly GroupLeaveAllSignalData $data,
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
     * @throws InvalidArgumentException When the leave-all payload is not an object
     * @throws InvalidFormatException When the leave-all payload names no connection or a malformed one
     */
    public static function fromArray(array $data): static
    {
        $payload = $data[self::FIELD_PAYLOAD] ?? [];
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Worker group leave-all frame carries a non-object payload');
        }

        return new static(GroupLeaveAllSignalData::fromArray($payload));
    }
}
