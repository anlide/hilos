<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Agents;

use Demo\SimpleTodo\Constants\AgentType;
use Demo\SimpleTodo\Constants\TodoSignalConstants;
use Demo\SimpleTodo\Core\Router\DTO\GuestIdentitySignalData;
use Demo\SimpleTodo\Database\TodoDbContext;
use Demo\SimpleTodo\Hilos;
use Demo\SimpleTodo\Runtime\View\Context\TodoRtContext;
use Hilos\Auth\Session\HilosSessionHost;
use Hilos\Auth\Session\SessionToken;
use Hilos\Constants\CliCommands;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Database\View\Item\Session;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;

/**
 * Monopolistic todo worker that owns the main page subscription and the
 * WebSocket lifecycle signals.
 *
 * Session identity is the framework's since HIL-407: the cookie resolves to a
 * `hilos_session` row through {@see HilosSessionHost}, and the socket is tracked
 * as a runtime connection of that session for presence. This demo DOES carry
 * anonymous sessions (HIL-610): a `user` row means an account, minted only by
 * {@see CliCommands::ADMIN_CREATE}, and a visitor without one is remembered by
 * name alone in this demo's own `guest` table.
 */
final class TodoAgent extends AbstractAgent
{
    use HilosSessionHost;

    public const string AGENT_TYPE = AgentType::TODO;

    // The operator path to the first administrator (HIL-609): this demo has no login, so
    // nothing in the product can hand out the flag the admin pages ask for. It is declared
    // here rather than on the index agent because the command ends in a session bind, and
    // sessions are this agent's - it is the class mixing in HilosSessionHost.
    public const array AGENT_COMMANDS = [
        CliCommands::ADMIN_CREATE,
    ];

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
     * Resolves the session token cookie to its session row, tracks the socket as a
     * runtime connection of that session, names the visitor behind it, and replies
     * with the current-user entity fragment in the session-scope payload form.
     *
     * The connection is registered with whatever user the session carries, `null`
     * included (HIL-610). An anonymous session is the normal state of this demo,
     * not a moment to be closed: nothing here mints a user, because the only thing
     * a `user` row means now is an account, and the only way to one is
     * {@see CliCommands::ADMIN_CREATE}.
     *
     * What an anonymous session gets instead is a name of its own, found or minted
     * in the `guest` table and sent BEFORE the handshake response, so the identity
     * line on the page is never drawn empty. A session that does carry an account
     * has its guest row dropped on the way past: that is how the row minted before
     * an operator claimed this browser goes, and doing it here rather than inside
     * the command keeps the cleanup on the path that is certain to run.
     *
     * @param WebSocketHandshakeSignalDTO $data Accept key and the daemon-resolved session token
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws InvalidFormatException When the session token is not a 32-character lowercase hex string
     * @throws DuplicateValueException When a concurrent create already claimed a new token
     * @throws HilosException On database or runtime failure while resolving the session, naming the guest or registering the connection
     */
    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void
    {
        // The daemon resolved the session token on the 101 (the client's cookie
        // or a freshly issued one) and carried it on the handshake DTO. Validate
        // inside the ValidationException family so the worker dispatcher contains
        // a bad token instead of crashing.
        $sessionToken = $data->sessionToken;
        SessionToken::ensureValid($sessionToken);

        $session = $this->resolveHandshakeSession($sessionToken);
        $userId = $session->userId;

        Hilos::$rt->connections->actions->register($data->acceptKey, $userId, $sessionToken);

        if ($userId === null) {
            $guest = Hilos::$db->guests->actions->ensureForSession($sessionToken);
            $this->sendToUser(
                TodoSignalConstants::GUEST_IDENTITY,
                $data->acceptKey,
                new GuestIdentitySignalData($guest->name),
            );
        } else {
            Hilos::$db->guests->actions->deleteForSession($sessionToken);
        }

        $this->sendToUser(
            TodoSignalConstants::HANDSHAKE_RESPONSE,
            $data->acceptKey,
            $this->handshakeResponse($session),
        );
    }

    /**
     * Builds the handshake response describing a session's current identity — the
     * {@see HilosSessionHost} hook, reading the display name from this demo's own
     * user table.
     *
     * The impersonator slots stay null: this demo has no impersonation, so the
     * only identity a session can carry is its own. A session with no user - the
     * ordinary state of a visitor since HIL-610 - yields the anonymous response,
     * which leaves the frontend without a current user; the guest name it shows
     * instead travels on its own signal and is not an identity.
     *
     * @param ?Session $session Session to describe, or null for an anonymous response
     * @return HandshakeResponseSignalData Handshake response for the session
     * @throws HilosException When the user lookup fails
     */
    protected function handshakeResponseFor(?Session $session): HandshakeResponseSignalData
    {
        $userId = $session?->userId;
        if ($userId === null) {
            return new HandshakeResponseSignalData();
        }

        $user = Hilos::$db->users[$userId] ?? null;
        if ($user === null) {
            return new HandshakeResponseSignalData();
        }

        return new HandshakeResponseSignalData(
            selfId: (int)$user->id,
            selfName: $user->name,
            selfAdmin: $user->admin,
        );
    }

    /**
     * Re-points one live connection's bound user through its runtime actions —
     * the {@see HilosSessionHost} hook. A missing connection is a no-op.
     *
     * @param string $acceptKey Connection accept key to re-point
     * @param ?int $userId User id to bind the connection to, or null for anonymous
     * @throws HilosException On runtime failure
     */
    protected function bindConnectionUser(string $acceptKey, ?int $userId): void
    {
        Hilos::$rt->connections[$acceptKey]?->actions->bindUser($userId);
    }

    /**
     * Writes one live connection's pending success ack — the {@see HilosSessionHost}
     * hook. A missing connection is a no-op, handled inside the collection action.
     *
     * Nothing in this demo finishes an auth flow, so nothing marks an ack today.
     * The hook is implemented rather than left to throw because the seam owns when
     * it is called, and a demo that answered an error there would break the
     * framework's own paths the day one is added.
     *
     * @param string $acceptKey Connection accept key to mark
     * @param ?string $ack Ack the connection owes, or null to clear it
     * @throws HilosException On runtime failure
     */
    protected function markConnectionAck(string $acceptKey, ?string $ack): void
    {
        Hilos::$rt->connections->actions->markAck($acceptKey, $ack);
    }

    /**
     * Returns the todo handshake-response signal name the {@see HilosSessionHost}
     * seam emits under; the frontend routes on this project constant.
     *
     * @return string Todo handshake-response signal name
     */
    protected function handshakeResponseSignalName(): string
    {
        return TodoSignalConstants::HANDSHAKE_RESPONSE;
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
     * Routes a CLI command sent to this agent.
     *
     * {@see CliCommands::ADMIN_CREATE} is the only one mounted here; anything else gets an
     * error reply rather than silence, because the socket parks the caller until it is
     * answered.
     *
     * @param CommandRequestDTO $data Command request payload
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused)
     * @throws InvalidArgumentException When the reply carries an empty correlation id
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        if ($data->command === CliCommands::ADMIN_CREATE) {
            $this->handleAdminCreateCommand($data);

            return;
        }

        $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));
    }

    /**
     * Makes one user an administrator, minting the row when the session carries none - this
     * demo's half of {@see HilosSessionHost::handleAdminCreateCommand()}.
     *
     * The session bind around this call is the framework's; all that happens here is the
     * user table, which is this worker's truth source.
     *
     * @param ?int $userId User the session carries, or null when it carries none
     * @return int Id of the user that is now an administrator
     * @throws ItemNotFoundForUpdateException When the id names no user row
     * @throws HilosException On database failure while minting or flagging
     */
    protected function ensureAdminUser(?int $userId): int
    {
        if ($userId === null) {
            return (int)Hilos::$db->users->actions->registerAdmin()->id;
        }

        $user = Hilos::$db->users[$userId] ?? null;
        if ($user === null) {
            throw new ItemNotFoundForUpdateException("No such user: {$userId}");
        }

        $user->actions->setAdmin(true);

        return $userId;
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
