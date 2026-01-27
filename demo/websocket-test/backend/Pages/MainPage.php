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
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Idea\Collection\IdeaCollectionNotManualException;
use Hilos\Logging\Logger\Logger;

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
     * @param string $clientId Client ID
     * @param IdeaUser $user User idea
     * @throws DatabaseException If operation fails
     * @throws IdeaCollectionNotManualException
     */
    protected function handleSubscribe(string $clientId, IdeaUser $user): void
    {
        // Exclude the joining user from receiving their own join event
        $this->chatContext->addEvent(ChatEventType::USER_JOINED, $user->id, [
            'users' => [$this->toPublicUserArray($user->toArray(withId: true, idAsIndex: false))],
        ], $clientId);

        // Send subscription response with all events and user info
        $onlineUserIds = $this->chatContext->getOnlineUserIds(PageConstants::MAIN);

        $onlineUsers = Idea::$idea->users->filter(
            static fn (IdeaUser $idea): bool => $idea->id !== null && in_array($idea->id, $onlineUserIds, true)
        );

        $subscriptionData = new SubscriptionResponseSignalData(
            events: Idea::$idea->events->toArray(idAsIndex: false),
            users: $this->toPublicUserArrayList($onlineUsers->toArray(withId: true, idAsIndex: false)),
            userId: $user->id,
            username: $user->name,
        );

        // Wrap subscription data in WebSocketSignalData for WebSocket routing
        $signalData = new WebSocketSignalData(
            data: $subscriptionData,
            targetClientId: $clientId,
            targetGroup: null,
            excludeClientId: null,
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
     * @param string $clientId Client id
     * @param int $userId User id
     * @throws DatabaseException If database operation fails
     */
    protected function handleUnsubscribe(string $clientId, int $userId): void
    {
        // Add user left event (clientId must NOT be included in event data)
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
     * @param string $clientId Client id
     * @param int $userId User id
     * @param string $action Action name
     * @param string $payload Action payload
     * @return void
     * @throws DatabaseException
     */
    protected function handleAction(string $clientId, int $userId, string $action, string $payload): void
    {
        if ($action === ChatSignalConstants::MESSAGE) {
            $this->handleMessageAction($clientId, $userId, $payload);
        } else {
            Logger::logAgentError('MainPage', "Unknown action: {$action}");
        }
    }

    /**
     * Handle "message" action.
     * Expects JSON payload like: { "type": "message", "content": "..." } (frontend)
     *
     * @param string $clientId Client id
     * @param int $userId User id
     * @param string $payload Raw websocket payload
     * @throws DatabaseException If database operation fails
     */
    private function handleMessageAction(string $clientId, int $userId, string $payload): void
    {
        $payloadData = $this->tryDecodeJsonPayload($payload);
        if ($payloadData === null) {
            Logger::logAgentError('MainPage', "Invalid JSON payload for message action (clientId={$clientId})");
            return;
        }

        // We accept both: {content:"..."} and {data:{message:"..."}} for forward/backward compatibility
        $content = $payloadData['content'] ?? null;
        if ($content === null && isset($payloadData['data']) && is_array($payloadData['data'])) {
            $content = $payloadData['data']['message'] ?? null;
        }

        if (!is_string($content) || trim($content) === '') {
            Logger::logAgentError('MainPage', "Empty message content (clientId={$clientId}, userId={$userId})");
            return;
        }

        $this->chatContext->addEvent(ChatEventType::MESSAGE_SENT, $userId, [
            'message' => $content,
        ]);
    }

    /**
     * Try to decode JSON payload into associative array.
     *
     * @param string $payload Raw websocket payload
     * @return ?array<string,mixed> Decoded payload or null if invalid JSON
     */
    private function tryDecodeJsonPayload(string $payload): ?array
    {
        $payload = trim($payload);
        if ($payload === '') {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }
}
