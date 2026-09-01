<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Hilos\Auth\Library\DTO\CompletePasswordResetActionDTO;
use Hilos\Auth\Library\DTO\ConfirmPasswordResetActionDTO;
use Hilos\Auth\Library\DTO\ConfirmRegisterActionDTO;
use Hilos\Auth\Library\DTO\RegisterActionDTO;
use Hilos\Auth\Library\DTO\RequestPasswordResetActionDTO;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Session\DTO\DismissSessionAckActionDTO;
use Hilos\Auth\Session\SessionAck;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
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
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;

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
 * That last answer has two exceptions, and both are here. The socket a login's own token
 * rotation replaces inherits what its predecessor owed (HIL-423), told so by the rotation
 * ticket it traded. And a socket with no ticket inherits what its SESSION still owes
 * (HIL-649) - which is how the tabs that login DROPPED are answered, since a rotation
 * hands the ticket to the initiator alone. Both are the same browser still inside the
 * flow; what a reload is, and what stays uncovered, is a session nothing owes any more.
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
     * A confirmed registration marks the socket that earned it and no other session's.
     *
     * The mark used to be written to every live socket of the session before the sign-in
     * went out, and to the initiator a second time with it. Since HIL-710 the two are one
     * frame, which names the socket that logged in and no other, and the session's own tabs
     * are exactly the ones the rotation is dropping (HIL-582). Where they get the sentence
     * from is a separate question with its own cases below - the row of the rotation for the
     * socket that replaces this one, the session itself for the tabs that were dropped
     * (HIL-649). What this case pins is the near edge: the initiator carries it, the
     * rotation carries it onward, and a browser of somebody else's session hears nothing.
     *
     * @throws HilosException When setup or registration handling fails
     */
    public function testConfirmingARegistrationMarksTheInitiatorAndHandsItToTheRotation(): void
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

            $rotation = $this->announcedRotation();
            $this->assertSame(
                SessionAck::REGISTERED,
                $rotation->pendingAck,
                'The sentence travels on the rotation, which is what survives the dropped sockets',
            );
            $this->assertContains(
                'ack-tab-b',
                $rotation->acceptKeysToDrop,
                'The other tab of the same browser is dropped by the rotation, to come back on the new token',
            );

            $this->assertNull($this->ackOf('ack-stranger'), 'Nobody else hears about it');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A socket opened after the fact owes nothing — the mark does not survive a reload.
     *
     * A reload is the death of the last socket that owed the sentence, and the case has to
     * be that literally since HIL-649: what a new socket inherits is what its SESSION still
     * owes, so leaving the old one alive here would be a second tab standing next to the
     * first, which is the neighbouring case below and the opposite answer. Closing it is not
     * scaffolding for the assertion - it IS the reload.
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

            // The reload: the same durable session comes back on a new socket, and the tab
            // that owed the sentence is gone. The person is still signed in, and that is the
            // point - the sentence was for the moment, the account is forever.
            $liveToken = (string)Hilos::$rt->connections['ack-reload-old']?->sessionToken;
            Hilos::$rt->connections['ack-reload-old']?->actions->unregister();
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
     * A tab the sign-in dropped is owed the sentence when it comes back (HIL-649).
     *
     * The defect this case was written for, in its registration half. Tab A types the code;
     * the rotation that signs the browser in drops tab B, which reconnects on the cookie the
     * initiator's trade installed and holding no ticket of its own - only the initiator ever
     * gets one. Until now the frame that met it carried nothing, so the surface tab B had
     * drawn was wiped by the session coming up: the flow ended in it, and never said it had
     * succeeded. What answers it now is the session, which still owes the sentence as long as
     * any live socket of it does.
     *
     * @throws HilosException When setup or registration handling fails
     */
    public function testATabDroppedByARegistrationIsOwedTheSentenceWhenItComesBack(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $token = $this->openSession($agent, 'ack-back-a');
        $this->openSession($agent, 'ack-back-b', $token);

        try {
            $this->register($agent, 'ack-back-a', $email);
            $this->seedRegisterCode($email);
            $this->confirm($agent, 'ack-back-a', $email, self::CODE);

            // What the master does with the sockets the rotation named, and what the browser
            // does next: tab B is dropped and reopens on the token the session now answers to.
            $liveToken = (string)Hilos::$rt->connections['ack-back-a']?->sessionToken;
            Hilos::$rt->connections['ack-back-b']?->actions->unregister();
            $this->drainHandshakeResponses();
            $this->openSession($agent, 'ack-back-b-again', $liveToken);

            $this->assertSame(SessionAck::REGISTERED, $this->ackOf('ack-back-b-again'));
            $this->assertNotNull(
                Hilos::$rt->connections['ack-back-b-again']?->userId,
                'It comes back into the account too, which is what made the empty screen so blank',
            );
            $this->assertSame(
                SessionAck::REGISTERED,
                $this->drainHandshakeResponses()['ack-back-b-again']?->pendingAck,
                'Stated in the frame the surface draws from, not only written on the row',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * The same for a saved password: the other tab of that browser is owed it too (HIL-649).
     *
     * The half of the defect it was first seen on. Recovery reaches the neighbouring tab by
     * two roads and they are not the same road: the converge frame moves a tab that is still
     * alive, and the mark answers the one the rotation dropped when it comes back. Only the
     * second survives the sign-in, which is why the case ends the tab rather than leaving it.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testATabDroppedByAPasswordResetIsOwedTheSentenceWhenItComesBack(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUserWithPassword($email);
        $token = $this->openSession($agent, 'ack-pw-back-a');
        $this->openSession($agent, 'ack-pw-back-b', $token);

        try {
            $this->requestReset($agent, 'ack-pw-back-a', $email);
            $this->seedResetCode($email);
            $this->confirmReset($agent, 'ack-pw-back-a', $email, self::CODE);
            $this->complete($agent, 'ack-pw-back-a', self::NEW_PASSWORD);

            $liveToken = (string)Hilos::$rt->connections['ack-pw-back-a']?->sessionToken;
            Hilos::$rt->connections['ack-pw-back-b']?->actions->unregister();
            $this->drainHandshakeResponses();
            $this->openSession($agent, 'ack-pw-back-b-again', $liveToken);

            $this->assertSame(SessionAck::PASSWORD_CHANGED, $this->ackOf('ack-pw-back-b-again'));
            $this->assertSame(
                SessionAck::PASSWORD_CHANGED,
                $this->drainHandshakeResponses()['ack-pw-back-b-again']?->pendingAck,
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A browser that lost the address is neither signed in nor marked (HIL-608).
     *
     * The mark says "you are in, and here is how you got there", so a browser that got
     * nowhere must not carry one. It used to: the converge signed every session parked on
     * the address into the new account and marked it REGISTERED, which is the capture
     * itself wearing a friendly sentence.
     *
     * @throws HilosException When setup or registration handling fails
     */
    public function testALosingBrowserIsNeitherSignedInNorMarked(): void
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

            $this->assertNull(
                Hilos::$rt->connections['ack-conv-second']?->userId,
                'A browser that lost the address is signed into nothing',
            );
            $this->assertNull($this->ackOf('ack-conv-second'), 'And is told nothing about an account it never got');
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
            // Put on the sockets the way a finished flow leaves it. The mark itself is
            // written by the sessions library and is not what this case is about - what is,
            // is that ONE press clears it everywhere.
            foreach (['ack-dismiss-a', 'ack-dismiss-b'] as $marked) {
                Hilos::$rt->connections->actions->markAck($marked, SessionAck::SIGNED_IN);
            }
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
     * Insists on there being exactly one, rather than taking whichever comes first. The
     * store is per process and shared by every case in the run, so "the first row" is
     * whatever the last case to leave one behind put there - which is how this read once
     * answered with another flow's ack and failed a case that was testing something else.
     * {@see self::bootAgent()} empties the store so the premise holds; this says so out
     * loud, and a future leak fails here with its own name instead of quietly returning
     * a stranger's row.
     *
     * @return HilosSessionRotation The pending rotation standing in the runtime store
     */
    private function announcedRotation(): HilosSessionRotation
    {
        $announced = [];
        foreach (Hilos::$rt->hilosSessionRotations as $rotation) {
            $announced[] = $rotation;
        }

        $this->assertCount(1, $announced, 'The login announces exactly one rotation');

        return $announced[0];
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
        RtTruthSourceRegistry::register(StateRegistrationWaiter::RT_COLLECTION, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(StateRecoveryWaiter::RT_COLLECTION, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        // Rotations too, and for a sharper reason than the connections: the store outlives
        // the case that filled it, nothing here trades a ticket, and the sign-in cases of
        // the other classes leave theirs behind. Cleaning up after ourselves is not enough
        // when we are not the ones who made the mess.
        foreach (Hilos::$rt->hilosSessionRotations as $rotation) {
            Hilos::$rt->hilosSessionRotations->actions->forget($rotation->ticket);
        }

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
        $this->deliverHandshake($agent, new WebSocketHandshakeSignalDTO(
            headers: [],
            acceptKey: $acceptKey,
            cookies: [],
            clientIp: '127.0.0.1',
            queryParams: RequestQueryParams::empty(),
            sessionToken: $token,
            inheritedAck: $inheritedAck,
        ));
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
        $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_REGISTER,
            new RegisterActionDTO($email, self::PASSWORD),
        );
        $this->deliverLibraryFrames($agent);
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
        $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_CONFIRM_REGISTER,
            new ConfirmRegisterActionDTO($email, $code),
        );
        $this->deliverLibraryFrames($agent);
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
        $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_REQUEST_PASSWORD_RESET,
            new RequestPasswordResetActionDTO($email),
        );
        $this->deliverLibraryFrames($agent);
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
        $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_CONFIRM_PASSWORD_RESET,
            new ConfirmPasswordResetActionDTO($email, $code),
        );
        $this->deliverLibraryFrames($agent);
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
        $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET,
            new CompletePasswordResetActionDTO($password),
        );
        $this->deliverLibraryFrames($agent);
    }

    /**
     * Dispatches the Continue button's dismiss action for one connection.
     *
     * @param ChatAgent $agent Agent told what the session became
     * @param string $acceptKey Acting connection accept key
     * @throws HilosException When the dismiss handler or the frame that follows it fails
     */
    private function dismiss(ChatAgent $agent, string $acceptKey): void
    {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        // The Continue button is the sessions library's action since HIL-710 - what it
        // clears is a session - and the chat agent learns of it on the frame that follows.
        $this->sessionsLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_DISMISS_SESSION_ACK,
            new DismissSessionAckActionDTO(),
        );
        $this->deliverLibraryFrames($agent);
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
