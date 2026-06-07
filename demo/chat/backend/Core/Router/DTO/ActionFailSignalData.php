<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketEnvelopeAware;

/**
 * ActionFailSignalData - Signal data for one-to-one action-acknowledgement
 * signals emitted to the initiator of a client-originated action on failure.
 *
 * Convention: emitted under signal name `<action>_fail` (e.g. `rename_fail`)
 * addressed only to the initiating accept-key.
 *
 * Carries an envelope-level `outcome='fail'` marker that the frontend
 * router uses to dispatch to failure handlers (see {@see WebSocketEnvelopeAware}).
 *
 * The `data` payload contains `reason` as human-readable text owned by the
 * backend, suitable for direct display in UI.
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

    public function getEnvelopeTime(): ?int
    {
        return null;
    }

    /**
     * Roundtrip reconstruction used by WebSocketSignalData::deserializeInnerData()
     * when the signal crosses the worker → daemon IPC boundary.
     *
     * Without this implementation the concrete class is lost on the daemon
     * side (IPC falls back to generic SignalData), the DTO no longer
     * implements {@see WebSocketEnvelopeAware}, and the outgoing WebSocket
     * frame ends up without the `outcome='fail'` envelope marker — which
     * in turn makes the frontend router drop the signal as unhandled.
     */
    public static function fromArray(array $data): static
    {
        $reason = isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : '';
        return new static($reason);
    }
}
