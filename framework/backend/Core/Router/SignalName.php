<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

/**
 * SignalName - Standard implementation of signal name identifier.
 *
 * Represents a signal name (e.g., 'message', 'main', 'frame').
 */
class SignalName implements SignalNameInterface
{
    public const string EMPTY = '';

    /**
     * SignalName constructor
     *
     * @param string $name Signal name
     */
    public function __construct(
        private readonly string $name,
    ) {
    }

    /**
     * Get signal name
     *
     * @return string Signal name
     */
    public function getName(): string
    {
        return $this->name;
    }
}
