<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\View\Item\UserRename;
use Hilos\HilosException;

/**
 * The chat demo's users library - the project half of the framework sign-in feature (HIL-622).
 *
 * Every sign-in command lives in {@see AbstractUsersLibraryAgent}, and so does the person -
 * created, named and guarded against deletion on the framework's table. What stayed behind is
 * the handful of answers only this project can give: what else happens when an account is born,
 * which methods an identifier may be offered, and the provider wiring a social login runs on.
 *
 * The framework also handles a person's own rename; chat records it in the room's feed
 * through `afterUserRenamed()`.
 *
 * Registered under {@see HilosAgentType::HILOS_USERS_LIBRARY} by the chat's own topology, and
 * reached because the chat declares {@see HilosFeature::AUTH}: the feature is what turns the
 * library's command names into this project's door.
 */
final class UsersLibraryAgent extends AbstractUsersLibraryAgent
{
    /**
     * The chat tables this library writes from its OWN process.
     *
     * The account row itself is not among them: the framework's library claims it (HIL-1194).
     *
     * The registry is per process, and the account event is written HERE rather than in the agent
     * that owns the room: a claim registered by the chat agent covers the chat agent's worker and
     * nothing else, so without this the "registered in chat" line would be refused as a write with
     * no truth source behind it. The same second claim the admin index agent makes on these
     * tables, and for the same reason. The rename's feed line lands in the same table, written
     * here by {@see afterUserRenamed()}; the journal row it links is the framework library's
     * claim (HIL-1196).
     *
     * The two claims never reach the cluster's arbiter as a clash, because both agents are placed
     * the same way - neither names a placement, so both are the leader's, and a second claim from
     * the node that already holds one is not a second node.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [
        ChatDbContext::events => TruthSourceOperation::BY_KIND,
        ChatDbContext::eventUserRegistrations => TruthSourceOperation::BY_KIND,
    ];

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
     * Writes a rename into the room's feed: the event, and the journal row linked to it (HIL-1196).
     *
     * One transaction, so the feed line is whole or absent. The rename committed before this
     * runs, so the transaction is a top-level one; a failure rolls it back and goes out to the
     * framework, which logs it - the rename stands.
     *
     * @param UserRename $rename Journal row of the rename just committed
     * @throws HilosException When the event, the link or the transaction cannot be written
     */
    public function afterUserRenamed(UserRename $rename): void
    {
        Database::transactionStart();
        try {
            Hilos::$db->events->actions->addUserRenamed($rename);
            Database::transactionCommit();
        } catch (HilosException $e) {
            Database::transactionRollback();
            throw $e;
        }
    }

    /**
     * Builds the OAuth service the chat's providers are configured on.
     *
     * @return OAuthService Service over the demo's provider credentials
     * @throws HilosException Whatever reading the OAuth providers' configuration raises
     */
    protected function buildOAuthService(): ?OAuthService
    {
        return ChatOAuthConfig::buildService();
    }
}
