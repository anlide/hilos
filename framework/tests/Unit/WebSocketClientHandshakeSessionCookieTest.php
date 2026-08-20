<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Session\SessionToken;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the session cookie the master puts on the 101 (HIL-556, HIL-582).
 *
 * Four things are pinned here. The cookie is issued on EVERY handshake, not
 * only when the token is minted, because the session row's expiry slides on
 * each one and the two have to slide together. A cookie that does not carry the
 * minted form is replaced instead of passed through, which is what ends the
 * client that used to stick forever on a token the worker refused. Max-Age
 * comes from HILOS_SESSION_COOKIE_MAX_AGE, the one number behind both halves of
 * a session's lifetime. And Secure is read off APP_ENV rather than a switch of
 * its own (HIL-582), so the whole truth table is held here: a production-like
 * environment secures the cookie, everything else - including a value the enum
 * does not recognise - leaves it plain.
 */
final class WebSocketClientHandshakeSessionCookieTest extends TestCase
{
    private const string COOKIE_NAME = 'hilos_session_token';

    private ?SignalRouter $previousSignalRouter = null;

    private ?EnvAccessor $previousEnv = null;

    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor();
        $this->previousAppEnv = getenv('APP_ENV');
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$env = $this->previousEnv;
        putenv('HILOS_SESSION_COOKIE_MAX_AGE');
        // The suite itself runs under an APP_ENV; putting the captured value back rather
        // than unsetting it keeps a case in this file from deciding what the next file reads.
        $this->previousAppEnv === false
            ? putenv('APP_ENV')
            : putenv('APP_ENV=' . $this->previousAppEnv);
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

    /**
     * Secure follows APP_ENV and nothing else (HIL-582).
     *
     * @param string $appEnv Value of APP_ENV the master runs under
     * @param bool $secured Whether the issued cookie is expected to carry Secure
     */
    #[DataProvider('appEnvSecureCases')]
    public function testCookieSecureFollowsTheEnvironment(string $appEnv, bool $secured): void
    {
        putenv('APP_ENV=' . $appEnv);

        $line = $this->setCookieLine($this->handshakenProbe()->outboundBytes());

        $secured
            ? $this->assertStringContainsString('; Secure', $line)
            : $this->assertStringNotContainsString('; Secure', $line);
    }

    /**
     * Every environment the enum names, plus the one value it does not.
     *
     * An unrecognised name stays plain, because a deployment that cannot say what it is must
     * not have its cookie dropped by a browser on plain http. The EMPTY name is no longer a
     * case: since HIL-566 APP_ENV is a required catalog entry, so a node that names no
     * environment refuses to start and never reaches a handshake to issue a cookie on.
     *
     * @return array<string, array{string, bool}> Case name to APP_ENV value and expected Secure
     */
    public static function appEnvSecureCases(): array
    {
        return [
            'prod is secured' => ['prod', true],
            'staging is secured' => ['staging', true],
            'dev is plain' => ['dev', false],
            'local is plain' => ['local', false],
            'test is plain' => ['test', false],
            'an alias resolves like its canonical value' => ['production', true],
            'an unrecognised environment is plain' => ['nonsense', false],
        ];
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
