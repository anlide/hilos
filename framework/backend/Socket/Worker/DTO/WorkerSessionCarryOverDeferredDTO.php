<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Auth\Session\DTO\SessionCarryOverDeferredSignalData;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\ProtectedMode\ProtectedModeLiftAnnouncer;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerSessionCarryOverDeferredDTO - worker -> daemon report that a restore left logins behind.
 *
 * Sent once per restore, by the node that ran it, and handed to
 * {@see ProtectedModeLiftAnnouncer::noteSessionsDeferred()} so the lift of this node's freeze waits
 * for the logins to be back before the browsers are told to reload. A thin transport envelope like
 * its protected-mode neighbours: the field shape lives in the wrapped payload.
 */
class WorkerSessionCarryOverDeferredDTO extends WorkerDTO
{
    /** @var string Envelope key carrying the deferred-carry-over payload */
    public const string FIELD_PAYLOAD = 'payload';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_SESSION_CARRY_OVER_DEFERRED;

    /**
     * @param SessionCarryOverDeferredSignalData $data How many logins the restore left queued
     */
    public function __construct(
        public readonly SessionCarryOverDeferredSignalData $data,
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
     * @throws InvalidFormatException When the deferred-carry-over payload is not an object
     */
    public static function fromArray(array $data): static
    {
        $payload = $data[self::FIELD_PAYLOAD] ?? [];
        if (!is_array($payload)) {
            throw new InvalidFormatException('Worker deferred session carry-over frame carries a non-object payload');
        }

        return new static(SessionCarryOverDeferredSignalData::fromArray($payload));
    }
}
