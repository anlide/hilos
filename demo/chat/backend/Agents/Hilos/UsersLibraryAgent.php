<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\Auth\ChatAuthMethods;
use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\HilosException;

/**
 * The chat demo's users library - the project half of the framework sign-in feature (HIL-622).
 *
 * Every sign-in command lives in {@see AbstractUsersLibraryAgent} now; what stayed behind
 * is the handful of answers only this project can give: which collection its members live
 * in, how one is created and named, what else happens when an account is born, which
 * methods an identifier may be offered, and the provider wiring a social login runs on.
 * Everything the surface actually does with those answers is the framework's.
 *
 * Registered under {@see HilosAgentType::HILOS_USERS_LIBRARY} by the chat's own topology, and
 * reached because the chat declares {@see HilosFeature::AUTH}: the feature is what turns the
 * library's command names into this project's door.
 */
final class UsersLibraryAgent extends AbstractUsersLibraryAgent
{
    /**
     * @var list<string> The people it answers about, owned by the chat agent that writes them,
     *     on top of everything the framework library reads.
     */
    public const array READS_DB = [...parent::READS_DB, ChatDbContext::users];

    /**
     * Claims the chat tables this library writes from its OWN process.
     *
     * The registry is per process, and the account event below is written HERE rather than
     * in the agent that owns the room: a claim registered by the chat agent covers the chat
     * agent's worker and nothing else, so without this the "registered in chat" line would
     * be refused as a write with no truth source behind it. The same second claim the
     * admin index agent makes on these tables, and for the same reason.
     *
     * @throws HilosException On database or runtime startup failure
     */
    public function onStart(): void
    {
        parent::onStart();

        $this->registerDbTruthSource(ChatDbContext::events);
        $this->registerDbTruthSource(ChatDbContext::eventUserRegistrations);
    }

    /**
     * Names the chat's own members collection.
     *
     * @return string Collection name of the chat users
     */
    protected function usersCollection(): string
    {
        return ChatDbContext::users;
    }

    /**
     * Creates one chat user with the display name the ceremony earned.
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
     * Names one chat account the way the room shows it.
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
     * Announces a new member in the room the moment the account exists.
     *
     * @param int $userId User that was just created
     * @param string $identifier Normalized identifier the account was created for (unused)
     * @throws HilosException When the event write fails
     * @throws LogicException If event id is null after sync
     */
    public function afterUserCreated(int $userId, string $identifier): void
    {
        Hilos::$db->events->actions->addUserRegistered($userId);
    }

    /**
     * Builds the detector over the sign-in methods this demo has actually wired.
     *
     * @return IdentifierDetector Detector answering with the chat's enabled method keys
     */
    protected function buildAuthMethods(): IdentifierDetector
    {
        return ChatAuthMethods::detector();
    }

    /**
     * Builds the OAuth service the chat's providers are configured on.
     *
     * @return OAuthService Service over the demo's provider credentials
     */
    protected function buildOAuthService(): ?OAuthService
    {
        return ChatOAuthConfig::buildService();
    }
}
