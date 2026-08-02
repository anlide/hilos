<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\WebAuthn;

use Hilos\Auth\WebAuthn\AssertionVerifier;
use Hilos\Auth\WebAuthn\ClientData;
use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;
use Hilos\Auth\WebAuthn\WebAuthnConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the login (assertion) verifier (HIL-284).
 *
 * A genuine assertion verifies and reports the advanced counter; an invalid
 * signature, a non-advancing counter (clone), a wrong ceremony type, or a missing
 * required user-verification flag are each rejected.
 */
final class AssertionVerifierTest extends TestCase
{
    private const string CHALLENGE = 'assertion-challenge-value';
    private const string ORIGIN = 'http://localhost';

    /**
     * A valid assertion verifies and returns the authenticator's new counter.
     *
     * @throws WebAuthnVerificationException Never in the success path
     */
    public function testValidAssertionReturnsNewSignCount(): void
    {
        $vectors = new WebAuthnTestVectors();
        $authData = $vectors->authenticatorData(WebAuthnTestVectors::FLAG_USER_PRESENT | WebAuthnTestVectors::FLAG_USER_VERIFIED, 10);
        $clientDataJson = $vectors->clientDataJson(self::CHALLENGE, self::ORIGIN, ClientData::TYPE_GET);
        $signature = $vectors->sign($authData, $clientDataJson);

        $newCount = new AssertionVerifier($this->config())->verify($vectors->publicKeyPem, 5, self::CHALLENGE, $clientDataJson, $authData, $signature);

        self::assertSame(10, $newCount);
    }

    /**
     * An assertion whose signature does not verify is rejected.
     */
    public function testInvalidSignatureIsRejected(): void
    {
        $vectors = new WebAuthnTestVectors();
        $authData = $vectors->authenticatorData(WebAuthnTestVectors::FLAG_USER_PRESENT | WebAuthnTestVectors::FLAG_USER_VERIFIED, 10);
        $clientDataJson = $vectors->clientDataJson(self::CHALLENGE, self::ORIGIN, ClientData::TYPE_GET);

        $this->expectException(WebAuthnVerificationException::class);
        new AssertionVerifier($this->config())->verify($vectors->publicKeyPem, 5, self::CHALLENGE, $clientDataJson, $authData, 'not-a-valid-signature');
    }

    /**
     * A counter that does not advance past the stored one is rejected as a clone.
     */
    public function testNonAdvancingCounterIsRejected(): void
    {
        $vectors = new WebAuthnTestVectors();
        $authData = $vectors->authenticatorData(WebAuthnTestVectors::FLAG_USER_PRESENT | WebAuthnTestVectors::FLAG_USER_VERIFIED, 10);
        $clientDataJson = $vectors->clientDataJson(self::CHALLENGE, self::ORIGIN, ClientData::TYPE_GET);
        $signature = $vectors->sign($authData, $clientDataJson);

        $this->expectException(WebAuthnVerificationException::class);
        new AssertionVerifier($this->config())->verify($vectors->publicKeyPem, 10, self::CHALLENGE, $clientDataJson, $authData, $signature);
    }

    /**
     * A create-type clientDataJSON is rejected for a login assertion.
     */
    public function testWrongCeremonyTypeIsRejected(): void
    {
        $vectors = new WebAuthnTestVectors();
        $authData = $vectors->authenticatorData(WebAuthnTestVectors::FLAG_USER_PRESENT | WebAuthnTestVectors::FLAG_USER_VERIFIED, 10);
        $clientDataJson = $vectors->clientDataJson(self::CHALLENGE, self::ORIGIN, ClientData::TYPE_CREATE);
        $signature = $vectors->sign($authData, $clientDataJson);

        $this->expectException(WebAuthnVerificationException::class);
        new AssertionVerifier($this->config())->verify($vectors->publicKeyPem, 5, self::CHALLENGE, $clientDataJson, $authData, $signature);
    }

    /**
     * When policy requires user verification, an assertion without the UV flag is rejected.
     */
    public function testRequiredUserVerificationMissingIsRejected(): void
    {
        $vectors = new WebAuthnTestVectors();
        $authData = $vectors->authenticatorData(WebAuthnTestVectors::FLAG_USER_PRESENT, 10);
        $clientDataJson = $vectors->clientDataJson(self::CHALLENGE, self::ORIGIN, ClientData::TYPE_GET);
        $signature = $vectors->sign($authData, $clientDataJson);

        $config = $this->config(userVerification: WebAuthnConfig::USER_VERIFICATION_REQUIRED);

        $this->expectException(WebAuthnVerificationException::class);
        new AssertionVerifier($config)->verify($vectors->publicKeyPem, 5, self::CHALLENGE, $clientDataJson, $authData, $signature);
    }

    /**
     * @param string $userVerification UV level
     * @return WebAuthnConfig Config under test
     */
    private function config(string $userVerification = WebAuthnConfig::USER_VERIFICATION_PREFERRED): WebAuthnConfig
    {
        return new WebAuthnConfig('localhost', 'Hilos', [self::ORIGIN], 300, $userVerification, 60000, 'secret');
    }
}
