<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\Core\Exception\InvalidArgumentException;

/**
 * SignalName - Standard implementation of signal name identifier.
 *
 * Represents a signal name (e.g., 'message', 'main', 'frame'). A signal always
 * has a name: a transport signal that carries no application name is named after
 * its own type, so "no name" is not a state this type can hold.
 */
class SignalName implements SignalNameInterface
{
    /**
     * @param string $name Signal name
     * @throws InvalidArgumentException When the name is empty
     */
    public function __construct(
        private readonly string $name,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('Signal name must not be empty');
        }
    }

    /**
     * Get signal name.
     *
     * @return string Signal name
     */
    public function getName(): string
    {
        return $this->name;
    }
}
