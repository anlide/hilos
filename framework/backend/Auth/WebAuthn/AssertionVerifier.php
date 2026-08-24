<?php

declare(strict_types=1);

namespace Hilos\Auth\WebAuthn;

use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;

/**
 * Verifies a WebAuthn login (assertion) ceremony (HIL-284).
 *
 * The login half of the passkey subsystem: given a stored credential's PEM public
 * key and last signature counter plus the challenge recovered from the signed
 * token and the assertion the browser returned, it checks the client-data
 * binding and authenticatorData (RP-id hash, user-present, and user-verified when
 * policy requires it), verifies the signature over
 * `authenticatorData || SHA-256(clientDataJSON)`, and enforces the monotonic
 * signature counter to catch a cloned authenticator. It returns the new counter
 * for the caller to persist. A pure verifier: no DB, no session, no I/O.
 *
 * The credential's {@see PasskeyAlgorithm} comes in alongside its key rather than
 * being read off the PEM: openssl picks the signature scheme from the key type on
 * its own, but only the stored algorithm can say the row still holds the key it
 * was enrolled with (HIL-658).
 */
final class AssertionVerifier
{
    private const int OPENSSL_VERIFY_SUCCESS = 1;

    /**
     * @param WebAuthnConfig $config Resolved WebAuthn configuration
     */
    public function __construct(
        private readonly WebAuthnConfig $config,
    ) {
    }

    /**
     * Verifies an assertion and returns the new signature counter to persist.
     *
     * @param string $publicKeyPem Stored PEM credential public key
     * @param PasskeyAlgorithm $algorithm Signature suite recorded on the credential row
     * @param int $storedSignCount Last persisted signature counter for the credential
     * @param string $expectedChallenge base64url challenge recovered from the signed token
     * @param string $clientDataJson Raw clientDataJSON bytes returned by the client
     * @param string $authenticatorData Raw authenticatorData bytes returned by the client
     * @param string $signature Raw signature bytes returned by the client
     * @return int The authenticator's new signature counter
     * @throws WebAuthnVerificationException When any client-data, authenticatorData, key-type, signature or counter check fails
     */
    public function verify(
        string $publicKeyPem,
        PasskeyAlgorithm $algorithm,
        int $storedSignCount,
        string $expectedChallenge,
        string $clientDataJson,
        string $authenticatorData,
        string $signature,
    ): int {
        ClientData::parse($clientDataJson)->assertValid($this->config, ClientData::TYPE_GET, $expectedChallenge);

        $authData = AuthenticatorData::parse($authenticatorData);

        if (!hash_equals($this->config->rpIdHash(), $authData->rpIdHash)) {
            throw new WebAuthnVerificationException('authenticatorData RP id hash does not match');
        }

        if (!$authData->userPresent) {
            throw new WebAuthnVerificationException('User-present flag is not set');
        }

        if ($this->config->requiresUserVerification() && !$authData->userVerified) {
            throw new WebAuthnVerificationException('User verification is required but was not performed');
        }

        $this->verifySignature($publicKeyPem, $algorithm, $authenticatorData, $clientDataJson, $signature);
        $this->assertCounterAdvances($storedSignCount, $authData->signCount);

        return $authData->signCount;
    }

    /**
     * Verifies the signature over authenticatorData concatenated with the client-data hash.
     *
     * The loaded key is first held against the algorithm the credential row records.
     * openssl reads the scheme off the key type — ECDSA for an EC key,
     * RSASSA-PKCS1-v1_5 for an RSA one — so a row whose key and algorithm have
     * drifted apart would otherwise be verified under a scheme nobody enrolled it
     * with, silently.
     *
     * @param string $publicKeyPem Stored PEM credential public key
     * @param PasskeyAlgorithm $algorithm Signature suite recorded on the credential row
     * @param string $authenticatorData Raw authenticatorData bytes
     * @param string $clientDataJson Raw clientDataJSON bytes
     * @param string $signature Raw signature bytes
     * @throws WebAuthnVerificationException When the key cannot be loaded, contradicts the algorithm, or the signature does not verify
     */
    private function verifySignature(
        string $publicKeyPem,
        PasskeyAlgorithm $algorithm,
        string $authenticatorData,
        string $clientDataJson,
        string $signature,
    ): void {
        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            throw new WebAuthnVerificationException('Stored credential public key could not be loaded');
        }

        $details = openssl_pkey_get_details($publicKey);
        if ($details === false) {
            throw new WebAuthnVerificationException('Stored credential public key could not be read');
        }

        if ($details['type'] !== $algorithm->opensslKeyType()) {
            throw new WebAuthnVerificationException('Stored credential key type does not match its algorithm');
        }

        $signedData = $authenticatorData . hash('sha256', $clientDataJson, true);
        $result = openssl_verify($signedData, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($result !== self::OPENSSL_VERIFY_SUCCESS) {
            throw new WebAuthnVerificationException('Assertion signature is invalid');
        }
    }

    /**
     * Enforces the WebAuthn signature-counter rule to detect a cloned authenticator.
     *
     * When either the stored or the presented counter is non-zero, the presented
     * counter must be strictly greater than the stored one; a 0/0 pair is accepted
     * (authenticators that never increment, e.g. synced passkeys).
     *
     * @param int $storedSignCount Last persisted signature counter
     * @param int $presentedSignCount Counter reported in this assertion
     * @throws WebAuthnVerificationException When the counter did not advance (possible clone)
     */
    private function assertCounterAdvances(int $storedSignCount, int $presentedSignCount): void
    {
        if (($storedSignCount !== 0 || $presentedSignCount !== 0) && $presentedSignCount <= $storedSignCount) {
            throw new WebAuthnVerificationException('Signature counter did not advance (possible cloned authenticator)');
        }
    }
}
