<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Agents;

use Demo\SimpleTodo\Constants\AgentType;
use Demo\SimpleTodo\Constants\CookieNames;
use Demo\SimpleTodo\Constants\TodoSignalConstants;
use Demo\SimpleTodo\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Monopolistic todo worker that owns the main page subscription and the
 * WebSocket lifecycle signals.
 *
 * Declares no truth sources yet: the todos table and its DB ownership arrive
 * with the first data-on-screen rewrite step. Session identity is agent-local
 * and lives only for the daemon lifetime; the demo has no durable user table.
 */
final class TodoAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::TODO;

    /** @var string Session token cookie format: 32 lowercase hex characters */
    private const string SESSION_TOKEN_PATTERN = '/\A[0-9a-f]{32}\z/';

    /** @var array<string, HandshakeResponseSignalData> Agent-local session token to current-user reply */
    private array $sessionUsers = [];

    /** @var int Last assigned agent-local user id (monotonic for the daemon lifetime) */
    private int $nextUserId = 0;

    /**
     * Authenticates the session token cookie and replies with the current-user
     * entity fragment in the session-scope payload form.
     *
     * Identity is agent-local: the monopolistic todo worker maps each session
     * token to a generated user for its lifetime, so a reconnecting tab keeps
     * the same id and name. No durable user table backs the demo.
     *
     * @param WebSocketHandshakeSignalDTO $data Accept key and cookies with a required session token cookie
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws ValidationException When the session token cookie is missing
     * @throws EmptyValueException When the session token cookie is empty
     * @throws InvalidFormatException When the session token cookie is not a 32-character lowercase hex string
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

        $response = $this->sessionUsers[$sessionToken] ??= new HandshakeResponseSignalData(
            selfId: ++$this->nextUserId,
            selfName: 'User' . RandomHelper::integer(1000, 9999),
        );

        $this->sendToUser(TodoSignalConstants::HANDSHAKE_RESPONSE, $data->acceptKey, $response);
    }

    /**
     * No durable state to clean up; WorkerManager unregisters the agent itself.
     */
    public function onStop(): void
    {
    }
}
