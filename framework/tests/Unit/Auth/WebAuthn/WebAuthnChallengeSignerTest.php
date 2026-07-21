<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\WebAuthn;

use Hilos\Auth\WebAuthn\Exception\WebAuthnChallengeException;
use Hilos\Auth\WebAuthn\WebAuthnChallengeSigner;
use PHPUnit\Framework\TestCase;
use Random\RandomException;

/**
 * Unit tests for the stateless, signed WebAuthn challenge token (HIL-284).
 *
 * A challenge token round-trips only for the same purpose and session it was
 * bound to and only while unexpired, recovers the original challenge and bound
 * user, and rejects any tamper or wrong secret — all without persisting anything.
 */
final class WebAuthnChallengeSignerTest extends TestCase
{
    private const string SECRET = 'unit-test-webauthn-secret';
    private const string SESSION = 'session-token-abc123';

    /**
     * A register challenge round-trips and recovers its challenge and bound user.
     *
     * @throws RandomException When the CSPRNG cannot produce a challenge
     * @throws WebAuthnChallengeException Never in the success path
     */
    public function testRegisterChallengeRoundTripsWithBoundUser(): void
    {
        $signer = new WebAuthnChallengeSigner(self::SECRET);

        $issued = $signer->issue(WebAuthnChallengeSigner::PURPOSE_REGISTER, self::SESSION, 42, 300);
        $claims = $signer->verify($issued->token, WebAuthnChallengeSigner::PURPOSE_REGISTER, self::SESSION);

        self::assertSame($issued->challenge, $claims->challenge);
        self::assertSame(42, $claims->userId);
    }

    /**
     * A login challenge carries no bound user.
     *
     * @throws RandomException When the CSPRNG cannot produce a challenge
     * @throws WebAuthnChallengeException Never in the success path
     */
    public function testLoginChallengeHasNoBoundUser(): void
    {
        $signer = new WebAuthnChallengeSigner(self::SECRET);

        $issued = $signer->issue(WebAuthnChallengeSigner::PURPOSE_LOGIN, self::SESSION, null, 300);
        $claims = $signer->verify($issued->token, WebAuthnChallengeSigner::PURPOSE_LOGIN, self::SESSION);

        self::assertNull($claims->userId);
    }

    /**
     * A token minted for register does not verify as a login challenge.
     *
     * @throws RandomException When the CSPRNG cannot produce a challenge
     */
    public function testChallengeBoundToAnotherPurposeIsRejected(): void
    {
        $signer = new WebAuthnChallengeSigner(self::SECRET);
        $issued = $signer->issue(WebAuthnChallengeSigner::PURPOSE_REGISTER, self::SESSION, 42, 300);

        $this->expectException(WebAuthnChallengeException::class);
        $signer->verify($issued->token, WebAuthnChallengeSigner::PURPOSE_LOGIN, self::SESSION);
    }

    /**
     * A challenge bound to one session does not verify for another.
     *
     * @throws RandomException When the CSPRNG cannot produce a challenge
     */
    public function testChallengeBoundToAnotherSessionIsRejected(): void
    {
        $signer = new WebAuthnChallengeSigner(self::SECRET);
        $issued = $signer->issue(WebAuthnChallengeSigner::PURPOSE_LOGIN, self::SESSION, null, 300);

        $this->expectException(WebAuthnChallengeException::class);
        $signer->verify($issued->token, WebAuthnChallengeSigner::PURPOSE_LOGIN, 'a-different-session');
    }

    /**
     * A flipped signature byte is rejected.
     *
     * @throws RandomException When the CSPRNG cannot produce a challenge
     */
    public function testTamperedSignatureIsRejected(): void
    {
        $signer = new WebAuthnChallengeSigner(self::SECRET);
        $issued = $signer->issue(WebAuthnChallengeSigner::PURPOSE_LOGIN, self::SESSION, null, 300);

        $tampered = substr($issued->token, 0, -1) . ($issued->token[-1] === 'A' ? 'B' : 'A');

        $this->expectException(WebAuthnChallengeException::class);
        $signer->verify($tampered, WebAuthnChallengeSigner::PURPOSE_LOGIN, self::SESSION);
    }

    /**
     * A challenge whose expiry is in the past is rejected.
     *
     * @throws RandomException When the CSPRNG cannot produce a challenge
     */
    public function testExpiredChallengeIsRejected(): void
    {
        $signer = new WebAuthnChallengeSigner(self::SECRET);
        $issued = $signer->issue(WebAuthnChallengeSigner::PURPOSE_LOGIN, self::SESSION, null, -10);

        $this->expectException(WebAuthnChallengeException::class);
        $signer->verify($issued->token, WebAuthnChallengeSigner::PURPOSE_LOGIN, self::SESSION);
    }

    /**
     * A structurally malformed token is rejected.
     */
    public function testMalformedTokenIsRejected(): void
    {
        $signer = new WebAuthnChallengeSigner(self::SECRET);

        $this->expectException(WebAuthnChallengeException::class);
        $signer->verify('no-separator-here', WebAuthnChallengeSigner::PURPOSE_LOGIN, self::SESSION);
    }
}
