<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\UserRenameFeedLinkSignalData;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\TruthSource\TruthSourceOperation;
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
     * Creates the registration and rename feed rows. Later room changes belong to ChatAgent.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [
        ChatDbContext::events => [TruthSourceOperation::Add],
        ChatDbContext::eventUserRegistrations => [TruthSourceOperation::Add],
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
     * Writes the feed event, then asks the person's agent to link the journal row (HIL-1896).
     * An insert or enqueue failure reaches the framework caller, which logs it; the name stands.
     *
     * @param UserRename $rename Journal row of the rename just committed
     * @throws HilosException When the event or the frame cannot be written
     * @throws LogicException If event id is null after sync
     */
    public function afterUserRenamed(UserRename $rename): void
    {
        $event = Hilos::$db->events->actions->addUserRenamed($rename);
        $this->sendToAgent(
            ChatSignalConstants::USER_RENAME_FEED_LINK,
            new UserRenameFeedLinkSignalData($rename->userId, (int)$rename->id, (int)$event->id),
        );
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
