<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Auth\Session\DTO\SessionCarryOverDoneSignalData;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\ProtectedMode\ProtectedModeLiftAnnouncer;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerSessionCarryOverDoneDTO - worker -> daemon report that the owed logins are back.
 *
 * The answer to {@see WorkerSessionCarryOverDeferredDTO}, sent by the worker hosting the sessions
 * library once it has emptied the deferred queue, and handed to
 * {@see ProtectedModeLiftAnnouncer::noteSessionsCarriedOver()} - which releases a lift being held
 * for it. A thin transport envelope like its protected-mode neighbours.
 */
class WorkerSessionCarryOverDoneDTO extends WorkerDTO
{
    /** @var string Envelope key carrying the carry-over result payload */
    public const string FIELD_PAYLOAD = 'payload';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_SESSION_CARRY_OVER_DONE;

    /**
     * @param SessionCarryOverDoneSignalData $data Logins carried and logins lost
     */
    public function __construct(
        public readonly SessionCarryOverDoneSignalData $data,
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
     * @throws InvalidFormatException When the carry-over result payload is not an object
     */
    public static function fromArray(array $data): static
    {
        $payload = $data[self::FIELD_PAYLOAD] ?? [];
        if (!is_array($payload)) {
            throw new InvalidFormatException('Worker session carry-over done frame carries a non-object payload');
        }

        return new static(SessionCarryOverDoneSignalData::fromArray($payload));
    }
}
