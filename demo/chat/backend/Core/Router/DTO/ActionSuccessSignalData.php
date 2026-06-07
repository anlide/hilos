<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketEnvelopeAware;

/**
 * ActionSuccessSignalData - Signal data for one-to-one action-acknowledgement
 * signals emitted to the initiator of a client-originated action on success.
 *
 * Convention: emitted under signal name `<action>_success` (e.g. `rename_success`)
 * addressed only to the initiating accept-key.
 *
 * Carries an envelope-level `outcome='success'` marker that the frontend
 * router uses to dispatch to success handlers (see {@see WebSocketEnvelopeAware}).
 *
 * The `data` payload is optional: most success acks have no body since
 * the real state transition is already broadcast via browser page data,
 * `table_mutation`, or another state-specific signal. An optional `message`
 * field is reserved for future toast UI.
 */
final class ActionSuccessSignalData extends SignalData implements SignalDataInterface, WebSocketEnvelopeAware
{
    /**
     * @param array<string, mixed> $data Optional payload body
     * @param ?string $message Optional human-readable success message (reserved for toast UI)
     */
    public function __construct(
        array $data = [],
        public readonly ?string $message = null,
    ) {
        parent::__construct($this->buildData($data));
    }

    /**
     * Merge optional `message` into the serialized data payload.
     *
     * @param array<string, mixed> $data Caller-provided payload
     * @return array<string, mixed>
     */
    private function buildData(array $data): array
    {
        if ($this->message !== null) {
            return array_merge($data, ['message' => $this->message]);
        }
        return $data;
    }

    public function getEnvelopeOutcome(): ?string
    {
        return 'success';
    }

    public function getEnvelopeTime(): ?int
    {
        return null;
    }

    /**
     * Roundtrip reconstruction used by WebSocketSignalData::deserializeInnerData()
     * when the signal crosses the worker → daemon IPC boundary.
     *
     * The serialized form is whatever {@see self::toArray()} produced: caller
     * payload with an optional `message` key merged in. We split them back
     * apart so the reconstructed instance carries the same `message` value
     * (required for {@see self::getEnvelopeOutcome()} to still be meaningful
     * and for any future toast UI).
     */
    public static function fromArray(array $data): static
    {
        $message = null;
        if (isset($data['message']) && is_string($data['message'])) {
            $message = $data['message'];
            unset($data['message']);
        }
        return new static($data, $message);
    }
}
