<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\Identity;
use Hilos\Database\Entity\Item\PasskeyCredential;
use Hilos\Database\Entity\Item\RegistrationReservation;
use Hilos\Database\Entity\Item\UserVerification;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\State\Collection\RecoveryWaiters as StateRecoveryWaiters;
use Hilos\Runtime\State\Collection\RegistrationWaiters as StateRegistrationWaiters;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;
use Hilos\Runtime\View\Actions\Collection\RecoveryWaitersActions;
use Hilos\Runtime\View\Actions\Collection\RegistrationWaitersActions;
use Hilos\Runtime\View\Collection\RecoveryWaiters;
use Hilos\Runtime\View\Collection\RegistrationWaiters;
use Hilos\Runtime\View\Context\RtContext;

/**
 * The sign-in surface: its commands, the library that owns them, and the tables they write.
 *
 * The project owes this feature two library agent pairs, and nothing else. One is
 * {@see AbstractUsersLibraryAgent}, which owns the user set and executes every command of
 * the surface; the other is {@see AbstractSessionsLibraryAgent}, which owns the sessions
 * those commands end in. Both are named by agent type like every other obligation in the
 * registry - until HIL-710 the session holder was FOUND instead, by looking for a class
 * that mixed a trait in, and that was the last requirement in the framework answered by
 * inspecting classes rather than by reading a declaration.
 *
 * Nothing here is a page. The sign-in surface is drawn by the frontend over a protocol of
 * action names, and those names are the library's AGENT_ACTIONS: a project activates the
 * door by declaring the feature, not by registering a page of its own.
 *
 * `requires` is deliberately EMPTY. Signing in with a password, with no SMS channel and no
 * anti-abuse layer, is a legitimate delivery; AUTH_THROTTLE and CODE_CHANNELS add to the
 * surface and are declared on their own.
 *
 * The two parked-surface collections are mounted here rather than by the project for the
 * reason the registry exists: they are the sign-in flow's own rows, read wherever a waiting
 * tab is converged, and a project that mounted them by hand would be declaring the feature
 * twice - with the second declaration free to drift.
 */
final class AuthFeature extends FeatureDefinition
{
    /**
     * @return HilosFeature Sign-in feature case
     */
    public function feature(): HilosFeature
    {
        return HilosFeature::AUTH;
    }

    /**
     * @return FeatureRequirements The two library agent pairs and the four tables the set lives in
     */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredAgents: [HilosAgentType::HILOS_USERS_LIBRARY],
            // Required as strictly, owned less so: a session is not a sign-in, and the two
            // demos with no login carry sessions without ever declaring this feature.
            requiredSharedAgents: [HilosAgentType::HILOS_SESSIONS_LIBRARY],
            requiredDbTables: [
                Identity::_table,
                UserVerification::_table,
                PasskeyCredential::_table,
                RegistrationReservation::_table,
            ],
        );
    }

    /**
     * Carries the parked sign-in surfaces, declared beside the mount it describes.
     *
     * @return bool Always true
     */
    public function mountsRuntime(): bool
    {
        return true;
    }

    /**
     * Mounts the registration and recovery waits with their framework representation.
     *
     * The representation is not optional decoration: the actions class is the only write
     * path that queues an RT sync, so a collection mounted without it would change in the
     * holder's worker and nowhere else.
     *
     * @param RtContext $context Runtime context being built
     * @throws StateCollectionNotFoundException When a wait is represented before it is mounted
     */
    public function mount(RtContext $context): void
    {
        $context->mountFeatureCollection(StateRegistrationWaiter::RT_COLLECTION, StateRegistrationWaiters::init());
        $context->setRepresent(
            StateRegistrationWaiter::RT_COLLECTION,
            RegistrationWaiters::class,
            RegistrationWaitersActions::class,
        );
        $context->mountFeatureCollection(StateRecoveryWaiter::RT_COLLECTION, StateRecoveryWaiters::init());
        $context->setRepresent(
            StateRecoveryWaiter::RT_COLLECTION,
            RecoveryWaiters::class,
            RecoveryWaitersActions::class,
        );
    }
}
