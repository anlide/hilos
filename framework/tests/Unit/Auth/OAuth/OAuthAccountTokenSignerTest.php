<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\OAuth\OAuthAccountTokenSigner;
use Hilos\Auth\OAuth\OAuthLinkTokenSigner;
use PHPUnit\Framework\TestCase;

/** The first sign-in token is signed, session-bound, expiring, and domain-separated (HIL-1235). */
final class OAuthAccountTokenSignerTest extends TestCase
{
    private const string SECRET = 'unit-app-secret';
    private const string SESSION = 'session-one';

    public function testRoundTripsProviderFactsIncludingUnusualNameAndMissingEmail(): void
    {
        $signer = new OAuthAccountTokenSigner(self::SECRET);
        $token = $signer->issue('oauth:github', 'subject-42', null, 'Łukasz | Developer', self::SESSION, 600);

        $data = $signer->verify($token, self::SESSION);

        self::assertNotNull($data);
        self::assertSame('oauth:github', $data->provider);
        self::assertSame('subject-42', $data->subject);
        self::assertNull($data->email);
        self::assertSame('Łukasz | Developer', $data->displayName);
    }

    public function testRejectsAnotherSession(): void
    {
        $signer = new OAuthAccountTokenSigner(self::SECRET);
        $token = $signer->issue('oauth:github', '42', 'user@example.test', 'User', self::SESSION, 600);

        self::assertNull($signer->verify($token, 'another-session'));
    }

    public function testRejectsExpiredAndTamperedTokens(): void
    {
        $signer = new OAuthAccountTokenSigner(self::SECRET);
        $expired = $signer->issue('oauth:github', '42', null, 'User', self::SESSION, -1);
        $valid = $signer->issue('oauth:github', '42', null, 'User', self::SESSION, 600);

        self::assertNull($signer->verify($expired, self::SESSION));
        self::assertNull($signer->verify($valid . 'x', self::SESSION));
        self::assertNull($signer->verify('bad-token', self::SESSION));
    }

    public function testLinkAndAccountTokensCannotBeInterchanged(): void
    {
        $accountSigner = new OAuthAccountTokenSigner(self::SECRET);
        $linkSigner = new OAuthLinkTokenSigner(self::SECRET);
        $account = $accountSigner->issue('oauth:github', '42', 'user@example.test', 'User', self::SESSION, 600);
        $link = $linkSigner->issue('oauth:github', '42', 'user@example.test', 600);

        self::assertNull($accountSigner->verify($link, self::SESSION));
        self::assertNull($linkSigner->verify($account));
    }
}
