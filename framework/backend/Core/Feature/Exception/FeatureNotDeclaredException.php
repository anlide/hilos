<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Exception;

use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Topology\Exception\TopologyException;

/**
 * Exception thrown when code calls the door of a feature the project did not declare.
 *
 * A topology exception for the reason {@see IncompleteFeatureActivationException} is one: the
 * call and the declaration disagree about how the project was composed. Refusing at the door
 * matters where the door is fire-and-forget - the frame would reach nobody, and the caller would
 * believe the work done.
 */
final class FeatureNotDeclaredException extends TopologyException
{
    /**
     * Creates the refusal for one feature.
     *
     * @param HilosFeature $feature Feature whose door was called
     * @return self Exception instance
     */
    public static function forFeature(HilosFeature $feature): self
    {
        return new self("HilosFeature::{$feature->name} is not declared by this project, so its door has nobody behind it");
    }
}
