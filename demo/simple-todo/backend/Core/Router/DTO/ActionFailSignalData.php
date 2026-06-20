<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Core\Router\DTO;

use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketEnvelopeAware;

/**
 * ActionFailSignalData - Signal data for one-to-one action-acknowledgement
 * signals emitted to the initiator of a client-originated action on failure.
 *
 * Convention: emitted under signal name `<action>_fail` addressed only to the
 * initiating accept-key. Carries an envelope-level `outcome='fail'` marker the
 * frontend router uses to dispatch to failure handlers, plus a `reason` string
 * suitable for direct display.
 */
final class ActionFailSignalData extends SignalData implements SignalDataInterface, WebSocketEnvelopeAware
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

    public function getEnvelopeOutcome(): ?string
    {
        return 'fail';
    }

    public function getEnvelopeRequestId(): ?string
    {
        return null;
    }

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
     */
    public static function fromArray(array $data): static
    {
        $reason = isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : '';

        return new static($reason);
    }
}
