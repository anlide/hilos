<?php

declare(strict_types=1);

namespace Demo\Tasks\Agents\Hilos;

use Demo\Tasks\Auth\TasksOAuthConfig;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\HilosFeature;
use Hilos\HilosException;

/**
 * The tasks demo's users library - the project half of the framework sign-in feature (HIL-623).
 *
 * Every sign-in command lives in {@see AbstractUsersLibraryAgent}, and so does the person -
 * created, named and guarded against deletion on the framework's table. What is here is the
 * handful of answers only this project can give: which methods an identifier may be offered
 * and the provider wiring a social login runs on. Renaming a person, its journal row and the
 * notice to the renamed are the framework's too (HIL-1195). Everything the surface actually
 * does with those answers is the framework's.
 *
 * Registered under {@see HilosAgentType::HILOS_USERS_LIBRARY} by this demo's own topology,
 * and reached because the demo declares {@see HilosFeature::AUTH}: the feature is what
 * turns the library's command names into this project's door.
 *
 * {@see afterUserCreated()} and {@see afterUserRenamed()} are deliberately not overridden: this
 * demo keeps no event log, so a new account or a new name is news to nobody here and the empty
 * framework default is the whole truth.
 */
final class UsersLibraryAgent extends AbstractUsersLibraryAgent
{
    /**
     * Builds the OAuth service this demo's providers are configured on.
     *
     * @return OAuthService Service over the demo's provider credentials
     * @throws HilosException Whatever reading the OAuth providers' configuration raises
     */
    protected function buildOAuthService(): ?OAuthService
    {
        return TasksOAuthConfig::buildService();
    }
}
