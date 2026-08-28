<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Database\Object\Item\User;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\AdminUser\DTO\AdminUserUpdateActionDTO;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\DTO\TableActionErrorSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\HilosException;
use Throwable;

/**
 * Admin users browser table page action handler.
 *
 * @property ChatAgent $agent Page-owning chat agent
 */
final class AdminUsersPage extends AbstractPage
{
    /** @var list<string> The people its actions act on, and the events they are counted over */
    public const array READS_DB = [ChatDbContext::users, ChatDbContext::events];

    public const string PAGE = PageConstants::ADMIN_USERS;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

    public const array ACTIONS = [
        ChatSignalConstants::USER_UPDATE => AdminUserUpdateActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_USERS,
        BrowserConfigKey::GUARDS => [
            [
                BrowserGuardKey::TYPE => BrowserGuardType::ACCESS,
                BrowserGuardKey::SOURCE => ChatBrowserSource::DB_USERS,
                BrowserGuardKey::FIELD => User::admin,
            ],
        ],
    ];

    /**
     * Routes admin user actions to typed handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws TableActionException When the target user is invalid or missing
     * @throws HilosException When user update or audit event persistence fails
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case ChatSignalConstants::USER_UPDATE:
                if (!$dto instanceof AdminUserUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, AdminUserUpdateActionDTO::class, $dto);
                }
                $this->handleUserUpdate($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Sends admin users table action failures to the initiating client.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name that failed
     * @param ActionPayloadDTO $dto Action payload
     * @param Throwable $e Action failure
     */
    public function onActionException(string $acceptKey, string $action, ActionPayloadDTO $dto, Throwable $e): void
    {
        $this->sendToUser(
            ChatSignalConstants::TABLE_ACTION_ERROR,
            $acceptKey,
            new TableActionErrorSignalData(ChatTableContext::adminUsers, $action, $e->getMessage()),
        );
    }

    /**
     * Renames a user through the admin table action and records the audit event.
     *
     * @param string $acceptKey Requesting WebSocket accept key, kept for handler symmetry
     * @param AdminUserUpdateActionDTO $dto Update action payload
     * @throws TableActionException When user id is invalid or the user is missing
     * @throws HilosException When rename or audit event persistence fails
     */
    private function handleUserUpdate(string $acceptKey, AdminUserUpdateActionDTO $dto): void
    {
        if ($dto->id <= 0) {
            throw new TableActionException('Invalid user ID');
        }

        $dbUser = Hilos::$db->users[$dto->id]
            ?? throw new TableActionException("User #{$dto->id} not found");
        $oldName = $dbUser->name;

        Hilos::$table->adminUsers[$dto->id]->actions->update($dto);
        Hilos::$db->events->actions->addUserRenamedByAdmin(
            userId: $dto->id,
            oldName: $oldName,
            newName: $dto->name,
            adminUserId: Hilos::$rt->selfConnection?->userId,
        );
    }
}
