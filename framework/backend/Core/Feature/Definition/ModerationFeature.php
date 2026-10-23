<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\FeatureActivationValidator;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\ModerationRule;
use Hilos\Moderation\ModerationSettingsCatalog;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\State\Collection\HilosModerationChecks as StateHilosModerationChecks;
use Hilos\Runtime\State\Item\HilosModerationCheck as StateHilosModerationCheck;
use Hilos\Runtime\View\Actions\Collection\HilosModerationChecksActions;
use Hilos\Runtime\View\Actions\Item\HilosModerationCheckActions;
use Hilos\Runtime\View\Collection\HilosModerationChecks;
use Hilos\Runtime\View\Context\RtContext;

/**
 * Checks of what people publish, each verdict sent to the agent its kind names (HIL-1623).
 *
 * Administrators' own rules of every kind live in one framework table, and its single writer is
 * the framework rules library (HIL-1624): both are listed here. The library is shared rather than
 * the feature's own because a project registers it before switching moderation on, the way the
 * chat does. The verdict recipients are not listed: they follow from the project's kind directory,
 * and {@see FeatureActivationValidator} judges them kind by kind.
 *
 * The moderator exists for this feature alone, and its models are switched by the moderation
 * settings, so the project folds {@see ModerationSettingsCatalog} into its own catalog and runs the
 * settings library that writes them (HIL-1625). Nothing about images is required: an image kind
 * needs uploads, which the profile photo feature brings with it, and a project that moderates text
 * alone runs the moderator without them.
 *
 * The checks in flight live in a framework runtime collection the feature mounts, so a project
 * that does not moderate has none to read (HIL-1625).
 */
final class ModerationFeature extends FeatureDefinition
{
    /** @return HilosFeature Moderation feature */
    public function feature(): HilosFeature
    {
        return HilosFeature::MODERATION;
    }

    /** @return FeatureRequirements The moderator pair, the rules and settings library pairs, the settings fragment and the rules table */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredAgents: [HilosAgentType::HILOS_MODERATOR],
            requiredSharedAgents: [HilosAgentType::HILOS_MODERATION_RULES_LIBRARY, HilosAgentType::HILOS_SETTINGS_LIBRARY],
            requiredCatalogFragments: [ModerationSettingsCatalog::class],
            requiredDbTables: [ModerationRule::_table],
        );
    }

    /** @return bool Moderation checks use a framework runtime collection */
    public function mountsRuntime(): bool
    {
        return true;
    }

    /**
     * @param RtContext $context Runtime context being built
     * @throws StateCollectionNotFoundException When the check representation is mounted before its state
     */
    public function mount(RtContext $context): void
    {
        $context->mountFeatureCollection(StateHilosModerationCheck::RT_COLLECTION, StateHilosModerationChecks::init());
        $context->setRepresent(
            StateHilosModerationCheck::RT_COLLECTION,
            HilosModerationChecks::class,
            HilosModerationChecksActions::class,
            HilosModerationCheckActions::class,
        );
    }
}
