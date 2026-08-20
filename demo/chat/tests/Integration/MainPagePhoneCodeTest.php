<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Entity\Item\EventUserRegistration as EntityEventUserRegistration;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\Main\ConfirmPhoneCodeActionDTO;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\DTO\AuthConvergeSignalData;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\Verification\VerificationType;
use Hilos\HilosException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Object\Item\RegistrationReservation as ObjectRegistrationReservation;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for what a returned phone code does (HIL-486).
 *
 * The confirm is where the phone flow stopped being "find or create an account by
 * number" and became the same registration the address flows run: the code proves
 * possession, and the HOLD put on the number when that code went out is what says a
 * registration was started and is still open. So the three endings this exercises are
 * the three states a number can be in - owned, held, neither - and they are checked
 * against real reservation, identity and event rows, because the whole point of the
 * hold is that a second person cannot register the number while the first reads the
 * message, and no mock can show that.
 *
 * The code is normally minted inside the code agent and delivered by a messenger, so
 * every case seeds a challenge with a code it knows through the verifications object
 * collection - the level HIL-415 and HIL-417 test their code flows at.
 *
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class MainPagePhoneCodeTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    private const string CODE = '424242';
    private const string WRONG_CODE = '111111';
    private const int TTL_SECONDS = 900;

    /**
     * A code returned for a held number finishes the registration it was proving.
     *
     * @throws HilosException When setup or the request handling fails
     */
    public function testConfirmOnAHeldNumberRegistersTheNumberAndSignsItIn(): void
    {
        $agent = $this->bootAgent();
        $phone = $this->uniquePhone();
        $this->openSession($agent, 'register-ak');

        try {
            $this->holdNumber('register-ak', $phone);
            $this->seedKnownCode($phone);

            $outcome = $this->confirmCode($agent, 'register-ak', $phone);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::DONE, $outcome->step);
            $this->assertSame(AuthFlowIntent::REGISTER, $outcome->intent);

            $identity = Hilos::$db->identities->findByIdentity(IdentityType::SMS, $phone);
            $this->assertNotNull($identity, 'A proven number ends as a verified sms identity');
            $this->assertTrue($identity->verified);

            $userId = $identity->userId;
            $this->assertNotNull($userId);
            $this->assertSame($phone, Hilos::$db->users[$userId]?->name, 'The account is named by its number');
            $this->assertSame(
                1,
                EntityEventUserRegistration::count([EntityEventUserRegistration::target_user_id => $userId]),
                'The new member is announced once',
            );

            $this->assertNull(
                $this->holdOf('register-ak'),
                'The hold lands into the account rather than outliving it',
            );
            $this->assertSame($userId, Hilos::$rt->connections['register-ak']->userId);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A code returned for a number nobody holds is answered as an expired wait.
     *
     * The registration this code was proving is gone - swept, or finished by somebody
     * else - and the person is told that rather than accused of a typo.
     *
     * @throws HilosException When setup or the request handling fails
     */
    public function testConfirmWithoutALiveHoldAnswersReservationExpired(): void
    {
        $agent = $this->bootAgent();
        $phone = $this->uniquePhone();
        $this->openSession($agent, 'expired-ak');

        try {
            $this->seedKnownCode($phone);

            $outcome = $this->confirmCode($agent, 'expired-ak', $phone);

            $this->assertFalse($outcome->ok);
            $this->assertSame(AuthFlowOutcome::CODE_RESERVATION_EXPIRED, $outcome->code);
            $this->assertSame(AuthFlowStep::IDENTIFIER, $outcome->step);
            $this->assertNull(
                Hilos::$db->identities->findByIdentity(IdentityType::SMS, $phone),
                'A code with no hold behind it registers nobody',
            );
            $this->assertNull(Hilos::$rt->connections['expired-ak']->userId);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A number that already has an account signs that account in and registers nothing.
     *
     * @throws HilosException When setup or the request handling fails
     */
    public function testConfirmOnAnOwnedNumberSignsInWithoutRegistering(): void
    {
        $agent = $this->bootAgent();
        $phone = $this->uniquePhone();
        $userId = $this->seedPhoneAccount($phone);
        $this->openSession($agent, 'login-ak');

        try {
            $this->seedKnownCode($phone);

            $outcome = $this->confirmCode($agent, 'login-ak', $phone);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::DONE, $outcome->step);
            $this->assertSame(AuthFlowIntent::LOGIN, $outcome->intent);
            $this->assertSame($userId, Hilos::$rt->connections['login-ak']->userId);
            $this->assertSame(
                0,
                EntityEventUserRegistration::count([EntityEventUserRegistration::target_user_id => $userId]),
                'Signing in announces nobody',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A wrong code is refused as one, and leaves the hold where it was.
     *
     * @throws HilosException When setup or the request handling fails
     */
    public function testAWrongCodeLeavesTheHeldRegistrationOpen(): void
    {
        $agent = $this->bootAgent();
        $phone = $this->uniquePhone();
        $this->openSession($agent, 'wrong-ak');

        try {
            $this->holdNumber('wrong-ak', $phone);
            $this->seedKnownCode($phone);

            $refused = false;
            try {
                $this->confirmCode($agent, 'wrong-ak', $phone, self::WRONG_CODE);
            } catch (HilosException) {
                $refused = true;
            }

            $this->assertTrue($refused, 'A wrong code is a plain action failure');
            $this->assertNotNull(
                $this->holdOf('wrong-ak'),
                'A mistyped code must not free the number somebody is registering',
            );
            $this->assertNull(Hilos::$rt->connections['wrong-ak']->userId);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * The other device waiting on the number is told it is taken, not signed in.
     *
     * Two devices asked for a code on the same free number; one of them types it. The
     * other was registering the number too, and it lost - so it goes back to the
     * identifier field under the sign-in intent, where the number now has an account it
     * can prove by a code of its own. What it must NOT be is subscribed into an account
     * somebody else proved, which is the capture HIL-608 closes on the number exactly as
     * on the address.
     *
     * It is reached through the durable memory of its own request - it never handshook
     * again, so nothing parked it in the runtime list - which is what makes that list a
     * projection rather than the truth.
     *
     * @throws HilosException When setup or the request handling fails
     */
    public function testConfirmTellsTheOtherDeviceTheNumberIsTaken(): void
    {
        $agent = $this->bootAgent();
        $phone = $this->uniquePhone();
        $this->openSession($agent, 'converge-ak');
        $waitingToken = $this->openSession($agent, 'waiting-ak');
        ExecutionContext::setCurrentAcceptKey('converge-ak');

        try {
            $this->holdNumber('converge-ak', $phone);
            $this->holdNumber('waiting-ak', $phone);
            Hilos::$db->registrationWaits->actions->hold($waitingToken, $phone);
            $this->seedKnownCode($phone);
            $this->drainConvergeSignals();

            $outcome = $this->confirmCode($agent, 'converge-ak', $phone);

            $this->assertTrue($outcome->ok);
            $userId = Hilos::$db->identities->findByIdentity(IdentityType::SMS, $phone)?->userId;
            $this->assertNotNull($userId);
            $this->assertNull(
                Hilos::$rt->connections['waiting-ak']->userId,
                'The device that lost the number must not be signed into the winner account',
            );
            $this->assertNull($this->holdOf('waiting-ak'), 'The losing hold is dropped rather than left to expire');

            $converge = $this->drainConvergeSignals()['waiting-ak'] ?? null;
            $this->assertNotNull($converge, 'The loser is told out loud');
            $this->assertSame(AuthFlowStep::IDENTIFIER, $converge->step);
            $this->assertSame(AuthFlowIntent::LOGIN, $converge->intent);
            $this->assertSame(AuthFlowOutcome::CODE_IDENTIFIER_TAKEN, $converge->code);
            $this->assertNull(
                Hilos::$db->registrationWaits->findBySession($waitingToken),
                'Nothing is left to wait on once the registration happened',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Reads the registration hold the browser behind a connection is leading.
     *
     * @param string $acceptKey Accept key whose session is asked about
     * @return ?ObjectRegistrationReservation Live hold, or null when that browser holds none
     * @throws HilosException When the reservation lookup fails
     */
    private function holdOf(string $acceptKey): ?ObjectRegistrationReservation
    {
        return new RegistrationReservationService()
            ->findActiveForSession(Hilos::$rt->connections[$acceptKey]->sessionToken);
    }

    /**
     * Empties the signal queue and returns the converge signals it held, by target.
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
     * Boots a chat agent with the runtime collections these cases write.
     *
     * @return ChatAgent Agent under test
     * @throws HilosException When the runtime or router setup fails
     */
    private function bootAgent(): ChatAgent
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::userStates, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::registrationWaiters, true, self::TEST_AGENT_ID);
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
     * Opens one anonymous session with a live connection.
     *
     * @param ChatAgent $agent Agent handling the handshake
     * @param string $acceptKey Accept key of the connection
     * @return string The session cookie token registered for the connection
     * @throws HilosException When the handshake fails
     */
    private function openSession(ChatAgent $agent, string $acceptKey): string
    {
        $token = RandomHelper::hex(16);
        $agent->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: $acceptKey,
                cookies: [],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
                sessionToken: $token,
            ),
            '',
            '',
        );
        ExecutionContext::setCurrentAcceptKey($acceptKey);

        return $token;
    }

    /**
     * Submits a phone code as one connection.
     *
     * @param ChatAgent $agent Agent the page runs on
     * @param string $acceptKey Accept key of the submitting connection
     * @param string $phone Number the code was sent to
     * @param string $code Code being submitted
     * @return AuthFlowOutcome Where the surface goes next
     * @throws HilosException When the action handling fails
     */
    private function confirmCode(
        ChatAgent $agent,
        string $acceptKey,
        string $phone,
        string $code = self::CODE,
    ): AuthFlowOutcome {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        $reply = new MainPage($agent)->onAction(
            $acceptKey,
            HilosSignalConstants::HILOS_CONFIRM_PHONE_CODE,
            new ConfirmPhoneCodeActionDTO($phone, $code),
        );
        $this->assertInstanceOf(AuthFlowOutcome::class, $reply);

        return $reply;
    }

    /**
     * Holds a number the way the code agent does when it sends a code to a free one.
     *
     * @param string $acceptKey Accept key whose browser is registering the number
     * @param string $phone Number being held
     * @throws HilosException When the reservation write fails
     */
    private function holdNumber(string $acceptKey, string $phone): void
    {
        new RegistrationReservationService()
            ->hold(IdentityType::SMS, Hilos::$rt->connections[$acceptKey]->sessionToken, $phone, null);
    }

    /**
     * Seeds a live login challenge with a code these cases know.
     *
     * @param string $phone Number the code was sent to
     * @throws HilosException When the challenge insert fails
     */
    private function seedKnownCode(string $phone): void
    {
        $this->verifications()->voidActive(VerificationType::SMS_LOGIN, $phone, $this->maxAttempts());
        $this->verifications()->createChallenge(
            VerificationType::SMS_LOGIN,
            $phone,
            null,
            self::CODE,
            self::TTL_SECONDS,
        );
    }

    /**
     * Creates an account owning a verified number, as an earlier phone sign-up leaves one.
     *
     * @param string $phone Number the account owns
     * @return int Id of the created user
     * @throws HilosException When the account or identity write fails
     */
    private function seedPhoneAccount(string $phone): int
    {
        $userId = (int)Hilos::$db->users->actions->createWithName($phone)->id;
        Hilos::$db->identities->createSmsIdentity($userId, $phone);

        return $userId;
    }

    /**
     * @return ObjectUserVerifications Verifications object collection
     * @throws HilosException When the collection is unavailable
     */
    private function verifications(): ObjectUserVerifications
    {
        /** @var ObjectUserVerifications $collection */
        $collection = Hilos::$db->getObjectCollection(HilosDbContext::verifications);

        return $collection;
    }

    /**
     * @return int Attempt ceiling a challenge is read under
     * @throws HilosException When the attempt-ceiling env key is missing or not an int
     */
    private function maxAttempts(): int
    {
        return max(1, Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_MAX_ATTEMPTS));
    }

    /**
     * @return string A number no other case in this run uses
     */
    private function uniquePhone(): string
    {
        return '+1' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    }

    /**
     * Drops the connections these cases opened.
     *
     * @throws HilosException When the runtime write fails
     */
    private function cleanUp(): void
    {
        Hilos::$rt->connections->actions->clear();
    }
}
