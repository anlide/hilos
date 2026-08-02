<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Pages\Hilos\Users;

use Demo\SimplePoll\Browser\PollBrowserRef;
use Demo\SimplePoll\Browser\PollBrowserSource;
use Demo\SimplePoll\Constants\AgentType;
use Demo\SimplePoll\Core\Router\DTO\ActionFailSignalData;
use Demo\SimplePoll\Core\Router\DTO\ActionSuccessSignalData;
use Demo\SimplePoll\Hilos;
use Demo\SimplePoll\Tables\HilosUser\DTO\HilosUserUpdateActionDTO;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserSubscriptionError;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\HilosException;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Throwable;

/**
 * Simple-poll implementation of the Hilos user-detail page.
 *
 * Subscription snapshots are browser-config driven. The update action renames the
 * selected user through the users table action, records a standalone rename audit
 * row, and sends modal success/fail acks.
 */
final class UserPage extends AbstractHilosUserPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_USER_UPDATE => HilosUserUpdateActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_USER,
        BrowserConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_USER_USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserConfigKey::GUARDS => [
            [
                BrowserGuardKey::TYPE => BrowserGuardType::DB_EXISTS,
                BrowserGuardKey::SOURCE => PollBrowserSource::DB_USERS,
                BrowserGuardKey::KEY => PollBrowserRef::HILOS_USER_ID,
                BrowserGuardKey::ERROR => BrowserSubscriptionError::NOT_FOUND,
            ],
        ],
    ];

    /**
     * Routes Hilos user-detail actions to page handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws HilosException When the user rename or success ack fails
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_USER_UPDATE:
                if (!$dto instanceof HilosUserUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosUserUpdateActionDTO::class, $dto);
                }
                $this->handleHilosUserUpdate($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Sends Hilos user update failures through the user-detail modal ack contract.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name that failed
     * @param ActionPayloadDTO $dto Action payload
     * @param Throwable $e Action failure
     */
    public function onActionException(string $acceptKey, string $action, ActionPayloadDTO $dto, Throwable $e): void
    {
        if ($action === HilosSignalConstants::HILOS_USER_UPDATE) {
            $this->sendToUser(
                HilosSignalConstants::HILOS_USER_UPDATE_FAIL,
                $acceptKey,
                new ActionFailSignalData($e->getMessage()),
            );

            return;
        }

        parent::onActionException($acceptKey, $action, $dto, $e);
    }

    /**
     * Renames the selected user, records a rename audit row, and acks success.
     *
     * Thrown failures become a dedicated fail ack through onActionException().
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param HilosUserUpdateActionDTO $dto Update action payload
     * @throws HilosException When the rename, audit write, or success ack fails
     */
    private function handleHilosUserUpdate(string $acceptKey, HilosUserUpdateActionDTO $dto): void
    {
        $dbUser = Hilos::$db->users[$dto->id];
        $oldName = $dbUser?->name ?? '';
        Hilos::$table->hilosUsers[$dto->id]->actions->update($dto);
        $newName = $dbUser?->name ?? $oldName;

        Hilos::$db->userRenames->actions->add($dto->id, $oldName, $newName);

        $this->sendToUser(
            HilosSignalConstants::HILOS_USER_UPDATE_SUCCESS,
            $acceptKey,
            new ActionSuccessSignalData(),
        );
    }
}
