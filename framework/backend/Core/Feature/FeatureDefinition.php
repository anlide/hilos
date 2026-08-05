<?php

declare(strict_types=1);

namespace Hilos\Core\Feature;

use Hilos\Hilos;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\View\Context\RtContext;
use ReflectionMethod;

/**
 * One framework feature: what the project owes it, and what the framework mounts for it.
 *
 * The definition is the feature's single description. Everything the framework knows about
 * a feature is read from here - what it requires of the project ({@see self::requirements()})
 * and what runtime state it brings with it ({@see self::mount()}) - so switching a feature on
 * stays one line in {@see Hilos::FEATURES} instead of a checklist spread over registries.
 *
 * An abstract class rather than an interface because mounting is the exception: five of the six
 * features carry no runtime state at all, and inheriting the no-op is honest about that, where
 * an interface would demand five empty bodies that say nothing.
 */
abstract class FeatureDefinition
{
    /**
     * @return HilosFeature Feature this definition describes
     */
    abstract public function feature(): HilosFeature;

    /**
     * @return FeatureRequirements What the project must register for this feature to be complete
     */
    abstract public function requirements(): FeatureRequirements;

    /**
     * Mounts the framework-owned runtime state this feature brings, if any.
     *
     * Called by the runtime context for every declared feature, so a project that declares
     * the feature gets its rows without writing a line - which is the point: as long as the
     * project mounted them itself, the mount was a second, silent switch that could disagree
     * with the declaration.
     *
     * The default is a no-op: most features live entirely in pages, tables and agents.
     *
     * @param RtContext $context Runtime context being built
     * @throws StateCollectionNotFoundException When an implementation represents a collection it did not mount
     */
    public function mount(RtContext $context): void
    {
    }

    /**
     * Tells whether this feature brings runtime state with it.
     *
     * Answered by asking who declares {@see self::mount()} rather than by a flag a definition
     * sets, so the two can never disagree: a feature that grows a mount later starts requiring
     * a runtime context in the same edit, without anyone remembering to flip anything.
     *
     * The facade asks before it accepts a declaration: a project whose createRuntime() returns
     * null has nowhere to mount, and a feature that needs a place would be half-activated there.
     *
     * @return bool True when the definition overrides the no-op mount
     */
    final public function mountsRuntime(): bool
    {
        return (new ReflectionMethod(static::class, 'mount'))->getDeclaringClass()->getName() !== self::class;
    }
}
