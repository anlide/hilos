<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

/**
 * SignalTypeInterface - Interface for signal type identifiers.
 *
 * Represents a signal type (e.g., 'frame', 'handshake', 'close', 'subscribe', 'action').
 */
interface SignalTypeInterface
{
    /**
     * Get signal type.
     *
     * @return string Signal type
     */
    public function getType(): string;
}
