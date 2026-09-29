<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketEnvelopeAware;

/**
 * Server → initiator: an untracked rename from the person's card failed (HIL-1195).
 *
 * What {@see HilosSignalConstants::HILOS_USER_UPDATE_FAIL} carries, addressed only to the
 * initiating accept key. Carries an envelope-level `outcome='fail'` marker the frontend router
 * uses to dispatch to failure handlers, plus a `reason` string suitable for direct display -
 * the payload the demos' own acks carried, byte for byte, before the card moved here.
 */
final class HilosUserUpdateFailSignalData extends SignalData implements SignalDataInterface, WebSocketEnvelopeAware
{
    /**
     * @param string $reason Human-readable error text
     */
    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct([
            'reason' => $this->reason,
        ]);
    }

    /** @return ?string The failure marker the frontend router dispatches on */
    public function getEnvelopeOutcome(): ?string
    {
        return 'fail';
    }

    /** @return ?string No request id: an untracked submit has none to answer on */
    public function getEnvelopeRequestId(): ?string
    {
        return null;
    }

    /** @return ?int No envelope time */
    public function getEnvelopeTime(): ?int
    {
        return null;
    }

    /**
     * Roundtrip reconstruction used when the signal crosses the worker → daemon IPC boundary.
     *
     * Without it the concrete class is lost on the daemon side and the outgoing
     * frame ends up without the `outcome='fail'` marker, so the frontend router
     * drops the signal as unhandled.
     *
     * @param array<string, mixed> $data Serialized payload carrying the reason
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no reason text
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, 'reason'));
    }
}
