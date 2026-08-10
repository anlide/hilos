<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Session\SessionToken;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the session cookie the master puts on the 101 (HIL-556).
 *
 * Three things are pinned here. The cookie is issued on EVERY handshake, not
 * only when the token is minted, because the session row's expiry slides on
 * each one and the two have to slide together. A cookie that does not carry the
 * minted form is replaced instead of passed through, which is what ends the
 * client that used to stick forever on a token the worker refused. And Max-Age
 * comes from HILOS_SESSION_COOKIE_MAX_AGE, the one number behind both halves of
 * a session's lifetime.
 */
final class WebSocketClientHandshakeSessionCookieTest extends TestCase
{
    private const string COOKIE_NAME = 'hilos_session_token';

    private ?SignalRouter $previousSignalRouter = null;

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$env = $this->previousEnv;
        putenv('HILOS_SESSION_COOKIE_MAX_AGE');
        putenv('HILOS_SESSION_COOKIE_SECURE');
    }

    public function testHandshakeWithoutACookieIssuesAMintedToken(): void
    {
        $probe = $this->handshakenProbe();

        $this->assertTrue(SessionToken::isValid($this->issuedToken($probe->outboundBytes())));
    }

    public function testHandshakeReissuesTheCookieItWasSent(): void
    {
        $sent = SessionToken::mint();

        $probe = $this->handshakenProbe($sent);

        $this->assertSame($sent, $this->issuedToken($probe->outboundBytes()));
        $this->assertSame($sent, $probe->capturedCookies[self::COOKIE_NAME] ?? null);
    }

    public function testHandshakeReplacesACookieThatIsNotAMintedToken(): void
    {
        $sent = 'not-a-session-token';

        $probe = $this->handshakenProbe($sent);

        $issued = $this->issuedToken($probe->outboundBytes());
        $this->assertNotSame($sent, $issued);
        $this->assertTrue(SessionToken::isValid($issued));
    }

    public function testHandshakeReplacesACookieWrittenInUppercaseHex(): void
    {
        $sent = strtoupper(SessionToken::mint());

        $probe = $this->handshakenProbe($sent);

        $issued = $this->issuedToken($probe->outboundBytes());
        $this->assertNotSame($sent, $issued);
        $this->assertTrue(SessionToken::isValid($issued));
    }

    public function testHandshakeReplacesABlankCookie(): void
    {
        $probe = $this->handshakenProbe('');

        $this->assertTrue(SessionToken::isValid($this->issuedToken($probe->outboundBytes())));
    }

    public function testCookieMaxAgeFollowsTheConfiguredSessionLifetime(): void
    {
        putenv('HILOS_SESSION_COOKIE_MAX_AGE=4242');

        $probe = $this->handshakenProbe();

        $this->assertStringContainsString('; Max-Age=4242', $this->setCookieLine($probe->outboundBytes()));
    }

    public function testCookieCarriesPathHttpOnlyAndSameSiteStrict(): void
    {
        $line = $this->setCookieLine($this->handshakenProbe()->outboundBytes());

        $this->assertStringContainsString('; Path=/', $line);
        $this->assertStringContainsString('; HttpOnly', $line);
        $this->assertStringContainsString('; SameSite=Strict', $line);
    }

    public function testCookieIsPlainWhenTheStackIsNotSecured(): void
    {
        putenv('HILOS_SESSION_COOKIE_SECURE=false');

        $line = $this->setCookieLine($this->handshakenProbe()->outboundBytes());

        $this->assertStringNotContainsString('; Secure', $line);
    }

    public function testCookieIsSecuredWhenConfigured(): void
    {
        putenv('HILOS_SESSION_COOKIE_SECURE=true');

        $line = $this->setCookieLine($this->handshakenProbe()->outboundBytes());

        $this->assertStringContainsString('; Secure', $line);
    }

    /**
     * Drive a probe through a complete handshake, optionally sending a cookie.
     *
     * @param ?string $cookieValue Session cookie value to send, or null for a client that has none
     * @return WebSocketClientTestProbe Probe with a completed handshake
     */
    private function handshakenProbe(?string $cookieValue = null): WebSocketClientTestProbe
    {
        $probe = WebSocketClientTestProbe::createSocketless();
        $key = base64_encode('0123456789abcdef');
        $cookieHeader = $cookieValue === null
            ? ''
            : 'Cookie: ' . self::COOKIE_NAME . '=' . $cookieValue . "\r\n";

        $probe->feed(
            "GET /ws HTTP/1.1\r\n"
            . "Host: localhost:8092\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . $cookieHeader
            . "\r\n",
        );
        $this->assertTrue($probe->handshakeDone());

        return $probe;
    }

    /**
     * @param string $outbound Bytes queued for the client (101 response + frames)
     * @return string The single Set-Cookie header line of the 101
     */
    private function setCookieLine(string $outbound): string
    {
        $matched = preg_match('/^Set-Cookie: .+$/m', $outbound, $matches);
        $this->assertSame(1, $matched, 'The 101 carries no Set-Cookie header');
        $this->assertSame(1, substr_count($outbound, 'Set-Cookie:'), 'The 101 carries more than one Set-Cookie header');

        return rtrim($matches[0]);
    }

    /**
     * @param string $outbound Bytes queued for the client (101 response + frames)
     * @return string Token value carried by the Set-Cookie header
     */
    private function issuedToken(string $outbound): string
    {
        $line = $this->setCookieLine($outbound);
        $matched = preg_match('/^Set-Cookie: ' . self::COOKIE_NAME . '=([^;]*);/', $line, $matches);
        $this->assertSame(1, $matched, "Set-Cookie does not carry the session token: {$line}");

        return $matches[1];
    }
}
