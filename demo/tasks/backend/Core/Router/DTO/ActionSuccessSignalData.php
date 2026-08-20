<?php

declare(strict_types=1);

namespace Demo\Tasks\Core\Router\DTO;

use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketEnvelopeAware;

/**
 * ActionSuccessSignalData - Signal data for one-to-one action-acknowledgement
 * signals emitted to the initiator of a client-originated action on success.
 *
 * Convention: emitted under signal name `<action>_success` addressed only to the
 * initiating accept-key. Carries an envelope-level `outcome='success'` marker the
 * frontend router uses to dispatch to success handlers. The body is usually empty
 * since the real state transition is already broadcast through browser page data.
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
     * @return array<string, mixed> Payload with the optional message merged in
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
     * @param array<string, mixed> $data Serialized payload (caller body plus an optional message key)
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
