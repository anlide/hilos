<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

/**
 * SignalType - Standard implementation of signal type identifier
 *
 * Represents a signal type (e.g., 'frame', 'handshake', 'close', 'subscribe', 'action').
 */
class SignalType implements SignalTypeInterface
{
    /**
     * Creates signal type instance.
     *
     * @param string $type Signal type (e.g. frame, handshake, action)
     */
    public function __construct(
        private readonly string $type,
    ) {
    }

    /**
     * Get signal type
     *
     * @return string Signal type
     */
    public function getType(): string
    {
        return $this->type;
    }
}
