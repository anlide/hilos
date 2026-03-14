<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

/**
 * SignalNameInterface - Interface for signal name identifiers.
 *
 * Represents a signal name (e.g., 'message', 'main', 'frame').
 */
interface SignalNameInterface
{
    /**
     * Gets the signal name.
     *
     * @return string Signal name
     */
    public function getName(): string;
}
