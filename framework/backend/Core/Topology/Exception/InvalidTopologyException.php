<?php

declare(strict_types=1);

namespace Hilos\Core\Topology\Exception;

use Hilos\Hilos;

/**
 * Exception thrown when a Hilos facade declares inconsistent topology.
 */
final class InvalidTopologyException extends TopologyException
{
    /**
     * Creates a readable validation failure from collected topology errors.
     *
     * @param class-string<Hilos> $hilosClass Facade class being validated
     * @param list<string> $errors Validation error messages
     * @return self Exception instance
     */
    public static function forErrors(string $hilosClass, array $errors): self
    {
        return new self("Invalid Hilos topology in {$hilosClass}:\n- " . implode("\n- ", $errors));
    }
}
