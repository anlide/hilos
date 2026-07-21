<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\OAuth\OAuthLinkTokenSigner;
use Hilos\Auth\OAuth\OAuthStateSigner;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the stateless OAuth account-link token signer (HIL-282).
 *
 * The capability the email-collision branch hands the browser: it round-trips its
 * fields, rejects tampering / expiry / empties, and — sharing one app secret with
 * the state signer — stays cryptographically distinct from a state token.
 */
final class OAuthLinkTokenSignerTest extends TestCase
{
    private const string SECRET = 'unit-app-secret';

    public function testIssueRoundTripsProviderSubjectAndEmail(): void
    {
        $signer = new OAuthLinkTokenSigner(self::SECRET);

        $token = $signer->issue('oauth:github', '4242', 'user@example.com', 600);
        $data = $signer->verify($token);

        self::assertNotNull($data);
        self::assertSame('oauth:github', $data->provider);
        self::assertSame('4242', $data->subject);
        self::assertSame('user@example.com', $data->email);
    }

    public function testVerifyRejectsATamperedSignature(): void
    {
        $signer = new OAuthLinkTokenSigner(self::SECRET);

        $token = $signer->issue('oauth:github', '4242', 'user@example.com', 600);

        self::assertNull($signer->verify($token . 'x'));
    }

    public function testVerifyRejectsADifferentSecret(): void
    {
        $token = (new OAuthLinkTokenSigner(self::SECRET))->issue('oauth:github', '4242', 'user@example.com', 600);

        self::assertNull((new OAuthLinkTokenSigner('other-secret'))->verify($token));
    }

    public function testVerifyRejectsAnExpiredToken(): void
    {
        $signer = new OAuthLinkTokenSigner(self::SECRET);

        $token = $signer->issue('oauth:github', '4242', 'user@example.com', -1);

        self::assertNull($signer->verify($token));
    }

    public function testVerifyRejectsAnEmptyEmailField(): void
    {
        $signer = new OAuthLinkTokenSigner(self::SECRET);

        $token = $signer->issue('oauth:github', '4242', '', 600);

        self::assertNull($signer->verify($token));
    }

    public function testVerifyRejectsAMalformedToken(): void
    {
        $signer = new OAuthLinkTokenSigner(self::SECRET);

        self::assertNull($signer->verify('not-a-token'));
    }

    /**
     * Domain separation: a state token minted with the same secret is never a valid
     * link token (its payload lacks the link domain tag and field shape).
     */
    public function testVerifyRejectsAStateTokenSharingTheSecret(): void
    {
        $stateToken = (new OAuthStateSigner(self::SECRET))->issue('session-token', 600);

        self::assertNull((new OAuthLinkTokenSigner(self::SECRET))->verify($stateToken));
    }
}
