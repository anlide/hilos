<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Session\SessionAck;
use Hilos\Auth\Session\SessionRotationTicket;
use Hilos\Auth\Session\SessionToken;
use Hilos\Core\Daemon\ConnectionDropper;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the rotation ticket the master trades on the 101 (HIL-582).
 *
 * This is where a rotated session token actually reaches the browser, so what is pinned
 * here is the trade and its refusals. A live ticket buys the token its row names - for the
 * cookie AND for the worker handshake, which have to agree or the visitor is handed a
 * cookie naming a session the worker never opened. The ticket is then gone: burned on the
 * spot, so the value is good for one handshake even inside its thirty seconds, and the
 * connections the login left behind are dropped now that the browser holds the new cookie.
 *
 * Every other ticket - malformed, unknown, expired, already spent - is not an error but the
 * ordinary handshake, because that is what an attacker replaying one gets. What they all
 * share is the erasure of the auxiliary cookie: presented once means spent once, and a
 * ticket that survived in the browser would be tried again on every reconnect.
 *
 * The trade carries one more thing than the token (HIL-423): the success ack the socket
 * this rotation ends had not shown yet. It travels on the same terms as the token - only to
 * the handshake that actually spent the ticket.
 */
final class WebSocketClientHandshakeRotationTest extends TestCase
{
    private const string COOKIE_NAME = 'hilos_session_token';

    private const string ROTATE_COOKIE_NAME = 'hilos_session_token_rotate';

    private const string TICKET = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private const string ROTATED_TOKEN = '0f1e2d3c4b5a69788796a5b4c3d2e1f0';

    private ?SignalRouter $previousSignalRouter = null;

    private ?EnvAccessor $previousEnv = null;

    private ?RtContext $previousRt = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor();
        $this->previousRt = Hilos::$rt;
        Hilos::$rt = new RotationTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        // The master registers itself the same way at daemon start; without it the burn
        // the trade performs would be refused as a write from nowhere.
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionRotation::RT_COLLECTION);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionRotation::RT_COLLECTION);
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$env = $this->previousEnv;
    }

    public function testALiveTicketIssuesTheRotatedTokenInsteadOfTheCookieItWasSent(): void
    {
        $planted = SessionToken::mint();
        $this->announceRotation();

        $probe = $this->handshakenProbe($planted, self::TICKET);

        $this->assertSame(self::ROTATED_TOKEN, $this->issuedToken($probe->outboundBytes()));
    }

    public function testTheWorkerHandshakeCarriesTheSameRotatedToken(): void
    {
        $this->announceRotation();

        $this->handshakenProbe(SessionToken::mint(), self::TICKET);

        // The cookie names the session the visitor will present next time; this DTO names the
        // one the worker opens now. A trade that moved only the first would hand out a cookie
        // for a session nobody authenticated.
        $this->assertSame(self::ROTATED_TOKEN, $this->queuedHandshake()->sessionToken);
    }

    public function testATradedTicketCarriesTheAckTheRotatedAwaySocketStillOwed(): void
    {
        $this->announceRotation(pendingAck: SessionAck::REGISTERED);

        $this->handshakenProbe(SessionToken::mint(), self::TICKET);

        // The socket that earned the announcement is the one this rotation ends, so the
        // sentence rides the ticket to its replacement (HIL-423). Without it the browser
        // comes back owing nothing and the surface closes over what it had to say.
        $this->assertSame(SessionAck::REGISTERED, $this->queuedHandshake()->inheritedAck);
    }

    public function testAHandshakeThatTradesNoTicketInheritsNoAck(): void
    {
        $this->announceRotation(pendingAck: SessionAck::REGISTERED);

        $this->handshakenProbe(SessionToken::mint());

        // A socket that presented nothing is a reload, and a reload owes nothing (HIL-422)
        // - the standing rotation belongs to a different socket entirely.
        $this->assertNull($this->queuedHandshake()->inheritedAck);
    }

    public function testATradedTicketIsBurnedSoASecondHandshakeGetsNothing(): void
    {
        $this->announceRotation();

        $this->handshakenProbe(SessionToken::mint(), self::TICKET);

        $this->assertNull(Hilos::$rt?->hilosSessionRotations->claimable(self::TICKET));
    }

    public function testATradeDropsTheConnectionsTheLoginLeftBehind(): void
    {
        $this->announceRotation(['ak-second-tab', 'ak-third-tab']);
        $dropper = new RecordingConnectionDropper();

        $probe = $this->handshakenProbe(SessionToken::mint(), self::TICKET, $dropper);
        $probe->flushOutbound();

        $this->assertTrue($probe->handshakeDone());
        $this->assertSame(['ak-second-tab', 'ak-third-tab'], $dropper->dropped);
    }

    public function testTheLeftBehindConnectionsAreHeldUntilTheRotatedCookieIsSent(): void
    {
        $this->announceRotation(['ak-second-tab']);
        $dropper = new RecordingConnectionDropper();

        $probe = $this->handshakenProbe(SessionToken::mint(), self::TICKET, $dropper);

        // Dropping a tab while the rotated Set-Cookie is still queued would let it come
        // back on the pre-rotation token and write that value back over the jar.
        $this->assertSame([], $dropper->dropped);
        $this->assertStringContainsString(self::ROTATED_TOKEN, $probe->flushOutbound());
        $this->assertSame(['ak-second-tab'], $dropper->dropped);
    }

    public function testAHandshakeWithoutATradeDropsNobody(): void
    {
        $this->announceRotation(['ak-second-tab']);
        $dropper = new RecordingConnectionDropper();

        $this->handshakenProbe(SessionToken::mint(), null, $dropper);

        $this->assertSame([], $dropper->dropped);
    }

    public function testAnUnknownTicketFallsBackToTheOrdinaryCookieRule(): void
    {
        $sent = SessionToken::mint();

        $probe = $this->handshakenProbe($sent, self::TICKET);

        $this->assertSame($sent, $this->issuedToken($probe->outboundBytes()));
    }

    public function testAnExpiredTicketFallsBackToTheOrdinaryCookieRule(): void
    {
        $sent = SessionToken::mint();
        $this->announceRotation(expiresInSeconds: -1);

        $probe = $this->handshakenProbe($sent, self::TICKET);

        $this->assertSame($sent, $this->issuedToken($probe->outboundBytes()));
    }

    public function testATicketThatIsNotOfTheMintedFormIsNotEvenLookedUp(): void
    {
        $sent = SessionToken::mint();
        $this->announceRotation();

        $probe = $this->handshakenProbe($sent, 'not-a-ticket');

        $this->assertSame($sent, $this->issuedToken($probe->outboundBytes()));
        // Refused on form alone, so the live rotation is still there for its real bearer.
        $this->assertNotNull(Hilos::$rt?->hilosSessionRotations->claimable(self::TICKET));
    }

    public function testEveryPresentedTicketIsErasedFromTheBrowser(): void
    {
        $this->announceRotation();

        foreach ([self::TICKET, 'not-a-ticket', ''] as $presented) {
            $outbound = $this->handshakenProbe(SessionToken::mint(), $presented)->outboundBytes();

            $this->assertStringContainsString(
                'Set-Cookie: ' . self::ROTATE_COOKIE_NAME . '=; Path=/; SameSite=Strict; Max-Age=0',
                $outbound,
                "A handshake presenting '{$presented}' left the auxiliary cookie in place",
            );
        }
    }

    public function testAHandshakeThatPresentsNoTicketSetsOneCookieAsBefore(): void
    {
        $outbound = $this->handshakenProbe(SessionToken::mint())->outboundBytes();

        $this->assertSame(1, substr_count($outbound, 'Set-Cookie:'));
    }

    /**
     * Announces a rotation the way the session seam does from its worker.
     *
     * @param list<string> $keysToDrop Accept keys of the session's other connections
     * @param float $expiresInSeconds Ticket lifetime from now; negative lands in the past
     * @param ?string $pendingAck Ack the initiating connection still owed, or null for none
     */
    private function announceRotation(
        array $keysToDrop = [],
        float $expiresInSeconds = 30,
        ?string $pendingAck = null,
    ): void {
        Hilos::$rt?->hilosSessionRotations->actions->register(
            self::TICKET,
            self::ROTATED_TOKEN,
            $keysToDrop,
            (microtime(true) + $expiresInSeconds) * 1000,
            $pendingAck,
        );
    }

    /**
     * Drive a probe through a complete handshake carrying the given cookies.
     *
     * @param string $sessionCookie Session cookie value the client sends
     * @param ?string $ticket Auxiliary rotation cookie value, or null for a client carrying none
     * @param ?ConnectionDropper $dropper Drop seam the accepting server would have wired in
     * @return WebSocketClientTestProbe Probe with a completed handshake
     */
    private function handshakenProbe(
        string $sessionCookie,
        ?string $ticket = null,
        ?ConnectionDropper $dropper = null,
    ): WebSocketClientTestProbe {
        $probe = WebSocketClientTestProbe::createSocketless();
        if ($dropper !== null) {
            $probe->setConnectionDropper($dropper);
        }
        $cookies = self::COOKIE_NAME . '=' . $sessionCookie;
        if ($ticket !== null) {
            $cookies .= '; ' . self::ROTATE_COOKIE_NAME . '=' . $ticket;
        }

        $probe->feed(
            "GET /ws HTTP/1.1\r\n"
            . "Host: localhost:8092\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . base64_encode('0123456789abcdef') . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . 'Cookie: ' . $cookies . "\r\n"
            . "\r\n",
        );
        $this->assertTrue($probe->handshakeDone());

        return $probe;
    }

    /**
     * Finds the handshake payload among everything the queue holds.
     *
     * The announcement's own RT sync is queued ahead of it, so the search is by type rather
     * than by position.
     *
     * @return WebSocketHandshakeSignalDTO Handshake payload the master queued for the worker
     */
    private function queuedHandshake(): WebSocketHandshakeSignalDTO
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketHandshakeSignalDTO) {
                return $signal->data;
            }
        }

        $this->fail('The handshake queued no payload for the worker');
    }

    /**
     * @param string $outbound Bytes queued for the client (101 response + frames)
     * @return string Session token value carried by the session Set-Cookie header
     */
    private function issuedToken(string $outbound): string
    {
        $matched = preg_match('/^Set-Cookie: ' . self::COOKIE_NAME . '=([^;]*);/m', $outbound, $matches);
        $this->assertSame(1, $matched, 'The 101 carries no session Set-Cookie header');

        return $matches[1];
    }
}

/**
 * Runtime context of a project that mounts nothing of its own.
 *
 * The rotations collection is framework-owned and mounted for every project, so the master
 * finds it here exactly as it does in a real daemon.
 */
final class RotationTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

/**
 * Connection-drop seam that records instead of closing sockets.
 */
final class RecordingConnectionDropper implements ConnectionDropper
{
    /** @var list<string> Accept keys the master asked to close, in order */
    public array $dropped = [];

    /**
     * @param string $acceptKey Daemon-minted identifier of the connection to close
     * @return bool Always true; a probe has no socket table to miss in
     */
    public function dropWebSocketConnection(string $acceptKey): bool
    {
        $this->dropped[] = $acceptKey;

        return true;
    }
}
