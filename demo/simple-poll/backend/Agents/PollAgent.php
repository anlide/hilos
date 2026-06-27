<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Agents;

use Demo\SimplePoll\Constants\AgentType;
use Demo\SimplePoll\Constants\PollSignalConstants;
use Demo\SimplePoll\Database\PollDbContext;
use Demo\SimplePoll\Hilos;
use Demo\SimplePoll\Runtime\View\Context\PollRtContext;
use Demo\SimplePoll\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;

/**
 * Monopolistic poll worker that owns the main page subscription and the
 * WebSocket lifecycle signals.
 *
 * Session identity is durable: the handshake maps each session token cookie to
 * a user row, registering it on first connect and reusing it on reconnect, and
 * tracks the live socket as a runtime connection for presence.
 */
final class PollAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::POLL;

    /** @var string Session token cookie format: 32 lowercase hex characters */
    private const string SESSION_TOKEN_PATTERN = '/\A[0-9a-f]{32}\z/';

    /**
     * Registers the user table and the connections runtime collection as this
     * worker's truth sources so their changes fan out to the browser.
     */
    public function onStart(): void
    {
        $this->registerDbTruthSource(PollDbContext::users);
        $this->registerRtTruthSource(PollRtContext::connections);
    }

    /**
     * Authenticates the session token cookie, finds or registers the durable
     * user, tracks the socket as a runtime connection, and replies with the
     * current-user entity fragment in the session-scope payload form.
     *
     * @param WebSocketHandshakeSignalDTO $data Accept key and the daemon-resolved session token
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws EmptyValueException When the session token is empty
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
        if ($sessionToken === '') {
            throw new EmptyValueException('session token is required');
        }

        if (preg_match(self::SESSION_TOKEN_PATTERN, $sessionToken) !== 1) {
            throw new InvalidFormatException(
                'session token must be a 32-character lowercase hex token',
            );
        }

        $user = Hilos::$db->users->findBySession($sessionToken);
        if ($user === null) {
            $user = Hilos::$db->users->actions->register($sessionToken);
        }
        $userId = (int)$user->id;

        Hilos::$rt->connections->actions->register($data->acceptKey, $userId);

        $this->sendToUser(
            PollSignalConstants::HANDSHAKE_RESPONSE,
            $data->acceptKey,
            new HandshakeResponseSignalData(
                selfId: $userId,
                selfName: $user->name,
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
}
