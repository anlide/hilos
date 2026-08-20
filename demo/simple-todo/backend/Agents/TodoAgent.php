<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Agents;

use Demo\SimpleTodo\Constants\AgentType;
use Demo\SimpleTodo\Constants\TodoNotificationType;
use Demo\SimpleTodo\Constants\TodoSignalConstants;
use Demo\SimpleTodo\Database\TodoDbContext;
use Demo\SimpleTodo\Hilos;
use Demo\SimpleTodo\Runtime\View\Context\TodoRtContext;
use Hilos\Auth\Session\Exception\SessionTokenExhaustedException;
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
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Random\RandomException;

/**
 * Monopolistic todo worker that owns the main page subscription and the
 * WebSocket lifecycle signals.
 *
 * Session identity is the framework's since HIL-407: the cookie resolves to a
 * `hilos_session` row through {@see HilosSessionHost}, and the socket is tracked
 * as a runtime connection of that session for presence. This demo carries NO
 * anonymous sessions — the framework keeps them as a capability for an end
 * project, but a session that reaches this agent without a user gets one
 * registered and bound on the spot, which is the whole of that decision in code.
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
     * Resolves the session token cookie to its session row, makes sure that
     * session carries a user, tracks the socket as a runtime connection of the
     * session, and replies with the current-user entity fragment in the
     * session-scope payload form.
     *
     * A session that arrives anonymous — a new cookie, or one whose authenticated
     * session outlived its expiry and was downgraded by
     * {@see HilosSessionHost::resolveHandshakeSession()} — has a guest registered
     * for it and bound through {@see HilosSessionHost::authenticateSession()}. That
     * is the demo's one project-side step, and the reason the anonymous state never
     * reaches an RT row or the wire: it exists only between those two calls.
     *
     * The bind passes no initiating connection, so no token rotation happens. That
     * is right rather than convenient: rotation defends a LOGIN against a cookie
     * someone planted, and this demo has no login to defend.
     *
     * The connection is registered AFTER the bind, so the row is born carrying the
     * real user and presence never flickers through an anonymous frame.
     *
     * Passing no initiator also settles what this handler can raise: minting is
     * reached only through the rotation, so the token-mint failures the bind seam
     * declares ({@see RandomException}, {@see SessionTokenExhaustedException})
     * cannot arise here.
     *
     * @param WebSocketHandshakeSignalDTO $data Accept key and the daemon-resolved session token
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws InvalidFormatException When the session token is not a 32-character lowercase hex string
     * @throws DuplicateValueException When a concurrent create already claimed a new token
     * @throws HilosException On database or runtime failure while resolving the session or registering the connection
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
        if ($userId === null) {
            $user = Hilos::$db->users->actions->registerGuest();
            $userId = (int)$user->id;
            $this->notifyAdminsOfNewUser($userId, $user->name);
            $this->authenticateSession($sessionToken, $userId, null);
        }

        Hilos::$rt->connections->actions->register($data->acceptKey, $userId, $sessionToken);

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
     * only identity a session can carry is its own. A session or user that is
     * missing yields the anonymous response, which clears the frontend current
     * user. In practice the handshake never sends one — every session that
     * reaches the wire here has been bound to a user — but a hook that answered
     * anything else for a missing row would be inventing an identity.
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
