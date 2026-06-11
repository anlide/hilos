<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\UsersPageResponseSignalData;
use Demo\Chat\Tables\AdminUser\DTO\AdminUserUpdateActionDTO;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\DTO\TableActionErrorSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\DatabaseException;
use Hilos\HilosException;
use Throwable;

/**
 * Admin users browser table page action handler.
 *
 * @property ChatAgent $agent Page-owning chat agent
 */
final class AdminUsersPage extends AbstractPage
{
    public const string PAGE = PageConstants::ADMIN_USERS;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

    public const array ACTIONS = [
        ChatSignalConstants::USER_UPDATE => AdminUserUpdateActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_USERS,
    ];

    /**
     * Sends the admin users scope payload snapshot to the subscribing client.
     *
     * Replaces the legacy browser-table subscription default: the page answers
     * with the page_response signal carrying the read-only users-table
     * snapshot in the scope-payload wire form.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params from page subscription
     * @throws DatabaseException When loading the user snapshot fails
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            ChatSignalConstants::PAGE_RESPONSE,
            $acceptKey,
            new UsersPageResponseSignalData(self::PAGE, Hilos::$db->users->snapshotRows()),
        );
    }

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
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
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
