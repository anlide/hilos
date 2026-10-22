<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\FeatureActivationValidator;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\ModerationRule;

/**
 * Checks of what people publish, each verdict sent to the agent its kind names (HIL-1623).
 *
 * Administrators' own rules of every kind live in one framework table, and its single writer is
 * the framework rules library (HIL-1624): both are listed here. The library is shared rather than
 * the feature's own because a project registers it before switching moderation on, the way the
 * chat does. The verdict recipients are not listed: they follow from the project's kind directory,
 * and {@see FeatureActivationValidator} judges them kind by kind. The moderator and its settings
 * join the requirements with the leaf that builds them (HIL-1625).
 */
final class ModerationFeature extends FeatureDefinition
{
    /** @return HilosFeature Moderation feature */
    public function feature(): HilosFeature
    {
        return HilosFeature::MODERATION;
    }

    /** @return FeatureRequirements The rules library pair and the rules table */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredSharedAgents: [HilosAgentType::HILOS_MODERATION_RULES_LIBRARY],
            requiredDbTables: [ModerationRule::_table],
        );
    }
}
