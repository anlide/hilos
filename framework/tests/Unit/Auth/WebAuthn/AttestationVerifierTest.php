<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\WebAuthn;

use Hilos\Auth\WebAuthn\AttestationVerifier;
use Hilos\Auth\WebAuthn\Base64Url;
use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Auth\WebAuthn\WebAuthnConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the registration (attestation) verifier (HIL-284, HIL-658).
 *
 * A genuine register ceremony yields the credential material to store, including
 * the algorithm the authenticator enrolled under; a ceremony whose challenge,
 * origin, RP-id hash or user-present flag is wrong is rejected. Every scenario
 * runs once per declared algorithm, because the ceremony now offers a set rather
 * than a single suite and the checks around the key must not care which one came
 * back.
 */
final class AttestationVerifierTest extends TestCase
{
    private const string CHALLENGE = 'attestation-challenge-value';
    private const string ORIGIN = 'http://localhost';

    /**
     * @return list<array{PasskeyAlgorithm}> Every algorithm the ceremony offers
     */
    public static function declaredAlgorithms(): array
    {
        return array_map(static fn(PasskeyAlgorithm $algorithm): array => [$algorithm], PasskeyAlgorithm::cases());
    }

    /**
     * A valid registration returns the credential id, key, algorithm, counter and AAGUID.
     *
     * @param PasskeyAlgorithm $algorithm Algorithm the authenticator signs with
     * @throws WebAuthnVerificationException Never in the success path
     */
    #[DataProvider('declaredAlgorithms')]
    public function testValidRegistrationYieldsCredentialMaterial(PasskeyAlgorithm $algorithm): void
    {
        $vectors = new WebAuthnTestVectors(algorithm: $algorithm);
        $credentialId = random_bytes(20);
        $aaguid = hex2bin('0102030405060708090a0b0c0d0e0f10');

        $authData = $vectors->authenticatorData(
            WebAuthnTestVectors::FLAG_USER_PRESENT | WebAuthnTestVectors::FLAG_USER_VERIFIED | WebAuthnTestVectors::FLAG_ATTESTED_CREDENTIAL_DATA,
            7,
            $vectors->attestedCredentialData($credentialId, $aaguid),
        );
        $clientDataJson = $vectors->clientDataJson(self::CHALLENGE, self::ORIGIN);

        $result = new AttestationVerifier($this->config())->verify(self::CHALLENGE, $clientDataJson, $vectors->attestationObject($authData));

        self::assertSame(Base64Url::encode($credentialId), $result->credentialId);
        self::assertSame($algorithm, $result->algorithm);
        self::assertSame(7, $result->signCount);
        self::assertSame('01020304-0506-0708-090a-0b0c0d0e0f10', $result->aaguid);
        self::assertNotFalse(openssl_pkey_get_public($result->publicKeyPem));
    }

    /**
     * A clientDataJSON echoing a different challenge is rejected.
     *
     * @param PasskeyAlgorithm $algorithm Algorithm the authenticator signs with
     */
    #[DataProvider('declaredAlgorithms')]
    public function testWrongChallengeIsRejected(PasskeyAlgorithm $algorithm): void
    {
        $vectors = new WebAuthnTestVectors(algorithm: $algorithm);
        $authData = $this->registrationAuthData($vectors);
        $clientDataJson = $vectors->clientDataJson('a-different-challenge', self::ORIGIN);

        $this->expectException(WebAuthnVerificationException::class);
        new AttestationVerifier($this->config())->verify(self::CHALLENGE, $clientDataJson, $vectors->attestationObject($authData));
    }

    /**
     * A ceremony from a non-allowed origin is rejected.
     *
     * @param PasskeyAlgorithm $algorithm Algorithm the authenticator signs with
     */
    #[DataProvider('declaredAlgorithms')]
    public function testDisallowedOriginIsRejected(PasskeyAlgorithm $algorithm): void
    {
        $vectors = new WebAuthnTestVectors(algorithm: $algorithm);
        $authData = $this->registrationAuthData($vectors);
        $clientDataJson = $vectors->clientDataJson(self::CHALLENGE, 'http://evil.example');

        $this->expectException(WebAuthnVerificationException::class);
        new AttestationVerifier($this->config())->verify(self::CHALLENGE, $clientDataJson, $vectors->attestationObject($authData));
    }

    /**
     * authenticatorData scoped to a different RP id is rejected.
     *
     * @param PasskeyAlgorithm $algorithm Algorithm the authenticator signs with
     */
    #[DataProvider('declaredAlgorithms')]
    public function testWrongRpIdHashIsRejected(PasskeyAlgorithm $algorithm): void
    {
        $vectors = new WebAuthnTestVectors('localhost', $algorithm);
        $authData = $this->registrationAuthData($vectors);
        $clientDataJson = $vectors->clientDataJson(self::CHALLENGE, self::ORIGIN);

        $config = $this->config(rpId: 'other.example');

        $this->expectException(WebAuthnVerificationException::class);
        new AttestationVerifier($config)->verify(self::CHALLENGE, $clientDataJson, $vectors->attestationObject($authData));
    }

    /**
     * A ceremony without the user-present flag is rejected.
     *
     * @param PasskeyAlgorithm $algorithm Algorithm the authenticator signs with
     */
    #[DataProvider('declaredAlgorithms')]
    public function testMissingUserPresenceIsRejected(PasskeyAlgorithm $algorithm): void
    {
        $vectors = new WebAuthnTestVectors(algorithm: $algorithm);
        $authData = $vectors->authenticatorData(
            WebAuthnTestVectors::FLAG_ATTESTED_CREDENTIAL_DATA,
            1,
            $vectors->attestedCredentialData(random_bytes(20), str_repeat("\0", 16)),
        );
        $clientDataJson = $vectors->clientDataJson(self::CHALLENGE, self::ORIGIN);

        $this->expectException(WebAuthnVerificationException::class);
        new AttestationVerifier($this->config())->verify(self::CHALLENGE, $clientDataJson, $vectors->attestationObject($authData));
    }

    /**
     * @param WebAuthnTestVectors $vectors Fixture
     * @return string Raw registration authenticatorData with UP|UV|AT set
     */
    private function registrationAuthData(WebAuthnTestVectors $vectors): string
    {
        return $vectors->authenticatorData(
            WebAuthnTestVectors::FLAG_USER_PRESENT | WebAuthnTestVectors::FLAG_USER_VERIFIED | WebAuthnTestVectors::FLAG_ATTESTED_CREDENTIAL_DATA,
            1,
            $vectors->attestedCredentialData(random_bytes(20), str_repeat("\0", 16)),
        );
    }

    /**
     * @param string $rpId RP id
     * @return WebAuthnConfig Config under test
     */
    private function config(string $rpId = 'localhost'): WebAuthnConfig
    {
        return new WebAuthnConfig($rpId, 'Hilos', [self::ORIGIN], 300, WebAuthnConfig::USER_VERIFICATION_PREFERRED, 60000, 'secret');
    }
}
