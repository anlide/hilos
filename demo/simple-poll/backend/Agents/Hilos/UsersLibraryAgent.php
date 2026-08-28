<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Agents\Hilos;

use Demo\SimplePoll\Auth\PollAuthMethods;
use Demo\SimplePoll\Auth\PollOAuthConfig;
use Demo\SimplePoll\Database\PollDbContext;
use Demo\SimplePoll\Hilos;
use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\HilosException;

/**
 * The simple-poll demo's users library - the project half of the framework sign-in feature (HIL-634).
 *
 * Every sign-in command lives in {@see AbstractUsersLibraryAgent}; what is here is the
 * handful of answers only this project can give: which collection its accounts live in,
 * how one is created and named, which methods an identifier may be offered, and the
 * provider wiring a social login runs on. Everything the surface actually does with those
 * answers is the framework's.
 *
 * Registered under {@see HilosAgentType::HILOS_USERS_LIBRARY} by this demo's own topology,
 * and reached because the demo declares {@see HilosFeature::AUTH}: the feature is what
 * turns the library's command names into this project's door.
 *
 * {@see afterUserCreated()} is deliberately not overridden: this demo keeps no event log, so
 * a new account is news to nobody here and the empty framework default is the whole truth.
 */
final class UsersLibraryAgent extends AbstractUsersLibraryAgent
{
    /**
     * @var list<string> The people it answers about, owned by the poll agent that writes them,
     *     on top of everything the framework library reads.
     */
    public const array READS_DB = [...parent::READS_DB, PollDbContext::users];

    /**
     * Names this demo's own accounts collection.
     *
     * @return string Collection name of the simple-poll users
     */
    protected function usersCollection(): string
    {
        return PollDbContext::users;
    }

    /**
     * Creates one account with the display name the ceremony earned.
     *
     * @param string $displayName Name to show for the new account
     * @return int Durable id of the created user
     * @throws EmptyValueException When the display name is empty
     * @throws HilosException When the insert fails
     */
    public function createUser(string $displayName): int
    {
        return (int)Hilos::$db->users->actions->createWithName($displayName)->id;
    }

    /**
     * Names one account the way this demo shows it.
     *
     * A row that is gone, or one carrying an empty name, answers null - the caller draws
     * its own placeholder rather than offering the person a blank label to recognize
     * themselves by.
     *
     * @param int $userId Account to name
     * @return ?string Name to show, or null when there is none
     * @throws HilosException When the lookup fails
     */
    public function displayNameOf(int $userId): ?string
    {
        $name = Hilos::$db->users[$userId]?->name;

        return $name === null || $name === '' ? null : $name;
    }

    /**
     * Builds the detector over the sign-in methods this demo has actually wired.
     *
     * @return IdentifierDetector Detector answering with this demo's enabled method keys
     */
    protected function buildAuthMethods(): IdentifierDetector
    {
        return PollAuthMethods::detector();
    }

    /**
     * Builds the OAuth service this demo's providers are configured on.
     *
     * @return OAuthService Service over the demo's provider credentials
     */
    protected function buildOAuthService(): ?OAuthService
    {
        return PollOAuthConfig::buildService();
    }
}
