<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Database\Idea;
use Demo\Chat\DTO\Action\RenameActionDTO;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\DTO\Action\ActionPayloadDTO;
use Hilos\DTO\EntitiesChangesDTO;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Idea\Actions\IdeaActionsObjectCollectionNullException;
use Hilos\Exception\Idea\Actions\IdeaActionsTableNameUndeterminedException;
use Hilos\Exception\Idea\Actions\IdeaActionsUnknownLazyStrategyException;
use Hilos\Exception\Idea\TruthSource\IdeaTruthSourceWriteNotAllowedException;
use Hilos\Logging\Logger\Logger;

/**
 * ProfilePage - User profile page handler
 *
 * Handles subscription, unsubscription, and actions for the user profile page.
 */
class ProfilePage extends AbstractChatPage
{
    /**
     * Get page name
     *
     * @return string Page name
     */
    public function getPageName(): string
    {
        return PageConstants::PROFILE;
    }

    /**
     * Handle page-specific subscription logic
     *
     * @param string $acceptKey Accept key
     */
    public function onSubscribe(string $acceptKey): void
    {
        $this->signalRouter->queueSignal(
            signalSource: $this->agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName(ChatSignalConstants::SUBSCRIPTION_PAGE_PROFILE),
            signalData: new WebSocketSignalData(
                data: new SignalData(),
                targetAcceptKey: $acceptKey,
            ),
        );
    }

    /**
     * Handle page-specific unsubscription logic
     *
     * @param string $acceptKey Accept key
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        // TODO: Implement profile page unsubscribe logic
    }

    /**
     * Handle page-specific action logic
     *
     * @param string $acceptKey Accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     * @throws DatabaseException
     * @throws IdeaActionsObjectCollectionNullException
     * @throws IdeaActionsUnknownLazyStrategyException
     * @throws IdeaTruthSourceWriteNotAllowedException
     * @throws IdeaActionsTableNameUndeterminedException
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        switch ($action) {
            case ChatSignalConstants::RENAME:
                if ($dto instanceof RenameActionDTO) {
                    $this->handleRename($acceptKey, $dto);
                }
                break;

            default:
                Logger::logAgentError('ProfilePage', "Unknown action: {$action}");
        }
    }

    /**
     * Handle rename action
     *
     * @param string $acceptKey Accept key
     * @param RenameActionDTO $dto Rename DTO
     * @throws DatabaseException
     * @throws IdeaActionsObjectCollectionNullException
     * @throws IdeaActionsUnknownLazyStrategyException
     * @throws IdeaTruthSourceWriteNotAllowedException
     * @throws IdeaActionsTableNameUndeterminedException
     */
    private function handleRename(string $acceptKey, RenameActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            Logger::logAgentError('ProfilePage', "Empty new name (acceptKey={$acceptKey})");
            return;
        }

        if (!isset(Idea::$rt->connections[$acceptKey])) {
            Logger::logAgentError('ProfilePage', "User not found for acceptKey={$acceptKey}");
            return;
        }

        $oldName = Idea::$db->users[Idea::$rt->connections[$acceptKey]->userId]->name;
        Idea::$db->users->actions->rename(Idea::$rt->connections[$acceptKey]->userId, $dto->newName);

        $entities = new EntitiesChangesDTO(
            updates: [
                Idea::users => [
                    ['id' => Idea::$rt->connections[$acceptKey]->userId, 'name' => $dto->newName],
                ],
            ],
        );

        $this->getChatAgent()->addEvent(ChatEventType::USER_RENAMED, Idea::$rt->connections[$acceptKey]->userId, [
            'oldName' => $oldName,
            'newName' => $dto->newName,
        ], $entities);
    }
}
