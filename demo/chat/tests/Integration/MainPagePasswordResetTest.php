<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Hilos\Auth\Library\DTO\CompletePasswordResetActionDTO;
use Hilos\Auth\Library\DTO\ConfirmPasswordResetActionDTO;
use Hilos\Auth\Library\DTO\RequestPasswordResetActionDTO;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Flow\DTO\AuthConvergeSignalData;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
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
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Random\RandomException;

/**
 * Integration tests for recovery by code (HIL-416): the code is accepted on one
 * screen and the password saved on the next, the grant between them belongs to the
 * session rather than to the address, two devices may both reach the password
 * screen and only the first save counts, and finishing takes the account back -
 * this session signed in, every other session of the user signed out.
 *
 * Codes are only mailed, never surfaced to a caller (the dev-stub deliverer merely
 * logs them), so every case seeds a known-code challenge through the verifications
 * object collection after the request - findActive() answers the newest challenge,
 * so the seeded code is the live one.
 *
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class MainPagePasswordResetTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    private const string OLD_PASSWORD = 'correct horse battery';
    private const string NEW_PASSWORD = 'a whole new passphrase';
    private const string OTHER_PASSWORD = 'the losing passphrase';
    private const string CODE = '424242';
    private const string WRONG_CODE = '000000';
    private const int TTL_SECONDS = 900;

    /**
     * An accepted code moves the surface to the password step and grants the session.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testAnAcceptedCodeOpensThePasswordStep(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUserWithPassword($email);
        $this->openSession($agent, 'accept-ak');

        try {
            $this->requestReset($agent, 'accept-ak', $email);
            $this->seedKnownCode($email);

            $outcome = $this->confirm($agent, 'accept-ak', $email, self::CODE);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::SET_PASSWORD, $outcome->step);
            $this->assertSame(AuthFlowIntent::RECOVERY, $outcome->intent);
            $this->assertTrue(
                Hilos::$rt->hilosRecoveryWaiters['accept-ak']?->codeAccepted,
                'The session that proved the code holds the grant',
            );
            $this->assertNotNull(
                $this->activeChallenge($email),
                'Accepting a code must not spend it - the password step still has to',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A wrong code is an inline error, and the person stays on the code screen.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testAWrongCodeIsRefusedWithoutMovingOrGranting(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUserWithPassword($email);
        $this->openSession($agent, 'wrong-ak');
        $this->requestReset($agent, 'wrong-ak', $email);
        $this->seedKnownCode($email);

        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('Invalid or expired code');

            $this->confirm($agent, 'wrong-ak', $email, self::WRONG_CODE);
        } finally {
            $this->assertFalse(
                Hilos::$rt->hilosRecoveryWaiters['wrong-ak']?->codeAccepted,
                'A wrong code grants nothing',
            );
            $this->cleanUp();
        }
    }

    /**
     * A code that is no longer live rolls the surface back instead of accusing a typo.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testAnExpiredChallengeRollsTheSurfaceBackToTheAddress(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUserWithPassword($email);
        $this->openSession($agent, 'expired-ak');

        try {
            $this->requestReset($agent, 'expired-ak', $email);
            $this->verifications()->voidActive(VerificationType::PASSWORD_RESET, $email, $this->maxAttempts());

            $outcome = $this->confirm($agent, 'expired-ak', $email, self::CODE);

            $this->assertFalse($outcome->ok);
            $this->assertSame(AuthFlowOutcome::CODE_RESET_CODE_EXPIRED, $outcome->code);
            $this->assertSame(AuthFlowStep::IDENTIFIER, $outcome->step);
            $this->assertSame(AuthFlowIntent::RECOVERY, $outcome->intent);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A code proven for one address grants that address and no other of the session.
     *
     * Two tabs of one browser share a session token and may be parked on two different
     * addresses. Granting by session alone would let somebody prove the code of an
     * address they own and then save a password for the one they do not - with no code
     * for it ever asked for, and the owner logged out of the account afterwards.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testAProvenCodeGrantsOnlyTheAddressItWasProvenFor(): void
    {
        $agent = $this->bootAgent();
        $mine = $this->uniqueEmail();
        $theirs = $this->uniqueEmail();
        $this->seedUserWithPassword($mine);
        $this->seedUserWithPassword($theirs);

        $token = $this->openSession($agent, 'two-tabs-mine-ak');
        $this->openSession($agent, 'two-tabs-theirs-ak', $token);

        try {
            ExecutionContext::setCurrentAcceptKey('two-tabs-theirs-ak');
            $this->requestReset($agent, 'two-tabs-theirs-ak', $theirs);
            ExecutionContext::setCurrentAcceptKey('two-tabs-mine-ak');
            $this->requestReset($agent, 'two-tabs-mine-ak', $mine);
            $this->seedKnownCode($mine);

            $this->confirm($agent, 'two-tabs-mine-ak', $mine, self::CODE);

            $this->assertTrue(Hilos::$rt->hilosRecoveryWaiters['two-tabs-mine-ak']?->codeAccepted);
            $this->assertFalse(
                Hilos::$rt->hilosRecoveryWaiters['two-tabs-theirs-ak']?->codeAccepted,
                'The address nobody proved a code for stays shut',
            );

            $outcome = $this->complete($agent, 'two-tabs-mine-ak', self::NEW_PASSWORD);

            $this->assertTrue($outcome->ok);
            $this->assertTrue(
                password_verify(self::NEW_PASSWORD, (string)$this->readSecret($mine)),
                'The save lands on the address the code was proven for',
            );
            $this->assertTrue(
                password_verify(self::OLD_PASSWORD, (string)$this->readSecret($theirs)),
                'The other address keeps its password',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A second code asked for from the same tab moves the wait and shuts the first address.
     *
     * The one branch the wait-moved frame exists for (HIL-685). The tab is parked once, so
     * the row is already there when the person changes their mind - which the library may
     * not edit, and which the holder edits on the frame instead. Two things have to be true
     * afterwards: the row names the SECOND address, and the code of the first one no longer
     * opens anything, because a wait that moved took its grant with it.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testAskingForASecondCodeOnAnotherAddressMovesTheWait(): void
    {
        $agent = $this->bootAgent();
        $first = $this->uniqueEmail();
        $second = $this->uniqueEmail();
        $this->seedUserWithPassword($first);
        $this->seedUserWithPassword($second);
        $this->openSession($agent, 'moved-ak');

        try {
            $this->requestReset($agent, 'moved-ak', $first);
            $this->assertSame($first, Hilos::$rt->hilosRecoveryWaiters['moved-ak']?->identifier);

            $this->requestReset($agent, 'moved-ak', $second);

            $this->assertSame(
                $second,
                Hilos::$rt->hilosRecoveryWaiters['moved-ak']?->identifier,
                'The wait follows the address the person is actually on',
            );
            $this->assertFalse(Hilos::$rt->hilosRecoveryWaiters['moved-ak']?->codeAccepted);

            // The code of the SECOND address is the only one that opens the step now.
            $this->seedKnownCode($second);
            $outcome = $this->confirm($agent, 'moved-ak', $second, self::CODE);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::SET_PASSWORD, $outcome->step);
            $this->assertTrue(Hilos::$rt->hilosRecoveryWaiters['moved-ak']?->codeAccepted);

            $this->complete($agent, 'moved-ak', self::NEW_PASSWORD);

            $this->assertTrue(
                password_verify(self::NEW_PASSWORD, (string)$this->readSecret($second)),
                'The save lands on the address the wait moved to',
            );
            $this->assertTrue(
                password_verify(self::OLD_PASSWORD, (string)$this->readSecret($first)),
                'The address the person left keeps its password',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A lost wait-moved frame still costs the neighbours, never the person who proved a code.
     *
     * The frame is best-effort by construction, so the holder's grant step may not lean on
     * it: it re-points the initiator's row itself before writing the grant. A row left
     * naming the address the person walked away from would otherwise be UN-granted by the
     * very submit that proved a code, and the password screen would open onto nothing.
     * The loss is staged the only honest way - by putting the row back the way a dropped
     * frame would have left it.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testAProvenCodeOpensThePasswordStepEvenIfTheWaitMovedFrameWasLost(): void
    {
        $agent = $this->bootAgent();
        $first = $this->uniqueEmail();
        $second = $this->uniqueEmail();
        $this->seedUserWithPassword($first);
        $this->seedUserWithPassword($second);
        $this->openSession($agent, 'lost-frame-ak');

        try {
            $this->requestReset($agent, 'lost-frame-ak', $first);
            $this->requestReset($agent, 'lost-frame-ak', $second);

            // The frame never arrived: the row still names the address that was left.
            ExecutionContext::setCurrentAgentId($agent->getId());
            Hilos::$rt->hilosRecoveryWaiters->actions->repoint(
                'lost-frame-ak',
                $first,
                (string)$this->sessionOf('lost-frame-ak')?->token,
            );
            ExecutionContext::setCurrentAgentId(self::TEST_AGENT_ID);

            $this->seedKnownCode($second);
            $outcome = $this->confirm($agent, 'lost-frame-ak', $second, self::CODE);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::SET_PASSWORD, $outcome->step);
            $this->assertTrue(
                Hilos::$rt->hilosRecoveryWaiters['lost-frame-ak']?->codeAccepted,
                'The grant lands on the address the code was proven for, stale row or not',
            );

            $this->complete($agent, 'lost-frame-ak', self::NEW_PASSWORD);

            $this->assertTrue(password_verify(self::NEW_PASSWORD, (string)$this->readSecret($second)));
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A recovery started from the account's other address changes its one password (HIL-692).
     *
     * The other half of the same dead end: an address a person can sign in with had to be
     * an address they can recover through, or the fix would have been half a fix. The code
     * goes to the address that was typed - it is already proven to be theirs - and lands
     * on the password of the account behind it.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testRecoveryThroughASecondAddressChangesTheAccountsPassword(): void
    {
        $agent = $this->bootAgent();
        $passwordEmail = $this->uniqueEmail();
        $secondEmail = $this->uniqueEmail();
        $userId = $this->seedUserWithPassword($passwordEmail);
        Hilos::$db->identities->createMagicLinkIdentity($userId, $secondEmail)->markVerified();

        $this->openSession($agent, 'second-address-reset-ak');

        try {
            ExecutionContext::setCurrentAcceptKey('second-address-reset-ak');
            $this->requestReset($agent, 'second-address-reset-ak', $secondEmail);
            $this->seedKnownCode($secondEmail);

            $this->confirm($agent, 'second-address-reset-ak', $secondEmail, self::CODE);
            $outcome = $this->complete($agent, 'second-address-reset-ak', self::NEW_PASSWORD);

            $this->assertTrue($outcome->ok);
            $this->assertTrue(
                password_verify(self::NEW_PASSWORD, (string)$this->readSecret($passwordEmail)),
                'The save lands on the account\'s one password, wherever it is written',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Both devices reach the password screen; the second one to save is told why not.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testTheSecondDeviceToSaveIsToldThePasswordAlreadyChanged(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUserWithPassword($email);

        $this->openSession($agent, 'race-first-ak');
        $this->requestReset($agent, 'race-first-ak', $email);
        $this->openSession($agent, 'race-second-ak');
        $this->requestReset($agent, 'race-second-ak', $email);
        $this->seedKnownCode($email);

        try {
            ExecutionContext::setCurrentAcceptKey('race-first-ak');
            $this->confirm($agent, 'race-first-ak', $email, self::CODE);
            ExecutionContext::setCurrentAcceptKey('race-second-ak');
            $this->confirm($agent, 'race-second-ak', $email, self::CODE);

            ExecutionContext::setCurrentAcceptKey('race-first-ak');
            $this->drainConvergeSignals();
            $won = $this->complete($agent, 'race-first-ak', self::NEW_PASSWORD);
            $this->assertTrue($won->ok);

            $converged = $this->drainConvergeSignals();
            $this->assertArrayHasKey('race-second-ak', $converged, 'The device that lost is told, not left waiting');
            $this->assertSame(AuthFlowOutcome::CODE_PASSWORD_ALREADY_CHANGED, $converged['race-second-ak']->code);
            $this->assertSame(AuthFlowStep::IDENTIFIER, $converged['race-second-ak']->step);
            $this->assertSame(AuthFlowIntent::LOGIN, $converged['race-second-ak']->intent);
            $this->assertNull(Hilos::$rt->hilosRecoveryWaiters['race-second-ak'], 'Its grant goes with the news');

            // And if its save was already in flight when the news went out, it still cannot
            // land: the grant is gone with the code, so the surface is sent back to the
            // address field instead of writing the password nobody proved a code for.
            ExecutionContext::setCurrentAcceptKey('race-second-ak');
            $lost = $this->complete($agent, 'race-second-ak', self::OTHER_PASSWORD);

            $this->assertFalse($lost->ok);
            $this->assertSame(AuthFlowStep::IDENTIFIER, $lost->step);
            $this->assertTrue(
                password_verify(self::NEW_PASSWORD, (string)$this->readSecret($email)),
                'The account keeps the password of whoever saved first',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Saving signs this session in and logs every other session of the user out.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testCompletingSignsThisSessionInAndDropsTheOthers(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $userId = $this->seedUserWithPassword($email);

        $strangerToken = $this->openSession($agent, 'stranger-ak');
        $this->authenticateSession($agent, $strangerToken, $userId, null);
        $this->assertSame($userId, Hilos::$rt->connections['stranger-ak']?->userId);

        $this->openSession($agent, 'owner-ak');

        try {
            $this->requestReset($agent, 'owner-ak', $email);
            $this->seedKnownCode($email);
            $this->confirm($agent, 'owner-ak', $email, self::CODE);

            $outcome = $this->complete($agent, 'owner-ak', self::NEW_PASSWORD);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::DONE, $outcome->step);
            $this->assertSame(AuthFlowIntent::RECOVERY, $outcome->intent);
            $this->assertTrue(password_verify(self::NEW_PASSWORD, (string)$this->readSecret($email)));

            $this->assertSame($userId, Hilos::$rt->connections['owner-ak']?->userId);
            $this->assertSame(
                $userId,
                $this->sessionOf('owner-ak')?->userId,
                'The session that reset the password stays signed in',
            );
            $this->assertNull(
                Hilos::$db->sessions->findByToken($strangerToken)?->userId,
                'Every other session of the account is reverted to anonymous',
            );
            $this->assertNull(Hilos::$rt->connections['stranger-ak']?->userId);
            $this->assertNull(Hilos::$rt->hilosRecoveryWaiters['owner-ak'], 'A finished recovery releases its waiters');
            $this->assertNull($this->activeChallenge($email), 'The code is spent by the save');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A tab opened after the code was accepted starts on the password step (HIL-648).
     *
     * The defect this list was filed for. The grant belongs to the session, so a
     * connection that submitted nothing inherits the step its siblings reached - and
     * gets its own parked row, without which it would stand on the right screen and
     * never hear that the password was changed from another device.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testAFreshConnectAfterTheAcceptedCodeOpensOnThePasswordStep(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUserWithPassword($email);
        $token = $this->openSession($agent, 'accept-ak-2');

        try {
            $this->requestReset($agent, 'accept-ak-2', $email);
            $this->seedKnownCode($email);
            $this->confirm($agent, 'accept-ak-2', $email, self::CODE);

            $this->drainHandshakeResponses();
            $this->openSession($agent, 'resume-granted-ak', $token);
            $step = $this->drainHandshakeResponses()['resume-granted-ak']?->pendingAuthStep;

            $this->assertNotNull($step, 'A session standing on a live recovery is told so');
            $this->assertSame(AuthFlowIntent::RECOVERY, $step[HandshakeResponseSignalData::intent]);
            $this->assertSame(AuthFlowStep::SET_PASSWORD, $step[HandshakeResponseSignalData::step]);
            $this->assertSame($email, $step[HandshakeResponseSignalData::identifier]);
            $this->assertTrue(
                Hilos::$rt->hilosRecoveryWaiters['resume-granted-ak']?->codeAccepted,
                'The fresh tab is parked with the grant its session already holds',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A tab opened before the code was accepted starts on the code step (HIL-648).
     *
     * The other half of the same projection: the step answered is the one the session
     * stands on, not the furthest one the flow has.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testAFreshConnectBeforeTheCodeIsAcceptedOpensOnTheCodeStep(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUserWithPassword($email);
        $token = $this->openSession($agent, 'pending-ak');

        try {
            $this->requestReset($agent, 'pending-ak', $email);
            $this->seedKnownCode($email);

            $this->drainHandshakeResponses();
            $this->openSession($agent, 'resume-pending-ak', $token);
            $step = $this->drainHandshakeResponses()['resume-pending-ak']?->pendingAuthStep;

            $this->assertNotNull($step, 'A session waiting on a code is told so');
            $this->assertSame(AuthFlowIntent::RECOVERY, $step[HandshakeResponseSignalData::intent]);
            $this->assertSame(AuthFlowStep::CODE, $step[HandshakeResponseSignalData::step]);
            $this->assertFalse(
                Hilos::$rt->hilosRecoveryWaiters['resume-pending-ak']?->codeAccepted,
                'A tab that inherits the code step inherits no grant',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A dead code leaves the fresh tab on the identifier field (HIL-648).
     *
     * The grant is worth exactly what the code behind it is worth: answering the
     * password step here would draw a screen that refuses on submit, so the honest
     * answer is no step at all.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testAFreshConnectIsToldNoStepOnceTheCodeIsGone(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUserWithPassword($email);
        $token = $this->openSession($agent, 'dead-code-ak');

        try {
            $this->requestReset($agent, 'dead-code-ak', $email);
            $this->seedKnownCode($email);
            $this->confirm($agent, 'dead-code-ak', $email, self::CODE);
            $this->verifications()->voidActive(VerificationType::PASSWORD_RESET, $email, $this->maxAttempts());

            $this->drainHandshakeResponses();
            $this->openSession($agent, 'resume-dead-ak', $token);

            $this->assertNull(
                $this->drainHandshakeResponses()['resume-dead-ak']?->pendingAuthStep,
                'A recovery whose code died puts nobody on a screen that would refuse them',
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
     * Empties the signal queue and returns the converge signals it held, by target.
     *
     * The push half of recovery leaves no trace in the database or the runtime - being
     * told where your step goes IS the whole effect - so the queue is the only place the
     * news can be read. Called once before the act to clear what the setup queued, and
     * again after it to see what the act sent.
     *
     * @return array<string, AuthConvergeSignalData> Converge payload by target accept key
     */
    private function drainConvergeSignals(): array
    {
        $converged = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $payload = $signal->data instanceof WebSocketSignalData ? $signal->data->data : null;
            if ($payload instanceof AuthConvergeSignalData) {
                $converged[$payload->acceptKey] = $payload;
            }
        }

        return $converged;
    }

    /**
     * Registers the truth sources and signal router the recovery path needs.
     *
     * @return ChatAgent Agent under test
     * @throws HilosException When runtime setup fails
     */
    private function bootAgent(): ChatAgent
    {
        $agent = new ChatAgent();

        // Claimed under the REAL agent ids as well, and that is not decoration (HIL-685):
        // this flow stands on the split between the holder's full right over the parked
        // surfaces and the library's [add, remove], and a run where one all-powerful id
        // writes everything cannot meet a single refusal production meets. The dispatch
        // helpers below put the current id where the worker would put it.
        foreach ([
            ChatRtContext::connections,
            ChatRtContext::userStates,
            StateRecoveryWaiter::RT_COLLECTION,
            // Claimed in a node by the sessions library's own onStart() (HIL-710); a login
            // rotates the session token and writes here on the way out.
            StateHilosSessionRotation::RT_COLLECTION,
        ] as $collection) {
            RtTruthSourceRegistry::register($collection, true, self::TEST_AGENT_ID);
            RtTruthSourceRegistry::register($collection, true, $agent->getId());
        }

        // Built here rather than on first dispatch: onStart() is what lays down the
        // library's own narrower claim, and a claim that arrives after the first write
        // proves nothing.
        $this->usersLibrary();
        Hilos::$rt->connections->actions->clear();

        ExecutionContext::setCurrentAgentId(self::TEST_AGENT_ID);

        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::initBrowser();
        Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
            'listener-ak',
            PageConstants::MAIN,
            [],
        ));

        return $agent;
    }

    /**
     * Runs one sign-in action the way two workers run it: the library, then the holder.
     *
     * The current agent id is what the truth-source registry judges a write by, and in a
     * node it is set per callback by the worker ({@see WorkerManager}). Setting it here
     * too is the whole reason this file can see a refusal at all - under one shared id the
     * library was silently allowed to edit the holder's rows, which is how a dead password
     * recovery passed this suite for a week (HIL-685).
     *
     * @param ChatAgent $agent Agent holding this project's sessions
     * @param string $acceptKey Accept key the action arrives on
     * @param string $action Action name from the library's own list
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?AuthFlowOutcome Where the surface goes next, from whichever of the two answered
     * @throws AgentUnknownActionException When the library does not own the action
     * @throws AgentUnknownSignalException When the holder is handed a frame it does not know
     * @throws InvalidActionPayloadException When the payload does not match the action name
     * @throws InvalidArgumentException When a queued signal cannot be named
     * @throws InvalidFormatException When a frame's outcome cannot be read back
     * @throws ValidationException When the command refuses what was submitted
     * @throws RandomException When a code or a rotated token cannot draw from the CSPRNG
     * @throws HilosException When a command or a frame exposes database or runtime failure
     */
    private function runLibraryAction(
        ChatAgent $agent,
        string $acceptKey,
        string $action,
        ActionPayloadDTO $dto,
    ): ?AuthFlowOutcome {
        ExecutionContext::setCurrentAgentId($this->usersLibrary()->getId());
        try {
            $reply = $this->usersLibrary()->onAgentAction($acceptKey, $action, $dto);
        } finally {
            ExecutionContext::setCurrentAgentId($agent->getId());
        }

        try {
            $handedOver = $this->deliverLibraryFrames($agent);
        } finally {
            ExecutionContext::setCurrentAgentId(self::TEST_AGENT_ID);
        }

        return $reply instanceof AuthFlowOutcome ? $reply : $handedOver;
    }

    /**
     * Opens an anonymous session and connection for an accept key and marks it current.
     *
     * A token may be passed in to open a SECOND connection of a session that is already
     * open - two tabs of one browser, which is what session-binding is about.
     *
     * @param ChatAgent $agent Agent under test
     * @param string $acceptKey WebSocket accept key to open the session under
     * @param ?string $sessionToken Token of a session to join, or null to open a new one
     * @return string The session cookie token registered for the connection
     * @throws HilosException When the handshake fails
     */
    private function openSession(ChatAgent $agent, string $acceptKey, ?string $sessionToken = null): string
    {
        $token = $sessionToken ?? RandomHelper::hex(16);
        // Under the holder's own id: a handshake is the holder's callback, and what it
        // writes - the connection row, a wait restored from the session - is the holder's.
        ExecutionContext::setCurrentAgentId($agent->getId());
        try {
            $this->deliverHandshake($agent, new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: $acceptKey,
                cookies: [],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
                sessionToken: $token,
            ));
        } finally {
            ExecutionContext::setCurrentAgentId(self::TEST_AGENT_ID);
        }
        ExecutionContext::setCurrentAcceptKey($acceptKey);

        return $token;
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
        $this->runLibraryAction(
            $agent,
            $acceptKey,
            HilosSignalConstants::HILOS_REQUEST_PASSWORD_RESET,
            new RequestPasswordResetActionDTO($email),
        );
    }

    /**
     * Dispatches a code submission through the main page.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Submitted email
     * @param string $code Submitted verification code
     * @return AuthFlowOutcome Where the surface goes next
     * @throws HilosException When the confirm handler rejects the action
     */
    private function confirm(ChatAgent $agent, string $acceptKey, string $email, string $code): AuthFlowOutcome
    {
        $outcome = $this->runLibraryAction(
            $agent,
            $acceptKey,
            HilosSignalConstants::HILOS_CONFIRM_PASSWORD_RESET,
            new ConfirmPasswordResetActionDTO($email, $code),
        );
        $this->assertInstanceOf(AuthFlowOutcome::class, $outcome);

        return $outcome;
    }

    /**
     * Dispatches a password save through the main page.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $password Submitted new password
     * @return AuthFlowOutcome Where the surface goes next
     * @throws HilosException When the complete handler rejects the action
     */
    private function complete(ChatAgent $agent, string $acceptKey, string $password): AuthFlowOutcome
    {
        $outcome = $this->runLibraryAction(
            $agent,
            $acceptKey,
            HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET,
            new CompletePasswordResetActionDTO($password),
        );
        $this->assertInstanceOf(AuthFlowOutcome::class, $outcome);

        return $outcome;
    }

    /**
     * Seeds a reset challenge with a code this test knows, newer than the mailed one.
     *
     * @param string $email Address being recovered
     * @throws HilosException When the challenge insert fails
     */
    private function seedKnownCode(string $email): void
    {
        // Void the mailed challenge first: leaving it behind would let a burned seeded
        // code fall back on a code this test cannot know.
        $this->verifications()->voidActive(VerificationType::PASSWORD_RESET, $email, $this->maxAttempts());
        $this->verifications()->createChallenge(
            VerificationType::PASSWORD_RESET,
            $email,
            null,
            self::CODE,
            self::TTL_SECONDS,
        );
    }

    /**
     * Creates an account whose address has a password to reset.
     *
     * @param string $email Account email (also the identity identifier)
     * @return int New user id
     * @throws HilosException When user creation or the identity insert fails
     */
    private function seedUserWithPassword(string $email): int
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('User')->id;

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($userId));
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

        return $userId;
    }

    /**
     * Reads the stored password hash for a `password` identity by email.
     *
     * @param string $email Identity identifier
     * @return ?string Stored secret hash or null when absent
     * @throws HilosException When the lookup query fails
     */
    private function readSecret(string $email): ?string
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string(IdentityType::PASSWORD));
        $params->add(SqlParam::string($email));
        $resultSet = Database::sql(
            'SELECT `' . EntityIdentity::secret . '` FROM `' . EntityIdentity::_table . '` '
            . 'WHERE `' . EntityIdentity::type . '` = ? AND `' . EntityIdentity::identifier . '` = ?',
            $params,
        )->first();
        $row = $resultSet?->first();
        $secret = is_array($row) ? ($row[EntityIdentity::secret] ?? null) : null;

        return is_string($secret) ? $secret : null;
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
     * Resolves the live reset challenge for an address.
     *
     * @param string $email Address being recovered
     * @return ?object Active challenge, or null when none is live
     * @throws HilosException When the lookup fails
     */
    private function activeChallenge(string $email): ?object
    {
        return $this->verifications()->findActive(VerificationType::PASSWORD_RESET, $email, $this->maxAttempts());
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
        foreach (Hilos::$rt->hilosRecoveryWaiters as $waiter) {
            $parked[] = $waiter->acceptKey;
        }
        foreach ($parked as $acceptKey) {
            Hilos::$rt->hilosRecoveryWaiters->actions->release($acceptKey);
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
