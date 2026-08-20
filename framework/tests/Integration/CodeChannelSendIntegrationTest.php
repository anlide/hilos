<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Code\AuthCodeAgent;
use Hilos\Auth\Code\DTO\AuthCodeResultSignalData;
use Hilos\Auth\Code\DTO\AuthCodeSendSignalData;
use Hilos\Auth\CodeChannel\CodeChannel;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Random\RandomException;

/**
 * What a code request costs the person asking, against a real challenge table (HIL-492).
 *
 * The order the code agent works in - probe, then mint, then send - is the design of
 * the feature, and only a table can show that it holds. The claim under test is
 * negative and therefore untestable by mock: a channel that cannot reach a number
 * must leave NOTHING behind, so the next channel the person picks still gives them
 * their first code. A mock would happily report "issue was not called" while a stray
 * row sat in the table spending the cooldown.
 *
 * The other half is the send gate's key. It counts (type, identifier) and
 * deliberately not the channel, so walking the registry cannot buy one code per
 * channel out of one number's budget - which is exactly what a per-channel key would
 * have allowed, and exactly what a table shows and a mock cannot.
 *
 * The agent is driven directly rather than through a daemon: what is being checked is
 * its tick loop against the database, and the process it would run in adds nothing to
 * that.
 */
final class CodeChannelSendIntegrationTest extends FrameworkIntegrationTestCase
{
    /** @var list<string> Framework tables this case needs */
    private const array TABLES = [
        'hilos_user_verification',
        'hilos_identity',
        'hilos_registration_reservation',
        'hilos_session',
    ];

    private const string ACCEPT_KEY = 'code-channel-test-accept-key';

    /** Session token of the browser every case asks from, valid hex so a real row can carry it. */
    private const string SESSION_TOKEN = 'c0de00000000000000000000000000a1';

    /** Owner of the identity the "number already has an account" case plants. */
    private const int EXISTING_USER_ID = 4242;

    private const int TTL_SECONDS = 900;

    /** Codes one number may be sent per window here, small enough to reach in two sends. */
    private const int SEND_CAP = 2;

    /** @var list<string> Environment names this case sets and has to unset again */
    private const array VERIFICATION_KNOBS = [
        'HILOS_VERIFICATION_TTL_SEC',
        'HILOS_VERIFICATION_RESEND_COOLDOWN_SEC',
        'HILOS_VERIFICATION_SEND_WINDOW_SEC',
        'HILOS_VERIFICATION_SEND_CAP_SMS',
    ];

    private ?DbContext $previousDb = null;

    private ?SignalRouter $previousSignalRouter = null;

    /**
     * @throws HilosException When a stub statement fails or the context cannot be configured
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);

        $this->previousDb = Hilos::$db;
        $this->previousSignalRouter = Hilos::$sr;

        $db = new CodeChannelTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$sr = new SignalRouter();

        putenv(EnvConstants::HILOS_VERIFICATION_TTL_SEC->name . '=' . self::TTL_SECONDS);
        // No cooldown: this case is about the probe order and the cap key, and a live
        // cooldown would refuse the second send before the cap ever answered.
        putenv(EnvConstants::HILOS_VERIFICATION_RESEND_COOLDOWN_SEC->name . '=0');
        putenv(EnvConstants::HILOS_VERIFICATION_SEND_WINDOW_SEC->name . '=3600');
        putenv(EnvConstants::HILOS_VERIFICATION_SEND_CAP_SMS->name . '=' . self::SEND_CAP);
    }

    /**
     * @throws HilosException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        foreach (self::VERIFICATION_KNOBS as $knob) {
            putenv($knob);
        }

        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$db = $this->previousDb;

        self::runStubs(down: true);

        parent::tearDown();
    }

    /**
     * A channel that cannot reach the number mints nothing and spends nothing.
     *
     * @throws HilosException When a verification query fails
     */
    public function testAnUnreachableChannelLeavesNoChallengeAndNoSpentCooldown(): void
    {
        $phone = $this->uniquePhone();
        $agent = new CodeChannelTestAgent(new CodeChannelTestChannel('unreachable', reachable: false));

        $this->request($agent, $phone, 'unreachable');

        self::assertSame(
            AuthCodeResultSignalData::REASON_CHANNEL_UNAVAILABLE,
            $this->takeResultReason(),
        );
        self::assertNull(
            new VerificationService()->activeChannel(VerificationType::SMS_LOGIN, $phone),
            'A refused probe must leave no challenge behind',
        );
        self::assertSame(
            0,
            new VerificationService()->resendAllowedInSeconds(VerificationType::SMS_LOGIN, $phone),
            'A refused probe must not spend the cooldown the next channel needs',
        );
    }

    /**
     * A reachable channel mints, delivers, and records which channel carried the code.
     *
     * @throws HilosException When a verification query fails
     */
    public function testAReachableChannelSendsAndRecordsTheChannelOnTheChallenge(): void
    {
        $phone = $this->uniquePhone();
        $channel = new CodeChannelTestChannel('carrier', reachable: true);
        $agent = new CodeChannelTestAgent($channel);

        $this->request($agent, $phone, 'carrier');

        self::assertSame(AuthCodeResultSignalData::REASON_CODE_SENT, $this->takeResultReason());
        self::assertSame([$phone], $channel->handedOff, 'The channel must be handed the code it reported sending');
        self::assertSame(
            'carrier',
            new VerificationService()->activeChannel(VerificationType::SMS_LOGIN, $phone),
            'A resend has to repeat the channel the person chose, so the mint records it',
        );
    }

    /**
     * The cap counts the number, not the channel, so a second channel cannot buy a third code.
     *
     * @throws HilosException When a verification query fails
     */
    public function testTheSendCapCountsTheNumberAcrossChannels(): void
    {
        $phone = $this->uniquePhone();
        $first = new CodeChannelTestChannel('first', reachable: true);
        $second = new CodeChannelTestChannel('second', reachable: true);

        $this->request(new CodeChannelTestAgent($first), $phone, 'first');
        self::assertSame(AuthCodeResultSignalData::REASON_CODE_SENT, $this->takeResultReason());

        $this->request(new CodeChannelTestAgent($second), $phone, 'second');
        self::assertSame(AuthCodeResultSignalData::REASON_CODE_SENT, $this->takeResultReason());

        // Third send, on a channel that has sent nothing yet: refused all the same,
        // because the budget belongs to the number.
        $third = new CodeChannelTestChannel('third', reachable: true);
        $this->request(new CodeChannelTestAgent($third), $phone, 'third');

        self::assertSame(AuthCodeResultSignalData::REASON_CAP_REACHED, $this->takeResultReason());
        self::assertSame([], $third->handedOff, 'A capped request must not reach the transport');
    }

    /**
     * A send held back by the cooldown says so, and mints nothing extra.
     *
     * @throws HilosException When a verification query fails
     */
    public function testASecondSendInsideTheCooldownIsHeldRatherThanMinted(): void
    {
        putenv(EnvConstants::HILOS_VERIFICATION_RESEND_COOLDOWN_SEC->name . '=600');

        $phone = $this->uniquePhone();
        $channel = new CodeChannelTestChannel('held', reachable: true);

        $this->request(new CodeChannelTestAgent($channel), $phone, 'held');
        self::assertSame(AuthCodeResultSignalData::REASON_CODE_SENT, $this->takeResultReason());

        $this->request(new CodeChannelTestAgent($channel), $phone, 'held');

        self::assertSame(AuthCodeResultSignalData::REASON_RATE_LIMITED, $this->takeResultReason());
        self::assertCount(1, $channel->handedOff, 'A held send must not reach the transport a second time');
    }

    /**
     * A code to a free number holds it and leaves the asking session waiting on it.
     *
     * @throws HilosException When a reservation, wait or verification query fails
     */
    public function testAFreeNumberIsHeldAndTheAskingSessionIsRemembered(): void
    {
        $phone = $this->uniquePhone();

        $this->request(new CodeChannelTestAgent(new CodeChannelTestChannel('free', reachable: true)), $phone, 'free');

        self::assertSame(AuthCodeResultSignalData::REASON_CODE_SENT, $this->takeResultReason());
        self::assertNotNull(
            new RegistrationReservationService()->findActiveForSession(self::SESSION_TOKEN),
            'A number nobody owns must be held while its code travels',
        );
        self::assertSame($phone, $this->waitOf(self::SESSION_TOKEN), 'The session must be left waiting on the number');
    }

    /**
     * A number that already has an account is a sign-in: nothing is held, nothing is waited on.
     *
     * @throws HilosException When an identity, reservation or wait query fails
     */
    public function testANumberWithAnAccountIsNeitherHeldNorRemembered(): void
    {
        $phone = $this->uniquePhone();
        Hilos::$db?->identities->createSmsIdentity(self::EXISTING_USER_ID, $phone);

        $this->request(new CodeChannelTestAgent(new CodeChannelTestChannel('known', reachable: true)), $phone, 'known');

        self::assertSame(AuthCodeResultSignalData::REASON_CODE_SENT, $this->takeResultReason());
        self::assertNull(
            new RegistrationReservationService()->findActiveForSession(self::SESSION_TOKEN),
            'There is nothing left to reserve about a number somebody already owns',
        );
        self::assertNull($this->waitOf(self::SESSION_TOKEN), 'A sign-in leaves no unfinished registration behind');
    }

    /**
     * A second session racing a live code is remembered too, though no message went out.
     *
     * The cooldown arm is what a racing session gets, and it is the arm that puts it on
     * the code screen of the number it is racing for - so the memory has to be written
     * there as well, or a reload would strand it on an empty form (HIL-486, Flow p.15).
     * Its hold is its OWN since HIL-608; what it shares with the first session is the
     * code, because the send gate belongs to the number.
     *
     * @throws HilosException When a reservation, wait or verification query fails
     */
    public function testASessionJoiningAHeldSendIsRememberedToo(): void
    {
        putenv(EnvConstants::HILOS_VERIFICATION_RESEND_COOLDOWN_SEC->name . '=600');

        $phone = $this->uniquePhone();
        $channel = new CodeChannelTestChannel('joined', reachable: true);
        $second = 'c0de00000000000000000000000000b2';

        $this->request(new CodeChannelTestAgent($channel), $phone, 'joined');
        self::assertSame(AuthCodeResultSignalData::REASON_CODE_SENT, $this->takeResultReason());

        $this->request(new CodeChannelTestAgent($channel), $phone, 'joined', $second);

        self::assertSame(AuthCodeResultSignalData::REASON_RATE_LIMITED, $this->takeResultReason());
        self::assertSame($phone, $this->waitOf($second), 'The joining session waits on the same number');
        self::assertSame($phone, $this->waitOf(self::SESSION_TOKEN), 'The first session keeps waiting on it');
    }

    /**
     * Reads what a session is waiting on, straight from the durable memory.
     *
     * @param string $sessionToken Session token to ask about
     * @return ?string Identifier the session is waiting on, or null when it waits on nothing
     * @throws HilosException When the session lookup fails
     */
    private function waitOf(string $sessionToken): ?string
    {
        return Hilos::$db?->sessions->findByToken($sessionToken)?->pendingRegistrationIdentifier;
    }

    /**
     * Hands one request to an agent and pumps it until it settles.
     *
     * @param CodeChannelTestAgent $agent Agent under test, carrying its one channel
     * @param string $phone Number the code is asked for
     * @param string $channel Channel name the request names
     * @param string $sessionToken Session token the request speaks for
     * @throws HilosException When the session seed, the agent's intake or its tick raises
     */
    private function request(
        CodeChannelTestAgent $agent,
        string $phone,
        string $channel,
        string $sessionToken = self::SESSION_TOKEN,
    ): void {
        // The wait is a column on the session row since HIL-612, so the browser this
        // request speaks for has to exist before the agent can remember anything about it -
        // exactly as it does in production, where a socket only ever arrives with a
        // session the master already resolved.
        if (Hilos::$db?->sessions->findByToken($sessionToken) === null) {
            Hilos::$db?->sessions->actions->createAnonymous($sessionToken);
        }

        $agent->onSignalAgent(
            new AgentSignalData(new AuthCodeSendSignalData(
                self::ACCEPT_KEY,
                $sessionToken,
                $phone,
                $channel,
                VerificationType::SMS_LOGIN,
            )),
            '',
            HilosSignalConstants::HILOS_AUTH_CODE_SEND,
        );

        // Every channel in this case answers reachability without the network, so a
        // single tick carries the operation through probe, mint, send and outcome.
        $agent->onTick();
    }

    /**
     * Takes the reason off the next queued outcome signal.
     *
     * @return ?string Reason the agent reported, or null when it queued nothing
     */
    private function takeResultReason(): ?string
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $payload = $signal->data;
            $inner = $payload instanceof WebSocketSignalData ? $payload->data : $payload;
            if ($inner instanceof AuthCodeResultSignalData) {
                return $inner->reason;
            }
        }

        return null;
    }

    /**
     * @return string Unique E.164 number for one case
     * @throws RandomException When the platform CSPRNG cannot produce a number
     */
    private function uniquePhone(): string
    {
        return '+1' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    }

    /**
     * Runs one direction of the stub file of every table this case uses.
     *
     * @param bool $down Run the down (drop) stubs when true, the create stubs when false
     * @throws HilosException When a stub statement fails
     */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        foreach (self::TABLES as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/**
 * A framework database context with nothing but the framework's own collections.
 *
 * The code path is framework-owned and every table it touches - the challenge, the
 * identity it asks about, the hold, and the session row the wait is written onto - is a
 * framework one, so the smallest honest context for it is {@see HilosDbContext} with no
 * project collections.
 */
final class CodeChannelTestDbContext extends HilosDbContext
{
}

/**
 * The agent with its registry replaced by one channel this case controls.
 *
 * Only the registry lookup is overridden, so everything the case exercises - the
 * probe/mint/send order, the gate verdicts, the outcome signal - is the agent's own
 * code.
 */
final class CodeChannelTestAgent extends AuthCodeAgent
{
    /**
     * @param CodeChannelTestChannel $channel The one channel this agent resolves
     */
    public function __construct(private readonly CodeChannelTestChannel $channel)
    {
    }

    /**
     * @param string $channel Channel name the request named
     * @return ?CodeChannel The case's channel when the name matches, null otherwise
     */
    protected function resolveChannel(string $channel): ?CodeChannel
    {
        return $channel === $this->channel->name() ? $this->channel : null;
    }
}

/**
 * A channel that answers reachability without the network and records what it was
 * asked to deliver.
 */
final class CodeChannelTestChannel extends CodeChannel
{
    /** @var list<string> Identifiers this channel was handed a code for */
    public array $handedOff = [];

    /**
     * @param string $name Registry name of this channel
     * @param bool $reachable What its probe answers
     */
    public function __construct(
        private readonly string $name,
        private readonly bool $reachable,
    ) {
    }

    /**
     * @return string This channel's registry name
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @param string $type Verification type (see VerificationType)
     * @return bool True for the SMS-delivered types
     */
    public function supportsType(string $type): bool
    {
        return VerificationType::isSms($type);
    }

    /**
     * @param string $identifier Normalized identifier the code would go to
     * @return bool The reachability this channel was built with
     */
    public function reaches(string $identifier): bool
    {
        return $this->reachable;
    }

    /**
     * @param string $identifier Normalized identifier the code goes to
     * @param string $type Verification type the code was minted for (see VerificationType)
     * @param string $code Plaintext code to deliver
     */
    public function handoff(string $identifier, string $type, string $code): void
    {
        $this->handedOff[] = $identifier;
    }
}
