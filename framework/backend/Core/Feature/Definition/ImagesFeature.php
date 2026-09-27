<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;

/** Image copies drawn on demand by a dedicated agent and kept by the files library. */
final class ImagesFeature extends FeatureDefinition
{
    /** @return HilosFeature Image variants feature */
    public function feature(): HilosFeature
    {
        return HilosFeature::IMAGES;
    }

    /** @return FeatureRequirements The rendering agent pair and the files registry it serves */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredAgents: [HilosAgentType::HILOS_IMAGES],
            requires: [HilosFeature::FILES],
        );
    }
}
