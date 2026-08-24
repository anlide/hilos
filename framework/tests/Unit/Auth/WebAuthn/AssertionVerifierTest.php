<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\WebAuthn;

use Hilos\Auth\WebAuthn\AssertionVerifier;
use Hilos\Auth\WebAuthn\ClientData;
use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Auth\WebAuthn\WebAuthnConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the login (assertion) verifier (HIL-284, HIL-658).
 *
 * A genuine assertion verifies and reports the advanced counter; an invalid
 * signature, a non-advancing counter (clone), a wrong ceremony type, a missing
 * required user-verification flag, or a stored key that contradicts the algorithm
 * recorded beside it are each rejected. Every scenario runs once per declared
 * algorithm: openssl picks the signature scheme off the key type, so a suite that
 * is never exercised is a suite nobody has checked.
 */
final class AssertionVerifierTest extends TestCase
{
    private const string CHALLENGE = 'assertion-challenge-value';
    private const string ORIGIN = 'http://localhost';

    /**
     * @return list<array{PasskeyAlgorithm}> Every algorithm the ceremony offers
     */
    public static function declaredAlgorithms(): array
    {
        return array_map(static fn(PasskeyAlgorithm $algorithm): array => [$algorithm], PasskeyAlgorithm::cases());
    }

    /**
     * A valid assertion verifies and returns the authenticator's new counter.
     *
     * @param PasskeyAlgorithm $algorithm Algorithm the credential was enrolled with
     * @throws WebAuthnVerificationException Never in the success path
     */
    #[DataProvider('declaredAlgorithms')]
    public function testValidAssertionReturnsNewSignCount(PasskeyAlgorithm $algorithm): void
    {
        $vectors = new WebAuthnTestVectors(algorithm: $algorithm);
        $authData = $vectors->authenticatorData(WebAuthnTestVectors::FLAG_USER_PRESENT | WebAuthnTestVectors::FLAG_USER_VERIFIED, 10);
        $clientDataJson = $vectors->clientDataJson(self::CHALLENGE, self::ORIGIN, ClientData::TYPE_GET);
        $signature = $vectors->sign($authData, $clientDataJson);

        $newCount = new AssertionVerifier($this->config())
            ->verify($vectors->publicKeyPem, $algorithm, 5, self::CHALLENGE, $clientDataJson, $authData, $signature);

        self::assertSame(10, $newCount);
    }

    /**
     * A stored key whose type contradicts the algorithm recorded beside it is rejected.
     *
     * @param PasskeyAlgorithm $algorithm Algorithm the credential was enrolled with
     */
    #[DataProvider('declaredAlgorithms')]
    public function testKeyContradictingItsAlgorithmIsRejected(PasskeyAlgorithm $algorithm): void
    {
        $vectors = new WebAuthnTestVectors(algorithm: $algorithm);
        $authData = $vectors->authenticatorData(WebAuthnTestVectors::FLAG_USER_PRESENT | WebAuthnTestVectors::FLAG_USER_VERIFIED, 10);
        $clientDataJson = $vectors->clientDataJson(self::CHALLENGE, self::ORIGIN, ClientData::TYPE_GET);
        $signature = $vectors->sign($authData, $clientDataJson);

        $claimed = $algorithm === PasskeyAlgorithm::Es256 ? PasskeyAlgorithm::Rs256 : PasskeyAlgorithm::Es256;

        $this->expectException(WebAuthnVerificationException::class);
        $this->expectExceptionMessage('Stored credential key type does not match its algorithm');
        new AssertionVerifier($this->config())
            ->verify($vectors->publicKeyPem, $claimed, 5, self::CHALLENGE, $clientDataJson, $authData, $signature);
    }

    /**
     * An assertion whose signature does not verify is rejected.
     *
     * @param PasskeyAlgorithm $algorithm Algorithm the credential was enrolled with
     */
    #[DataProvider('declaredAlgorithms')]
    public function testInvalidSignatureIsRejected(PasskeyAlgorithm $algorithm): void
    {
        $vectors = new WebAuthnTestVectors(algorithm: $algorithm);
        $authData = $vectors->authenticatorData(WebAuthnTestVectors::FLAG_USER_PRESENT | WebAuthnTestVectors::FLAG_USER_VERIFIED, 10);
        $clientDataJson = $vectors->clientDataJson(self::CHALLENGE, self::ORIGIN, ClientData::TYPE_GET);

        $this->expectException(WebAuthnVerificationException::class);
        new AssertionVerifier($this->config())
            ->verify($vectors->publicKeyPem, $algorithm, 5, self::CHALLENGE, $clientDataJson, $authData, 'not-a-valid-signature');
    }

    /**
     * A counter that does not advance past the stored one is rejected as a clone.
     *
     * @param PasskeyAlgorithm $algorithm Algorithm the credential was enrolled with
     */
    #[DataProvider('declaredAlgorithms')]
    public function testNonAdvancingCounterIsRejected(PasskeyAlgorithm $algorithm): void
    {
        $vectors = new WebAuthnTestVectors(algorithm: $algorithm);
        $authData = $vectors->authenticatorData(WebAuthnTestVectors::FLAG_USER_PRESENT | WebAuthnTestVectors::FLAG_USER_VERIFIED, 10);
        $clientDataJson = $vectors->clientDataJson(self::CHALLENGE, self::ORIGIN, ClientData::TYPE_GET);
        $signature = $vectors->sign($authData, $clientDataJson);

        $this->expectException(WebAuthnVerificationException::class);
        new AssertionVerifier($this->config())
            ->verify($vectors->publicKeyPem, $algorithm, 10, self::CHALLENGE, $clientDataJson, $authData, $signature);
    }

    /**
     * A create-type clientDataJSON is rejected for a login assertion.
     *
     * @param PasskeyAlgorithm $algorithm Algorithm the credential was enrolled with
     */
    #[DataProvider('declaredAlgorithms')]
    public function testWrongCeremonyTypeIsRejected(PasskeyAlgorithm $algorithm): void
    {
        $vectors = new WebAuthnTestVectors(algorithm: $algorithm);
        $authData = $vectors->authenticatorData(WebAuthnTestVectors::FLAG_USER_PRESENT | WebAuthnTestVectors::FLAG_USER_VERIFIED, 10);
        $clientDataJson = $vectors->clientDataJson(self::CHALLENGE, self::ORIGIN, ClientData::TYPE_CREATE);
        $signature = $vectors->sign($authData, $clientDataJson);

        $this->expectException(WebAuthnVerificationException::class);
        new AssertionVerifier($this->config())
            ->verify($vectors->publicKeyPem, $algorithm, 5, self::CHALLENGE, $clientDataJson, $authData, $signature);
    }

    /**
     * When policy requires user verification, an assertion without the UV flag is rejected.
     *
     * @param PasskeyAlgorithm $algorithm Algorithm the credential was enrolled with
     */
    #[DataProvider('declaredAlgorithms')]
    public function testRequiredUserVerificationMissingIsRejected(PasskeyAlgorithm $algorithm): void
    {
        $vectors = new WebAuthnTestVectors(algorithm: $algorithm);
        $authData = $vectors->authenticatorData(WebAuthnTestVectors::FLAG_USER_PRESENT, 10);
        $clientDataJson = $vectors->clientDataJson(self::CHALLENGE, self::ORIGIN, ClientData::TYPE_GET);
        $signature = $vectors->sign($authData, $clientDataJson);

        $config = $this->config(userVerification: WebAuthnConfig::USER_VERIFICATION_REQUIRED);

        $this->expectException(WebAuthnVerificationException::class);
        new AssertionVerifier($config)->verify($vectors->publicKeyPem, $algorithm, 5, self::CHALLENGE, $clientDataJson, $authData, $signature);
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
