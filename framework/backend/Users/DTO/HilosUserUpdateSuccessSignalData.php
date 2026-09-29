<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketEnvelopeAware;

/**
 * Server → initiator: an untracked rename from the person's card went through (HIL-1195).
 *
 * What {@see HilosSignalConstants::HILOS_USER_UPDATE_SUCCESS} carries, addressed only to the
 * initiating accept key. Carries an envelope-level `outcome='success'` marker the frontend
 * router uses to dispatch to success handlers, and an empty body: the new name itself returns
 * over the live page - the payload the demos' own acks carried before the card moved here.
 */
final class HilosUserUpdateSuccessSignalData extends SignalData implements SignalDataInterface, WebSocketEnvelopeAware
{
    public function __construct()
    {
        parent::__construct([]);
    }

    /** @return ?string The success marker the frontend router dispatches on */
    public function getEnvelopeOutcome(): ?string
    {
        return 'success';
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
     * @param array<string, mixed> $data Serialized payload, empty
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
