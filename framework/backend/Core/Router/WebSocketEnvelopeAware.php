<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\Core\Daemon\DaemonManager;

/**
 * Marker interface for signal data DTOs that need to inject extra fields
 * into the WebSocket envelope alongside the standard `{type, data}`.
 *
 * Currently used by action-acknowledgement DTOs (success/fail) to add an
 * `outcome` marker, and reserved for future clock-sync DTOs to add a `time`
 * tick.
 *
 * Inspected by {@see DaemonManager} when building the
 * outgoing frame.
 */
interface WebSocketEnvelopeAware
{
    /**
     * Envelope-level outcome marker.
     *
     * @return 'success'|'fail'|null Action acknowledgement outcome, or null when absent
     */
    public function getEnvelopeOutcome(): ?string;

    /**
     * Envelope-level server clock tick in milliseconds, if this signal carries one.
     *
     * @return int|null Server clock tick in milliseconds, or null when absent
     */
    public function getEnvelopeTime(): ?int;
}
