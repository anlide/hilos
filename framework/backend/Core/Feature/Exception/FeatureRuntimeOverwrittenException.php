<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Exception;

use Hilos\Core\Topology\Exception\TopologyException;
use Hilos\Runtime\View\Context\RtContext;

/**
 * Exception thrown when a project replaced runtime state the framework mounted for a feature.
 *
 * Separate from {@see IncompleteFeatureActivationException} because it reports the opposite
 * mistake: not a feature that was declared and left unfinished, but one that was declared and
 * then mounted a second time by hand. Both are topology exceptions - the registries and the
 * declaration disagree - so both fail the start through the handlers a bad topology already has.
 *
 * Raised by {@see RtContext::assertFeatureRuntimeIntact()} after the project configured itself.
 */
final class FeatureRuntimeOverwrittenException extends TopologyException
{
    /**
     * Creates a readable overwrite failure from collected errors.
     *
     * @param class-string<RtContext> $contextClass Runtime context class that was configured
     * @param list<string> $errors Overwrite error messages
     * @return self Exception instance
     */
    public static function forErrors(string $contextClass, array $errors): self
    {
        return new self("Framework-owned runtime state overwritten in {$contextClass}:\n- " . implode("\n- ", $errors));
    }
}
