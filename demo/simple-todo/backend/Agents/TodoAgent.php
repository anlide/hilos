<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Agents;

use Demo\SimpleTodo\Constants\AgentType;
use Demo\SimpleTodo\Constants\TodoNotificationType;
use Demo\SimpleTodo\Constants\TodoSignalConstants;
use Demo\SimpleTodo\Database\TodoDbContext;
use Demo\SimpleTodo\Hilos;
use Demo\SimpleTodo\Runtime\View\Context\TodoRtContext;
use Demo\SimpleTodo\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Auth\Session\SessionToken;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\HilosException;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;

/**
 * Monopolistic todo worker that owns the main page subscription and the
 * WebSocket lifecycle signals.
 *
 * Session identity is durable: the handshake maps each session token cookie to
 * a user row, registering it on first connect and reusing it on reconnect, and
 * tracks the live socket as a runtime connection for presence.
 */
final class TodoAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::TODO;

    /**
     * Registers the user table and the connections runtime collection as this
     * worker's truth sources so their changes fan out to the browser.
     */
    public function onStart(): void
    {
        $this->registerDbTruthSource(TodoDbContext::users);
        $this->registerRtTruthSource(TodoRtContext::connections);
    }

    /**
     * Authenticates the session token cookie, finds or registers the durable
     * user, tracks the socket as a runtime connection, and replies with the
     * current-user entity fragment in the session-scope payload form.
     *
     * @param WebSocketHandshakeSignalDTO $data Accept key and the daemon-resolved session token
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws InvalidFormatException When the session token is not a 32-character lowercase hex string
     * @throws HilosException On database or runtime failure while resolving the user or registering the connection
     */
    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void
    {
        // The daemon resolved the session token on the 101 (the client's cookie
        // or a freshly issued one) and carried it on the handshake DTO. Validate
        // inside the ValidationException family so the worker dispatcher contains
        // a bad token instead of crashing.
        $sessionToken = $data->sessionToken;
        SessionToken::ensureValid($sessionToken);

        $user = Hilos::$db->users->findBySession($sessionToken);
        if ($user === null) {
            $user = Hilos::$db->users->actions->register($sessionToken);
            $this->notifyAdminsOfNewUser((int)$user->id, $user->name);
        }
        $userId = (int)$user->id;

        Hilos::$rt->connections->actions->register($data->acceptKey, $userId);

        $this->sendToUser(
            TodoSignalConstants::HANDSHAKE_RESPONSE,
            $data->acceptKey,
            new HandshakeResponseSignalData(
                selfId: $userId,
                selfName: $user->name,
                selfAdmin: $user->admin,
            ),
        );
    }

    /**
     * Unregisters the closed WebSocket connection from runtime presence.
     *
     * @param WebSocketCloseSignalDTO $data Closed WebSocket connection
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException On runtime cleanup failure
     */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        Hilos::$rt->connections[$data->acceptKey]?->actions->unregister();
    }

    /**
     * Clears runtime connection state on shutdown; the user table persists.
     *
     * @throws HilosException On runtime cleanup failure
     */
    public function onStop(): void
    {
        Hilos::$rt->connections->actions->clear();
    }

    /**
     * Tells every administrator that a new account appeared.
     *
     * This demo registers a user per new guest session, so the visitor stream is the
     * notification stream - accepted as the price of showing the line alive. Nobody
     * holds the admin flag: nothing is sent, and that is not an error. The emit is
     * best-effort - the visitor is registered and served whatever happens to it.
     *
     * @param int $userId Newly registered user id
     * @param string $userName Display name the registration assigned
     */
    private function notifyAdminsOfNewUser(int $userId, string $userName): void
    {
        try {
            foreach (Hilos::$db->users->listAll() as $admin) {
                if ($admin->id === null || $admin->id === $userId || !$admin->admin || $admin->block) {
                    continue;
                }

                Hilos::$notify?->emit(new NotificationDraft(
                    userId: $admin->id,
                    type: TodoNotificationType::USER_REGISTERED,
                    title: 'New user joined: ' . $userName,
                    severity: NotificationSeverity::INFO,
                    data: [
                        'userId' => $userId,
                        'userName' => $userName,
                    ],
                ));
            }
        } catch (HilosException $e) {
            $this->logAgentError("New-user notification failed for userId={$userId}: {$e->getMessage()}");
        }
    }
}
