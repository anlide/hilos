<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Exception;

use Hilos\Core\Topology\Exception\TopologyException;
use Hilos\Hilos;

/**
 * Exception thrown when a Hilos facade declares features it did not fully activate.
 *
 * A topology exception because that is what an incomplete activation is: the declared
 * features and the registries that carry them are one topology, and it is inconsistent.
 * Extending the topology base also keeps it caught by every process spin that already
 * handles a bad topology, so a gap fails the start instead of surfacing as a null halfway
 * through a request.
 */
final class IncompleteFeatureActivationException extends TopologyException
{
    /**
     * Creates a readable activation failure from collected errors.
     *
     * @param class-string<Hilos> $hilosClass Facade class being validated
     * @param list<string> $errors Activation error messages
     * @return self Exception instance
     */
    public static function forErrors(string $hilosClass, array $errors): self
    {
        return new self("Incomplete Hilos feature activation in {$hilosClass}:\n- " . implode("\n- ", $errors));
    }
}
