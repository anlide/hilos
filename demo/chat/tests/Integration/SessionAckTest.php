<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\DTO\DismissSessionAckActionDTO;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\Main\CompletePasswordResetActionDTO;
use Demo\Chat\Pages\DTO\Main\ConfirmPasswordResetActionDTO;
use Demo\Chat\Pages\DTO\Main\ConfirmRegisterActionDTO;
use Demo\Chat\Pages\DTO\Main\RegisterActionDTO;
use Demo\Chat\Pages\DTO\Main\RequestPasswordResetActionDTO;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Session\SessionAck;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Database\Verification\VerificationType;
use Hilos\HilosException;
use Hilos\Runtime\View\Item\HilosSessionRotation;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for the ephemeral success ack an auth flow leaves behind (HIL-422).
 *
 * What is under test is WHERE the mark lands and how it goes away, not what it says:
 * the copy belongs to the views. Three questions, and each has a wrong answer that
 * looks reasonable. Whose sockets get it - all the live ones of the session that
 * finished the flow, and nobody else's, including the sockets of another person
 * waiting on the same address. When it goes away - when the person says so, on every
 * socket at once, so the second tab does not ask again. And what a reload gets -
 * nothing, because the mark lives on the connection, which is the whole of how it
 * stays ephemeral without anything having to expire it.
 *
 * That last answer has exactly one exception, and it is here too (HIL-423): the socket a
 * login's own token rotation replaces inherits what its predecessor owed, because it is
 * the same browser still inside the flow rather than somebody coming back later. It is
 * told so by the rotation ticket it traded, and by nothing else.
 *
 * Confirmation codes are only mailed, so the cases seed a known-code challenge the
 * same way the register and reset suites do.
 *
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class SessionAckTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    private const string PASSWORD = 'correct horse battery';
    private const string OLD_PASSWORD = 'old sun garden lamp';
    private const string NEW_PASSWORD = 'new moon garden lamp';
    private const string CODE = '424242';
    private const int TTL_SECONDS = 900;

    /**
     * A confirmed registration marks every live socket of its session, and no other.
     *
     * @throws HilosException When setup or registration handling fails
     */
    public function testConfirmingARegistrationMarksEveryLiveSocketOfItsSession(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $token = $this->openSession($agent, 'ack-tab-a');
        $this->openSession($agent, 'ack-tab-b', $token);
        $this->openSession($agent, 'ack-stranger');

        try {
            $this->register($agent, 'ack-tab-a', $email);
            $this->seedRegisterCode($email);
            $this->confirm($agent, 'ack-tab-a', $email, self::CODE);

            $this->assertSame(SessionAck::REGISTERED, $this->ackOf('ack-tab-a'));
            // The WRITE reaches the other tab of the same browser; whether that tab
            // lives to show it is the login rotation's business, not this seam's —
            // HIL-582 drops every socket but the initiator's, and they come back on
            // the new token owing nothing. What is asserted here is the reach.
            $this->assertSame(
                SessionAck::REGISTERED,
                $this->ackOf('ack-tab-b'),
                'The mark is written to every live socket of the session',
            );
            $this->assertNull($this->ackOf('ack-stranger'), 'Nobody else hears about it');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A socket opened after the fact owes nothing — the mark does not survive a reload.
     *
     * @throws HilosException When setup or registration handling fails
     */
    public function testAReopenedSocketOfTheSameSessionOwesNothing(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'ack-reload-old');

        try {
            $this->register($agent, 'ack-reload-old', $email);
            $this->seedRegisterCode($email);
            $this->confirm($agent, 'ack-reload-old', $email, self::CODE);
            $this->assertSame(SessionAck::REGISTERED, $this->ackOf('ack-reload-old'));

            // The reload: the same durable session comes back on a new socket. The
            // person is still signed in, and that is the point - the sentence was for
            // the moment, the account is forever.
            $liveToken = (string)Hilos::$rt->connections['ack-reload-old']?->sessionToken;
            $this->openSession($agent, 'ack-reload-new', $liveToken);

            $this->assertNull($this->ackOf('ack-reload-new'));
            $this->assertNotNull(Hilos::$rt->connections['ack-reload-new']?->userId);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * The socket a login's rotation sends the browser back on inherits what it owed.
     *
     * The brother of the case above, and the only exception to it (HIL-423). The mark still
     * lives on the connection — but the login ENDS the connection it was written on: the
     * token rotates, the browser reconnects, and the sentence it just earned would be gone
     * one frame before it could be read. What tells the two cases apart is the rotation
     * ticket: presenting one means this socket replaces a named predecessor, whereas the
     * reload above presents nothing and is a person coming back later.
     *
     * @throws HilosException When setup or registration handling fails
     */
    public function testTheSocketARotationSendsTheBrowserBackOnInheritsTheAck(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'ack-rotate-old');

        try {
            $this->register($agent, 'ack-rotate-old', $email);
            $this->seedRegisterCode($email);
            $this->confirm($agent, 'ack-rotate-old', $email, self::CODE);

            // The announcement carries it out of the dying connection: this row is where the
            // ack waits while the browser is between sockets.
            $rotation = $this->announcedRotation();
            $this->assertSame(SessionAck::REGISTERED, $rotation->pendingAck);
            $this->drainHandshakeResponses();

            // What the master hands the worker once the ticket is traded: the rotated token
            // and the ack the row held.
            $this->openSession($agent, 'ack-rotate-new', $rotation->sessionToken, $rotation->pendingAck);

            $this->assertSame(SessionAck::REGISTERED, $this->ackOf('ack-rotate-new'));
            $this->assertNotNull(Hilos::$rt->connections['ack-rotate-new']?->userId);
            // Stated in the response as well as written to the row: the surface draws from
            // the handshake, and a row nobody published is a mark nobody sees.
            $this->assertSame(
                SessionAck::REGISTERED,
                $this->drainHandshakeResponses()['ack-rotate-new']?->pendingAck,
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A session converged into an account by somebody else's confirmation is marked too.
     *
     * @throws HilosException When setup or registration handling fails
     */
    public function testAConvergedSessionIsMarkedAsItIsSignedIn(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'ack-conv-first');
        $this->register($agent, 'ack-conv-first', $email);
        $this->openSession($agent, 'ack-conv-second');
        $this->register($agent, 'ack-conv-second', $email);

        try {
            $this->seedRegisterCode($email);
            $this->confirm($agent, 'ack-conv-first', $email, self::CODE);

            $this->assertNotNull(
                Hilos::$rt->connections['ack-conv-second']?->userId,
                'The waiting session is signed in by the confirmation it did not type',
            );
            $this->assertSame(SessionAck::REGISTERED, $this->ackOf('ack-conv-second'));
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A saved password marks the session that saved it, and not the one still waiting.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testSavingANewPasswordMarksOnlyTheSessionThatSavedIt(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUserWithPassword($email);
        $this->openSession($agent, 'ack-reset-owner');
        $this->requestReset($agent, 'ack-reset-owner', $email);
        $this->openSession($agent, 'ack-reset-other');
        $this->requestReset($agent, 'ack-reset-other', $email);
        $this->seedResetCode($email);

        try {
            $this->confirmReset($agent, 'ack-reset-owner', $email, self::CODE);
            $this->confirmReset($agent, 'ack-reset-other', $email, self::CODE);

            ExecutionContext::setCurrentAcceptKey('ack-reset-owner');
            $this->complete($agent, 'ack-reset-owner', self::NEW_PASSWORD);

            $this->assertSame(SessionAck::PASSWORD_CHANGED, $this->ackOf('ack-reset-owner'));
            $this->assertNull(
                $this->ackOf('ack-reset-other'),
                'The device that was still waiting gets the inline line, not a panel',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Dismissing clears the mark from every live socket of the session, twice over.
     *
     * @throws HilosException When setup or the dismiss action fails
     */
    public function testDismissingClearsTheMarkFromEveryLiveSocketAndRepeatsHarmlessly(): void
    {
        $agent = $this->bootAgent();
        $token = $this->openSession($agent, 'ack-dismiss-a');
        $this->openSession($agent, 'ack-dismiss-b', $token);
        $this->openSession($agent, 'ack-dismiss-stranger');

        try {
            $agent->markSessionAck($token, SessionAck::SIGNED_IN);
            $this->assertSame(SessionAck::SIGNED_IN, $this->ackOf('ack-dismiss-a'));
            $this->assertSame(SessionAck::SIGNED_IN, $this->ackOf('ack-dismiss-b'));

            $this->dismiss($agent, 'ack-dismiss-b');

            $this->assertNull($this->ackOf('ack-dismiss-a'), 'Read once is read in every tab');
            $this->assertNull($this->ackOf('ack-dismiss-b'));

            // The second press, or the second tab pressing at the same moment.
            $this->dismiss($agent, 'ack-dismiss-a');

            $this->assertNull($this->ackOf('ack-dismiss-a'));
            $this->assertNull($this->ackOf('ack-dismiss-stranger'));
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A tab left on the token the login rotated away is told nothing, least of all that
     * it is anonymous.
     *
     * The narrow case the seam has to survive: only the connection that logged in is
     * re-pointed onto the new token (HIL-582), so a sibling tab goes on naming a token no
     * session answers to — and it is exactly that tab which is showing the panel and may
     * press Continue. Resolving its token would find no session, and the response built
     * from that would carry `currentUser: null` and sign the tab out of its own shell to
     * tell it an announcement was dismissed.
     *
     * @throws HilosException When setup or the dismiss action fails
     */
    public function testATabLeftOnTheRotatedAwayTokenIsNotSignedOutByItsOwnDismiss(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $oldToken = $this->openSession($agent, 'ack-rot-initiator');
        $this->openSession($agent, 'ack-rot-sibling', $oldToken);

        try {
            $this->register($agent, 'ack-rot-initiator', $email);
            $this->seedRegisterCode($email);
            $this->confirm($agent, 'ack-rot-initiator', $email, self::CODE);

            $this->assertNotSame(
                $oldToken,
                Hilos::$rt->connections['ack-rot-initiator']?->sessionToken,
                'The login rotates the initiator onto a fresh token',
            );
            $this->assertSame($oldToken, Hilos::$rt->connections['ack-rot-sibling']?->sessionToken);
            $this->drainHandshakeResponses();

            $this->dismiss($agent, 'ack-rot-sibling');

            $this->assertSame(
                [],
                $this->drainHandshakeResponses(),
                'A token no session answers to is told nothing at all',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Empties the signal queue and returns the handshake responses it held, by target.
     *
     * @return array<string, HandshakeResponseSignalData> Handshake payload by target accept key
     */
    private function drainHandshakeResponses(): array
    {
        $responses = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $envelope = $signal->data instanceof WebSocketSignalData ? $signal->data : null;
            $payload = $envelope?->data;
            if ($payload instanceof HandshakeResponseSignalData) {
                $responses[(string)$envelope?->targetAcceptKey] = $payload;
            }
        }

        return $responses;
    }

    /**
     * Returns the one rotation the login just announced.
     *
     * @return HilosSessionRotation The pending rotation standing in the runtime store
     */
    private function announcedRotation(): HilosSessionRotation
    {
        foreach (Hilos::$rt->hilosSessionRotations as $rotation) {
            return $rotation;
        }

        $this->fail('The login announced no rotation');
    }

    /**
     * Registers the runtime truth sources and returns a chat agent on a clean stand.
     *
     * @return ChatAgent Agent under test
     * @throws HilosException When the runtime reset fails
     */
    private function bootAgent(): ChatAgent
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::userStates, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::registrationWaiters, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::recoveryWaiters, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        ExecutionContext::setCurrentAgentId(self::TEST_AGENT_ID);

        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::initBrowser();
        Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
            'listener-ak',
            PageConstants::MAIN,
            [],
        ));

        return new ChatAgent();
    }

    /**
     * Opens a connection for an accept key and marks it current.
     *
     * A token may be passed in to open a SECOND connection of a session that is already
     * open — two tabs of one browser, which is what the mark is spread across.
     *
     * @param ChatAgent $agent Agent under test
     * @param string $acceptKey WebSocket accept key to open the connection under
     * @param ?string $sessionToken Token of a session to join, or null to open a new one
     * @param ?string $inheritedAck Ack a traded rotation ticket carried over, as the master would pass it
     * @return string The session cookie token registered for the connection
     * @throws HilosException When the handshake fails
     */
    private function openSession(
        ChatAgent $agent,
        string $acceptKey,
        ?string $sessionToken = null,
        ?string $inheritedAck = null,
    ): string {
        $token = $sessionToken ?? RandomHelper::hex(16);
        $agent->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: $acceptKey,
                cookies: [],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
                sessionToken: $token,
                inheritedAck: $inheritedAck,
            ),
            '',
            '',
        );
        ExecutionContext::setCurrentAcceptKey($acceptKey);

        return $token;
    }

    /**
     * The ack one live connection currently owes.
     *
     * @param string $acceptKey Connection accept key
     * @return ?string Ack the connection owes, or null when it owes none
     * @throws HilosException When the runtime read fails
     */
    private function ackOf(string $acceptKey): ?string
    {
        return Hilos::$rt->connections[$acceptKey]?->pendingAck;
    }

    /**
     * Dispatches a register submit through the main page for one connection.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Submitted email
     * @throws HilosException When the register handler rejects the action
     */
    private function register(ChatAgent $agent, string $acceptKey, string $email): void
    {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        new MainPage($agent)->onAction(
            $acceptKey,
            ChatSignalConstants::REGISTER,
            new RegisterActionDTO($email, self::PASSWORD),
        );
    }

    /**
     * Dispatches a registration code submission through the main page.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Submitted email
     * @param string $code Submitted confirmation code
     * @throws HilosException When the confirm handler rejects the action
     */
    private function confirm(ChatAgent $agent, string $acceptKey, string $email, string $code): void
    {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        new MainPage($agent)->onAction(
            $acceptKey,
            ChatSignalConstants::CONFIRM_REGISTER,
            new ConfirmRegisterActionDTO($email, $code),
        );
    }

    /**
     * Dispatches a reset request through the main page.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Submitted email
     * @throws HilosException When the request handler rejects the action
     */
    private function requestReset(ChatAgent $agent, string $acceptKey, string $email): void
    {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        new MainPage($agent)->onAction(
            $acceptKey,
            ChatSignalConstants::REQUEST_PASSWORD_RESET,
            new RequestPasswordResetActionDTO($email),
        );
    }

    /**
     * Dispatches a reset code submission through the main page.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Submitted email
     * @param string $code Submitted verification code
     * @throws HilosException When the confirm handler rejects the action
     */
    private function confirmReset(ChatAgent $agent, string $acceptKey, string $email, string $code): void
    {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        new MainPage($agent)->onAction(
            $acceptKey,
            ChatSignalConstants::CONFIRM_PASSWORD_RESET,
            new ConfirmPasswordResetActionDTO($email, $code),
        );
    }

    /**
     * Dispatches a password save through the main page.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $password Submitted new password
     * @throws HilosException When the complete handler rejects the action
     */
    private function complete(ChatAgent $agent, string $acceptKey, string $password): void
    {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        new MainPage($agent)->onAction(
            $acceptKey,
            ChatSignalConstants::COMPLETE_PASSWORD_RESET,
            new CompletePasswordResetActionDTO($password),
        );
    }

    /**
     * Dispatches the Continue button's dismiss action for one connection.
     *
     * @param ChatAgent $agent Agent owning the action
     * @param string $acceptKey Acting connection accept key
     * @throws HilosException When the dismiss handler fails
     */
    private function dismiss(ChatAgent $agent, string $acceptKey): void
    {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        $agent->onAgentAction(
            $acceptKey,
            ChatSignalConstants::DISMISS_SESSION_ACK,
            new DismissSessionAckActionDTO(),
        );
    }

    /**
     * Seeds a registration challenge with a code this test knows.
     *
     * @param string $email Address the registration holds
     * @throws HilosException When the challenge insert fails
     */
    private function seedRegisterCode(string $email): void
    {
        $this->seedCode(VerificationType::REGISTER_CONFIRM, $email);
    }

    /**
     * Seeds a reset challenge with a code this test knows.
     *
     * @param string $email Address being recovered
     * @throws HilosException When the challenge insert fails
     */
    private function seedResetCode(string $email): void
    {
        $this->seedCode(VerificationType::PASSWORD_RESET, $email);
    }

    /**
     * Voids the mailed challenge and seeds a known-code one in its place.
     *
     * @param string $type Verification type the flow issues under
     * @param string $email Address the challenge belongs to
     * @throws HilosException When the challenge insert fails
     */
    private function seedCode(string $type, string $email): void
    {
        // Void the mailed challenge first: leaving it behind would let a burned seeded
        // code fall back on a code this test cannot know.
        $this->verifications()->voidActive($type, $email, $this->maxAttempts());
        $this->verifications()->createChallenge($type, $email, null, self::CODE, self::TTL_SECONDS);
    }

    /**
     * Creates an account whose address has a password to reset.
     *
     * @param string $email Account email (also the identity identifier)
     * @throws HilosException When user creation or the identity insert fails
     */
    private function seedUserWithPassword(string $email): void
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int((int)Hilos::$db->users->actions->createWithName('User')->id));
        $params->add(SqlParam::string(IdentityType::PASSWORD));
        $params->add(SqlParam::string($email));
        $params->add(SqlParam::string(password_hash(self::OLD_PASSWORD, PASSWORD_DEFAULT)));
        Database::sql(
            'INSERT INTO `' . EntityIdentity::_table . '` '
            . '(`' . EntityIdentity::user_id . '`, `' . EntityIdentity::type . '`, '
            . '`' . EntityIdentity::identifier . '`, `' . EntityIdentity::secret . '`, '
            . '`' . EntityIdentity::verified . '`) VALUES (?, ?, ?, ?, 1)',
            $params,
        );
    }

    /**
     * @return ObjectUserVerifications Verification persistence primitives
     * @throws HilosException When the collection is unavailable
     */
    private function verifications(): ObjectUserVerifications
    {
        /** @var ObjectUserVerifications $collection */
        $collection = Hilos::$db->getObjectCollection(HilosDbContext::verifications);

        return $collection;
    }

    /**
     * @return int Configured maximum verify attempts per code
     */
    private function maxAttempts(): int
    {
        return max(1, Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_MAX_ATTEMPTS));
    }

    /**
     * Clears the runtime a case filled, so the next one starts on an empty stand.
     *
     * @throws HilosException When the runtime write fails
     */
    private function cleanUp(): void
    {
        $parked = [];
        foreach (Hilos::$rt->hilosRegistrationWaiters as $waiter) {
            $parked[] = $waiter->acceptKey;
        }
        foreach ($parked as $acceptKey) {
            Hilos::$rt->hilosRegistrationWaiters->actions->release($acceptKey);
        }

        $parkedRecovery = [];
        foreach (Hilos::$rt->hilosRecoveryWaiters as $waiter) {
            $parkedRecovery[] = $waiter->acceptKey;
        }
        foreach ($parkedRecovery as $acceptKey) {
            Hilos::$rt->hilosRecoveryWaiters->actions->release($acceptKey);
        }

        // Rotations outlive the connections that announced them (nothing here trades a
        // ticket), so the store is emptied by hand or the next case finds a stranger's row.
        $tickets = [];
        foreach (Hilos::$rt->hilosSessionRotations as $rotation) {
            $tickets[] = $rotation->ticket;
        }
        foreach ($tickets as $ticket) {
            Hilos::$rt->hilosSessionRotations->actions->forget($ticket);
        }

        Hilos::$rt->connections->actions->clear();
    }

    /**
     * Builds a unique lowercase email for one test.
     *
     * @return string Unique email identifier
     */
    private function uniqueEmail(): string
    {
        return RandomHelper::hex(8) . '@example.test';
    }
}
