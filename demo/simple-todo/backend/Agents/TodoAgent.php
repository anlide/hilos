<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Agents;

use Demo\SimpleTodo\Constants\AgentType;
use Demo\SimpleTodo\Constants\CookieNames;
use Demo\SimpleTodo\Constants\TodoSignalConstants;
use Demo\SimpleTodo\Hilos;
use Demo\SimpleTodo\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;

/**
 * Monopolistic todo worker that owns the main page subscription and the
 * WebSocket lifecycle signals.
 *
 * Session identity is durable: the handshake maps each session token cookie to
 * a user row, registering it on first connect and reusing it on reconnect.
 */
final class TodoAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::TODO;

    /** @var string Session token cookie format: 32 lowercase hex characters */
    private const string SESSION_TOKEN_PATTERN = '/\A[0-9a-f]{32}\z/';

    /**
     * Authenticates the session token cookie, finds or registers the durable
     * user, and replies with the current-user entity fragment in the
     * session-scope payload form.
     *
     * @param WebSocketHandshakeSignalDTO $data Accept key and cookies with a required session token cookie
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws ValidationException When the session token cookie is missing
     * @throws EmptyValueException When the session token cookie is empty
     * @throws InvalidFormatException When the session token cookie is not a 32-character lowercase hex string
     * @throws HilosException On database failure while finding or registering the user
     */
    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void
    {
        // The whole cookie validation throws inside the ValidationException
        // family: the worker handshake dispatcher contains that family, so a
        // client without a valid cookie is rejected, never a worker crash.
        if (!array_key_exists(CookieNames::SESSION_TOKEN, $data->cookies)) {
            throw new ValidationException(CookieNames::SESSION_TOKEN . ' cookie is required');
        }

        $sessionToken = $data->cookies[CookieNames::SESSION_TOKEN];
        if ($sessionToken === '') {
            throw new EmptyValueException(CookieNames::SESSION_TOKEN . ' cookie cannot be empty');
        }

        if (preg_match(self::SESSION_TOKEN_PATTERN, $sessionToken) !== 1) {
            throw new InvalidFormatException(
                CookieNames::SESSION_TOKEN . ' cookie must be a 32-character lowercase hex token',
            );
        }

        $user = Hilos::$db->users->findBySession($sessionToken);
        if ($user === null) {
            $user = Hilos::$db->users->actions->register($sessionToken);
        }

        $this->sendToUser(
            TodoSignalConstants::HANDSHAKE_RESPONSE,
            $data->acceptKey,
            new HandshakeResponseSignalData(
                selfId: (int)$user->id,
                selfName: $user->name,
            ),
        );
    }

    /**
     * No durable runtime state to clean up; WorkerManager unregisters the agent
     * itself and the user table persists across the daemon lifetime.
     */
    public function onStop(): void
    {
    }
}
