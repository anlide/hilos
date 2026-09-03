<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Core\Daemon\ProtectedModeAdmissionRecorder;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\ProtectedMode\ProtectedModeAdmissionConstants;
use Hilos\ProtectedMode\ProtectedModeStubCopy;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Tests\Unit\WebSocketClientTestProbe;
use PHPUnit\Framework\TestCase;
use Hilos\Core\Daemon\DaemonManager;

/**
 * Unit tests for letting a verifier through the freeze on its upgrade request.
 *
 * Admission is decided on the 101 and nowhere else: while the mode holds, the frontend is
 * refused every outbound frame, so a connection that cannot speak cannot ask to be let in.
 * What is pinned here is the whole of that decision - a matching key admits and the welcome
 * stops claiming the mode holds this connection, a wrong key changes nothing, and a key
 * presented outside the verification window is not a key at all.
 */
final class ProtectedModeAdmissionTest extends TestCase
{
    private const string PASS = 'let-me-in-please';

    /** Session cookie of the browser that asked for the operation, in the minted token form. */
    private const string INITIATOR_SESSION_TOKEN = '0123456789abcdef0123456789abcdef';

    /** Session cookie of the browser the code was read out to, same form and a third value. */
    private const string VERIFIER_SESSION_TOKEN = '89abcdef0123456789abcdef01234567';

    /** Session cookie of any other browser, same form and a different value. */
    private const string STRANGER_SESSION_TOKEN = 'fedcba9876543210fedcba9876543210';

    private ?SignalRouter $previousSignalRouter = null;

    private ?EnvAccessor $previousEnv = null;

    private RecordingAdmissionRecorder $recorder;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor();
        $this->recorder = new RecordingAdmissionRecorder();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$env = $this->previousEnv;
        Hilos::$rt = null;
        putenv('HILOS_BUILD_TIMESTAMP');

        parent::tearDown();
    }

    public function testAMatchingPassAdmitsTheBrowserSessionRatherThanTheSocket(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, [hash('sha256', self::PASS)]);

        $this->handshake(self::PASS, sessionToken: self::VERIFIER_SESSION_TOKEN);

        $this->assertSame(
            [StateProtectedModeRuntime::hashSessionToken(self::VERIFIER_SESSION_TOKEN)],
            $this->recorder->admitted,
        );
    }

    public function testTheSecondTabOfAnAdmittedBrowserIsLetInWithoutPresentingTheCode(): void
    {
        // The whole leaf in one case: the verifier types the code in one tab and opens another,
        // which carries the same cookie, a brand new accept key and no code at all.
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, [hash('sha256', self::PASS)]);
        $this->handshake(self::PASS, admitOnTheRow: true, sessionToken: self::VERIFIER_SESSION_TOKEN);

        $block = $this->protectedModeBlock($this->handshake(null, sessionToken: self::VERIFIER_SESSION_TOKEN));

        $this->assertFalse($block['active']);
    }

    public function testASecondBrowserPresentingTheSameCodeIsAdmittedOnItsOwnAccount(): void
    {
        // The code is reusable on purpose, so a second verifier reading it over the phone is a
        // second session: neither entry stands in for the other.
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, [hash('sha256', self::PASS)]);

        $this->handshake(self::PASS, admitOnTheRow: true, sessionToken: self::VERIFIER_SESSION_TOKEN);
        $this->handshake(self::PASS, admitOnTheRow: true, sessionToken: self::STRANGER_SESSION_TOKEN);

        $this->assertSame(
            [
                StateProtectedModeRuntime::hashSessionToken(self::VERIFIER_SESSION_TOKEN),
                StateProtectedModeRuntime::hashSessionToken(self::STRANGER_SESSION_TOKEN),
            ],
            Hilos::$rt?->hilosProtectedModeRuntime?->admittedSessionTokenHashes,
        );
    }

    public function testTheSameBrowserPresentingTheCodeTwiceLeavesOneEntry(): void
    {
        // Two tabs of one browser both remember the code and both present it on their handshake.
        // The seam is asked twice - it records a decision, not a transition - and the row keeps one.
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, [hash('sha256', self::PASS)]);

        $this->handshake(self::PASS, admitOnTheRow: true, sessionToken: self::VERIFIER_SESSION_TOKEN);
        $this->handshake(self::PASS, admitOnTheRow: true, sessionToken: self::VERIFIER_SESSION_TOKEN);

        $this->assertCount(2, $this->recorder->admitted);
        $this->assertSame(
            [StateProtectedModeRuntime::hashSessionToken(self::VERIFIER_SESSION_TOKEN)],
            Hilos::$rt?->hilosProtectedModeRuntime?->admittedSessionTokenHashes,
        );
    }

    public function testAWrongPassAdmitsNobody(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, [hash('sha256', self::PASS)]);

        $this->handshake('not-the-key');

        $this->assertSame([], $this->recorder->admitted);
    }

    public function testARightPassOutsideTheWindowAdmitsNobody(): void
    {
        // The list is empty on a frozen phase by construction; this row carries one anyway, so
        // what is proven is that the phase alone refuses - not that there was nothing to match.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, [hash('sha256', self::PASS)]);

        $this->handshake(self::PASS);

        $this->assertSame([], $this->recorder->admitted);
    }

    public function testAConnectionThatPresentsNothingIsNotEvenConsidered(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, [hash('sha256', self::PASS)]);

        $this->handshake(null);

        $this->assertSame([], $this->recorder->admitted);
    }

    public function testTheWelcomeOffersACodeFieldToWhoeverIsStillHeld(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, [hash('sha256', self::PASS)]);

        $block = $this->protectedModeBlock($this->handshake(null));

        $this->assertTrue($block['active']);
        $this->assertTrue($block['acceptsPass']);
        $this->assertTrue($block['passIssued']);
    }

    public function testAWindowWithNothingMintedSaysSoInsteadOfOfferingAField(): void
    {
        // The state the leaf was written from: the phase says "present your code" from the moment
        // it opens, while the codes are minted one by one afterwards. The two bits part company
        // here and nowhere else.
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, []);

        $block = $this->protectedModeBlock($this->handshake(null));

        $this->assertTrue($block['active']);
        $this->assertTrue($block['acceptsPass']);
        $this->assertFalse($block['passIssued']);
    }

    public function testAnAdmittedConnectionIsToldTheModeDoesNotHoldItButIsStillOn(): void
    {
        // The seam is the daemon's, so the test records the admission on the row itself, the way
        // DaemonManager does - the point being that the welcome is composed after that write.
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, [hash('sha256', self::PASS)]);

        $block = $this->protectedModeBlock($this->handshake(self::PASS, admitOnTheRow: true));

        $this->assertFalse($block['active']);
        // The row's bit, not this connection's, and the only thing separating this welcome from
        // the one a lifted mode sends: the verifier that reads it is inside a window still open,
        // and a client that could not tell would reload itself straight back out of it.
        $this->assertTrue($block['acceptsPass']);
        $this->assertTrue($block['passIssued']);
    }

    public function testAHeldConnectionIsWordedForTheSurfaceAndNotForTheBanner(): void
    {
        // The two audiences are exclusive, and the welcome is where that is decided. This one is
        // held, so it renders the maintenance surface and gets the words for it - and no banner
        // sentence, because it has no application to put a banner over.
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, [hash('sha256', self::PASS)]);

        $block = $this->protectedModeBlock($this->handshake(null));

        $this->assertTrue($block['active']);
        $this->assertNotNull($block['title']);
        $this->assertNotNull($block['message']);
        $this->assertNull($block['bannerMessage']);
    }

    public function testAnAdmittedConnectionIsWordedForTheBannerAndNotForTheSurface(): void
    {
        // The mirror of the case above, and the hole this leaf closes: until HIL-736 the welcome
        // resolved no copy at all for a connection it was letting in, so an operator coming back
        // from an F5 was handed a running application with nothing on it saying the mode was
        // still on.
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, [hash('sha256', self::PASS)]);

        $block = $this->protectedModeBlock($this->handshake(self::PASS, admitOnTheRow: true));

        $this->assertFalse($block['active']);
        $this->assertNull($block['title']);
        $this->assertNull($block['message']);
        $this->assertSame(
            ProtectedModeStubCopy::forOperation($block['operation'])->bannerMessage,
            $block['bannerMessage'],
        );
        $this->assertNotNull($block['bannerMessage']);
    }

    public function testAWelcomeSentWithNoFreezeAtAllCarriesNeitherKindOfCopy(): void
    {
        // The third state, and the one that must stay wordless: nothing holds the node, so there
        // is no surface to word and no banner to raise. Pinned because the resolution above is
        // now reached by two conditions rather than one.
        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE, []);

        $block = $this->protectedModeBlock($this->handshake(null));

        $this->assertFalse($block['active']);
        $this->assertNull($block['operation']);
        $this->assertNull($block['title']);
        $this->assertNull($block['message']);
        $this->assertNull($block['bannerMessage']);
    }

    public function testTheInitiatorsOtherTabIsHeldByTheFreezeLikeEveryOther(): void
    {
        // The socket the operation was started from is gone - this is the reload that replaced it,
        // arriving with a brand new accept key and the same cookie. It is the same person, and
        // while the node is frozen that buys nothing: there is no application behind the door for
        // anybody, so the operator is sent to the maintenance surface where the restore panel is.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, [], self::INITIATOR_SESSION_TOKEN);

        $block = $this->protectedModeBlock($this->handshake(null, sessionToken: self::INITIATOR_SESSION_TOKEN));

        $this->assertTrue($block['active']);
    }

    public function testTheInitiatorsOtherTabIsLetInOnceTheWindowOpens(): void
    {
        // Same reload, one phase later: the agents are back up, so the identity the freeze ignored
        // is what carries the operator back into the application without a pass of its own.
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, [], self::INITIATOR_SESSION_TOKEN);

        $block = $this->protectedModeBlock($this->handshake(null, sessionToken: self::INITIATOR_SESSION_TOKEN));

        $this->assertFalse($block['active']);
    }

    public function testAnotherBrowserIsHeldByTheFreezeAsBefore(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, [], self::INITIATOR_SESSION_TOKEN);

        $block = $this->protectedModeBlock($this->handshake(null, sessionToken: self::STRANGER_SESSION_TOKEN));

        $this->assertTrue($block['active']);
    }

    public function testAFreezeNobodyStartedFromABrowserHoldsEveryConnection(): void
    {
        // A restore started from the CLI records no session, and the connection that just minted
        // its first cookie carries one nothing can match: neither side may stand in for the other.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, []);

        $block = $this->protectedModeBlock($this->handshake(null, sessionToken: self::INITIATOR_SESSION_TOKEN));

        $this->assertTrue($block['active']);
    }

    public function testAFrozenPhaseOffersNoCodeField(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, []);

        $block = $this->protectedModeBlock($this->handshake(null));

        $this->assertTrue($block['active']);
        $this->assertFalse($block['acceptsPass']);
        // A frozen phase voids its hashes on the way in, so neither bit survives the exit from
        // the window - the field is gone because the window is, not because it emptied.
        $this->assertFalse($block['passIssued']);
    }

    /**
     * Mounts the freeze row in the phase and with the minted passes the case needs.
     *
     * @param string $phase Freeze phase to mount
     * @param list<string> $passHashes Hashes of the passes the window has outstanding
     * @param ?string $initiatorSessionToken Session token of the browser that asked, or null when nothing with one did
     */
    private function freeze(string $phase, array $passHashes, ?string $initiatorSessionToken = null): void
    {
        Hilos::$rt = new AdmissionTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => $phase,
            StateProtectedModeRuntime::operation => 'restore',
            StateProtectedModeRuntime::initiatorAcceptKey => 'accept-initiator',
            StateProtectedModeRuntime::initiatorSessionTokenHash => $initiatorSessionToken === null
                ? null
                : StateProtectedModeRuntime::hashSessionToken($initiatorSessionToken),
            StateProtectedModeRuntime::initiatorAgentType => 'backup',
            StateProtectedModeRuntime::passHashes => $passHashes,
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
        ]));
    }

    /**
     * Drives a probe through a handshake that presents a pass, or none.
     *
     * @param ?string $pass Clear pass to append to the upgrade url, or null to present none
     * @param bool $admitOnTheRow Whether the recorder should write the admission through to the row
     * @return WebSocketClientTestProbe Probe with a completed handshake
     */
    private function handshake(
        ?string $pass,
        bool $admitOnTheRow = false,
        ?string $sessionToken = null,
    ): WebSocketClientTestProbe {
        $probe = WebSocketClientTestProbe::createSocketless();
        $this->recorder->writeThrough = $admitOnTheRow;
        $probe->setProtectedModeAdmissionRecorder($this->recorder);

        $path = '/ws';
        if ($pass !== null) {
            $path .= '?' . ProtectedModeAdmissionConstants::HILOS_PASS_QUERY_PARAM . '=' . rawurlencode($pass);
        }
        $cookieHeader = $sessionToken === null
            ? ''
            : 'Cookie: hilos_session_token=' . $sessionToken . "\r\n";

        $probe->feed(
            "GET {$path} HTTP/1.1\r\n"
            . "Host: localhost:8092\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . $cookieHeader
            . 'Sec-WebSocket-Key: ' . base64_encode('0123456789abcdef') . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n",
        );
        $this->assertTrue($probe->handshakeDone());

        return $probe;
    }

    /**
     * Reads the protected-mode block off the welcome frame this handshake queued.
     *
     * @param WebSocketClientTestProbe $probe Probe with a completed handshake
     * @return array<string, mixed> Protected-mode block of the welcome frame
     */
    private function protectedModeBlock(WebSocketClientTestProbe $probe): array
    {
        $outbound = $probe->outboundBytes();
        $delimiterPos = strpos($outbound, "\r\n\r\n");
        $this->assertNotFalse($delimiterPos, 'No 101 response in the outbound bytes');

        $frame = substr($outbound, $delimiterPos + 4);
        $lengthByte = ord($frame[1]);
        $headerLength = $lengthByte === 126 ? 4 : 2;
        $payloadLength = $lengthByte === 126 ? unpack('n', substr($frame, 2, 2))[1] : $lengthByte;

        $decoded = json_decode(substr($frame, $headerLength, $payloadLength), true);
        $this->assertIsArray($decoded);
        $block = $decoded['data']['protectedMode'] ?? null;
        $this->assertIsArray($block);

        return $block;
    }
}

final class AdmissionTestRtContext extends RtContext
{
    /**
     * Registers no project runtime state: the framework mount supplies the freeze row.
     */
    public function configure(): void
    {
    }
}

/**
 * Recording fake of the master admission seam: captures each session it was asked to admit.
 *
 * Every call is captured, including the ones the row then deduplicates: the seam records a
 * decision rather than a transition, and a test that wants to see the guard needs to watch the
 * two counts part company.
 *
 * It can also write the admission through to the row, which is what the real
 * {@see DaemonManager} does - a test that needs the welcome to see an admitted connection has
 * to have that write land before the frame is composed, which is the ordering under test.
 */
final class RecordingAdmissionRecorder implements ProtectedModeAdmissionRecorder
{
    /** @var list<string> Session token hashes the master was asked to admit, in order */
    public array $admitted = [];

    /** @var bool Whether to record the admission on the freeze row as well */
    public bool $writeThrough = false;

    public function admitProtectedModeSession(string $sessionTokenHash): void
    {
        $this->admitted[] = $sessionTokenHash;
        if (!$this->writeThrough) {
            return;
        }

        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        try {
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->admitSession($sessionTokenHash);
        } finally {
            RtTruthSourceRegistry::unregisterDaemon(StateProtectedModeRuntime::RT_ITEM);
        }
    }
}
