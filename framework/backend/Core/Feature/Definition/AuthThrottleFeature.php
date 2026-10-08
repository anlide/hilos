<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Auth\Throttle\AuthThrottleSettingsCatalog;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\AuthBlock;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\State\Collection\AuthAttempts as StateAuthAttempts;
use Hilos\Runtime\State\Item\AuthAttempt as StateAuthAttempt;
use Hilos\Runtime\View\Actions\Collection\AuthAttemptsActions;
use Hilos\Runtime\View\Actions\Item\AuthAttemptActions;
use Hilos\Runtime\View\Collection\AuthAttempts;
use Hilos\Runtime\View\Context\RtContext;

/**
 * Anti-abuse throttling of expensive auth actions: window counters, durable blocks and their agent.
 *
 * The project owes this feature exactly one thing - the agent pair that owns the counters -
 * and no page, because the layer has no surface: it answers inside the action dispatch and
 * shows itself only as a refusal the client already knows how to render. Which actions are
 * guarded is declared per page in THROTTLED_ACTIONS, not here, since that list is a property
 * of the project's own surface rather than of the framework's.
 *
 * The counters are mounted here rather than by the project for the reason the whole registry
 * exists: they are read in every worker that dispatches an action and written in exactly one,
 * so a project that mounted them by hand would be declaring the feature twice - and a key
 * mistyped in the second place produces a guard that reads an empty collection and lets
 * everything through, which is a defect that looks exactly like working software.
 *
 * Whether the layer refuses anything stays an env switch, and the test environment turns it
 * off; the registry answers what the project is built with, deployment answers what is on.
 * One number is required, though: {@see AuthThrottleSettingsCatalog} puts on the settings screen
 * how long the guarded doors may run while the agent is silent, and the start of a project whose
 * settings catalog never folded the fragment in is refused, since the grace would otherwise sit
 * at its default with no administrator able to reach it.
 */
final class AuthThrottleFeature extends FeatureDefinition
{
    /**
     * @return HilosFeature Auth throttle feature case
     */
    public function feature(): HilosFeature
    {
        return HilosFeature::AUTH_THROTTLE;
    }

    /**
     * @return FeatureRequirements The throttle agent pair, the durable block table and the settings fragment
     */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredAgents: [HilosAgentType::HILOS_AUTH_THROTTLE],
            requiredDbTables: [AuthBlock::_table],
            requiredCatalogFragments: [AuthThrottleSettingsCatalog::class],
        );
    }

    /**
     * Carries the runtime counters, declared beside the mount it describes.
     *
     * @return bool Always true
     */
    public function mountsRuntime(): bool
    {
        return true;
    }

    /**
     * Mounts the attempt counters with their framework representation.
     *
     * The representation is not optional decoration: the actions classes are the only write
     * path that queues an RT sync, so a collection mounted without them would change in the
     * agent's worker and nowhere else.
     *
     * @param RtContext $context Runtime context being built
     * @throws StateCollectionNotFoundException When the counters are represented before they are mounted
     */
    public function mount(RtContext $context): void
    {
        $context->mountFeatureCollection(StateAuthAttempt::RT_COLLECTION, StateAuthAttempts::init());
        $context->setRepresent(
            StateAuthAttempt::RT_COLLECTION,
            AuthAttempts::class,
            AuthAttemptsActions::class,
            AuthAttemptActions::class,
        );
    }
}
