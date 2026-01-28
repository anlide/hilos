<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Pages;

use Demo\WebSocketTest\Constants\ChatEventType;
use Demo\WebSocketTest\Constants\ChatSignalConstants;
use Demo\WebSocketTest\Constants\PageConstants;
use Demo\WebSocketTest\Core\Page\AbstractChatPage;
use Demo\WebSocketTest\Database\Idea;
use Demo\WebSocketTest\Database\Idea\User as IdeaUser;
use Hilos\DTO\EntitiesChangesDTO;
use Hilos\Logging\Logger\Logger;
use Hilos\Utils\Helpers\JsonHelper;

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
     * @param string $clientId Client ID
     * @param IdeaUser $user User idea
     */
    protected function handleSubscribe(string $clientId, IdeaUser $user): void
    {
        // TODO: Implement profile page subscription logic
    }

    /**
     * Handle page-specific unsubscription logic
     *
     * @param string $clientId Client id
     * @param int $userId User id
     */
    protected function handleUnsubscribe(string $clientId, int $userId): void
    {
        // TODO: Implement profile page unsubscribe logic
    }

    /**
     * Handle page-specific action logic
     *
     * @param string $clientId Client id
     * @param int $userId User id
     * @param string $action Action name
     * @param string $payload Action payload
     */
    protected function handleAction(string $clientId, int $userId, string $action, string $payload): void
    {
        if ($action !== ChatSignalConstants::RENAME) {
            Logger::logAgentError('ProfilePage', "Unknown action: {$action}");
            return;
        }

        $payloadData = JsonHelper::tryDecode($payload);
        if ($payloadData === null) {
            Logger::logAgentError('ProfilePage', "Invalid JSON payload for rename action (clientId={$clientId})");
            return;
        }

        $rawName = $payloadData['username'] ?? $payloadData['name'] ?? null;
        if (!is_string($rawName)) {
            Logger::logAgentError('ProfilePage', "Missing username in rename action (clientId={$clientId}, userId={$userId})");
            return;
        }

        $newName = trim($rawName);
        if ($newName === '' || strlen($newName) < 2 || strlen($newName) > 20) {
            Logger::logAgentError('ProfilePage', "Invalid username length in rename action (clientId={$clientId}, userId={$userId})");
            return;
        }

        try {
            $renameInfo = Idea::$idea->users->actions->rename($userId, $newName);
        } catch (\Throwable $e) {
            Logger::logAgentError('ProfilePage', "Rename failed for userId={$userId}: {$e->getMessage()}");
            return;
        }

        if ($renameInfo === null) {
            return;
        }

        $eventData = [
            'oldName' => $renameInfo['oldName'],
            'newName' => $renameInfo['newName'],
        ];

        $entities = new EntitiesChangesDTO(
            updates: [
                'users' => [[
                    'id' => $userId,
                    'name' => $renameInfo['newName'],
                    'lastActivity' => $renameInfo['lastActivity'],
                ]],
            ],
        );

        $this->chatContext->addEvent(ChatEventType::USER_RENAMED, $userId, $eventData, $entities);
    }
}
