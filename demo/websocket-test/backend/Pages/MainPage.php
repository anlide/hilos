<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Pages;

use Demo\WebSocketTest\Constants\ChatEventType;
use Demo\WebSocketTest\Constants\ChatSignalConstants;
use Demo\WebSocketTest\Constants\PageConstants;
use Demo\WebSocketTest\Core\Page\AbstractChatPage;
use Demo\WebSocketTest\Database\Idea;
use Demo\WebSocketTest\Database\Idea\User as IdeaUser;
use Demo\WebSocketTest\DTO\SubscriptionResponseSignalData;
use Hilos\Constants\SignalTypeConstants as HilosSignalTypeConstants;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\DTO\EntitiesChangesDTO;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Idea\Collection\IdeaCollectionNotManualException;
use Hilos\Logging\Logger\Logger;
use Hilos\Utils\Helpers\JsonHelper;

/**
 * MainPage - Main chat page handler
 *
 * Handles subscription, unsubscription, and actions for the main chat page.
 */
class MainPage extends AbstractChatPage
{
    /**
     * Get page name
     *
     * @return string Page name
     */
    public function getPageName(): string
    {
        return PageConstants::MAIN;
    }

    /**
     * Handle page-specific subscription logic
     *
     * @param string $acceptKey Accept key
     * @param IdeaUser $user User idea
     * @throws DatabaseException If operation fails
     * @throws IdeaCollectionNotManualException
     */
    protected function handleSubscribe(string $acceptKey, IdeaUser $user): void
    {
        // Exclude the joining user from receiving their own join event
        $publicUser = $this->toPublicUserArray($user->toArray(withId: true, idAsIndex: false));
        $entities = new EntitiesChangesDTO(
            full: ['users' => [$publicUser]],
        );
        $this->chatContext->addEvent(ChatEventType::USER_JOINED, $user->id, null, $entities, $acceptKey);

        // Send subscription response with all events and user info
        $subscriptionEntities = new EntitiesChangesDTO(
            full: ['users' => []],
        );
        $subscriptionData = new SubscriptionResponseSignalData(
            events: Idea::$idea->events->toArray(idAsIndex: false),
            entities: $subscriptionEntities,
            userId: $user->id,
            username: $user->name,
        );

        // Wrap subscription data in WebSocketSignalData for WebSocket routing
        $signalData = new WebSocketSignalData(
            data: $subscriptionData,
            targetAcceptKey: $acceptKey,
            targetGroup: null,
            excludeAcceptKey: null,
        );

        $this->signalRouter->queueSignal(
            signalSource: $this->chatContext->getAgentSignalSource(),
            signalType: new SignalType(HilosSignalTypeConstants::WS_USER),
            signalName: new SignalName('subscription_response'),
            signalData: $signalData,
        );
    }

    /**
     * Handle page-specific unsubscription logic
     *
     * @param string $acceptKey Accept key
     * @param int $userId User id
     * @throws DatabaseException If database operation fails
     */
    protected function handleUnsubscribe(string $acceptKey, int $userId): void
    {
        // Add user left event (acceptKey must NOT be included in event data)
        $this->chatContext->addEvent(ChatEventType::USER_LEFT, $userId);
    }

    /**
     * Remove sensitive fields from user array
     *
     * @param array $user
     * @return array
     */
    private function toPublicUserArray(array $user): array
    {
        unset($user['sessionToken']);
        return $user;
    }

    /**
     * Map user list to public arrays
     *
     * @param array $users
     * @return array
     */
    private function toPublicUserArrayList(array $users): array
    {
        return array_map(fn (array $user): array => $this->toPublicUserArray($user), $users);
    }

    /**
     * Handle page-specific action logic
     *
     * @param string $acceptKey Accept key
     * @param int $userId User id
     * @param string $action Action name
     * @param string $payload Action payload
     * @return void
     * @throws DatabaseException
     */
    protected function handleAction(string $acceptKey, int $userId, string $action, string $payload): void
    {
        if ($action === ChatSignalConstants::MESSAGE) {
            $this->handleMessageAction($acceptKey, $userId, $payload);
        } else {
            Logger::logAgentError('MainPage', "Unknown action: {$action}");
        }
    }

    /**
     * Handle "message" action.
     * Expects JSON payload like: { "type": "message", "content": "..." } (frontend)
     *
     * @param string $acceptKey Accept key
     * @param int $userId User id
     * @param string $payload Raw websocket payload
     * @throws DatabaseException If database operation fails
     */
    private function handleMessageAction(string $acceptKey, int $userId, string $payload): void
    {
        $payloadData = JsonHelper::tryDecode($payload);
        if ($payloadData === null) {
            Logger::logAgentError('MainPage', "Invalid JSON payload for message action (acceptKey={$acceptKey})");
            return;
        }

        // We accept both: {content:"..."} and {data:{message:"..."}} for forward/backward compatibility
        $content = $payloadData['content'] ?? null;
        if ($content === null && isset($payloadData['data']) && is_array($payloadData['data'])) {
            $content = $payloadData['data']['message'] ?? null;
        }

        if (!is_string($content) || trim($content) === '') {
            Logger::logAgentError('MainPage', "Empty message content (acceptKey={$acceptKey}, userId={$userId})");
            return;
        }

        $this->chatContext->addEvent(ChatEventType::MESSAGE_SENT, $userId, [
            'message' => $content,
        ]);
    }
}
