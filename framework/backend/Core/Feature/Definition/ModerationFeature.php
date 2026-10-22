<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Core\Feature\FeatureActivationValidator;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;

/**
 * Checks of what people publish, each verdict sent to the agent its kind names (HIL-1623).
 *
 * Nothing is listed here yet. The agents the feature needs are the recipients its kinds name,
 * so they follow from the project's directory and {@see FeatureActivationValidator} judges them
 * kind by kind. The rules table, the moderator and its settings join the requirements with the
 * leaves that build them (HIL-1624, HIL-1625).
 */
final class ModerationFeature extends FeatureDefinition
{
    /** @return HilosFeature Moderation feature */
    public function feature(): HilosFeature
    {
        return HilosFeature::MODERATION;
    }

    /** @return FeatureRequirements Nothing beyond the verdict recipients the declared kinds name */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements();
    }
}
