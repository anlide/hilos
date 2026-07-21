<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\OAuth\Exception\OAuthStateException;
use Hilos\Auth\OAuth\OAuthStateSigner;
use PHPUnit\Framework\TestCase;
use Random\RandomException;

/**
 * Unit tests for the stateless, signed OAuth state token (HIL-281).
 *
 * The CSRF guard: a state round-trips only for the session it was bound to and
 * only while unexpired, and any tamper or wrong secret is rejected — all without
 * persisting anything.
 */
final class OAuthStateSignerTest extends TestCase
{
    private const string SECRET = 'unit-test-signing-secret';
    private const string SESSION = 'session-token-abc123';

    /**
     * A state minted for a session verifies for that same session.
     *
     * @throws RandomException When the CSPRNG cannot produce a nonce
     * @throws OAuthStateException Never in the success path
     */
    public function testValidStateRoundTripsForBoundSession(): void
    {
        $signer = new OAuthStateSigner(self::SECRET);

        $state = $signer->issue(self::SESSION, 600);
        $signer->verify($state, self::SESSION);

        self::assertStringContainsString('.', $state);
    }

    /**
     * A flipped signature byte is rejected.
     *
     * @throws RandomException When the CSPRNG cannot produce a nonce
     */
    public function testTamperedSignatureIsRejected(): void
    {
        $signer = new OAuthStateSigner(self::SECRET);
        $state = $signer->issue(self::SESSION, 600);

        $tampered = substr($state, 0, -1) . ($state[-1] === 'A' ? 'B' : 'A');

        $this->expectException(OAuthStateException::class);
        $signer->verify($tampered, self::SESSION);
    }

    /**
     * A state bound to one session does not verify for another.
     *
     * @throws RandomException When the CSPRNG cannot produce a nonce
     */
    public function testStateBoundToAnotherSessionIsRejected(): void
    {
        $signer = new OAuthStateSigner(self::SECRET);
        $state = $signer->issue(self::SESSION, 600);

        $this->expectException(OAuthStateException::class);
        $signer->verify($state, 'a-different-session');
    }

    /**
     * A state whose expiry is in the past is rejected.
     *
     * @throws RandomException When the CSPRNG cannot produce a nonce
     */
    public function testExpiredStateIsRejected(): void
    {
        $signer = new OAuthStateSigner(self::SECRET);
        $state = $signer->issue(self::SESSION, -10);

        $this->expectException(OAuthStateException::class);
        $signer->verify($state, self::SESSION);
    }

    /**
     * A structurally malformed state is rejected.
     */
    public function testMalformedStateIsRejected(): void
    {
        $signer = new OAuthStateSigner(self::SECRET);

        $this->expectException(OAuthStateException::class);
        $signer->verify('not-a-valid-state', self::SESSION);
    }

    /**
     * A state signed with one secret does not verify under another.
     *
     * @throws RandomException When the CSPRNG cannot produce a nonce
     */
    public function testStateSignedWithAnotherSecretIsRejected(): void
    {
        $state = (new OAuthStateSigner('secret-a'))->issue(self::SESSION, 600);

        $this->expectException(OAuthStateException::class);
        (new OAuthStateSigner('secret-b'))->verify($state, self::SESSION);
    }
}
