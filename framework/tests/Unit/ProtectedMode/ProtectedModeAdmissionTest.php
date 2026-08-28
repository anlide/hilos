<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Core\Daemon\ProtectedModeAdmissionRecorder;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\ProtectedMode\ProtectedModeAdmissionConstants;
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

    public function testAMatchingPassAdmitsTheConnection(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, [hash('sha256', self::PASS)]);

        $probe = $this->handshake(self::PASS);

        $this->assertSame([$probe->acceptKey], $this->recorder->admitted);
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

    public function testTheInitiatorsOtherTabIsNotHeldByTheFreeze(): void
    {
        // The socket the operation was started from is gone - this is the reload that replaced it,
        // arriving with a brand new accept key and the same cookie. It is the same person.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, [], self::INITIATOR_SESSION_TOKEN);

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
            StateProtectedModeRuntime::admittedAcceptKeys => [],
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
 * Recording fake of the master admission seam: captures each admitted key.
 *
 * It can also write the admission through to the row, which is what the real
 * {@see DaemonManager} does - a test that needs the welcome to see an admitted connection has
 * to have that write land before the frame is composed, which is the ordering under test.
 */
final class RecordingAdmissionRecorder implements ProtectedModeAdmissionRecorder
{
    /** @var list<string> Accept keys the master was asked to admit, in order */
    public array $admitted = [];

    /** @var bool Whether to record the admission on the freeze row as well */
    public bool $writeThrough = false;

    public function admitProtectedModeConnection(string $acceptKey): void
    {
        $this->admitted[] = $acceptKey;
        if (!$this->writeThrough) {
            return;
        }

        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        try {
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->admitConnection($acceptKey);
        } finally {
            RtTruthSourceRegistry::unregisterDaemon(StateProtectedModeRuntime::RT_ITEM);
        }
    }
}
