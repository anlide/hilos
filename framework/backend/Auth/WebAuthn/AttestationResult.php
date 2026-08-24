<?php

declare(strict_types=1);

namespace Hilos\Auth\WebAuthn;

/**
 * The stored credential material extracted from a verified registration (HIL-284).
 *
 * What {@see AttestationVerifier::verify()} yields once a register ceremony has
 * checked out: the base64url {@see $credentialId} that names the credential, the
 * PEM {@see $publicKeyPem} used to check future assertions together with the
 * {@see $algorithm} it signs with, the initial {@see $signCount} (the
 * clone-detection baseline), and the {@see $aaguid} model identifier. These map
 * directly onto the passkey credential row the caller persists; per-credential
 * transports are supplied separately by the client.
 *
 * The algorithm is carried rather than derived from the PEM because an RSA key
 * cannot say which scheme it was enrolled under (HIL-658).
 */
final readonly class AttestationResult
{
    /**
     * @param string $credentialId base64url credential id
     * @param string $publicKeyPem PEM-encoded credential public key
     * @param PasskeyAlgorithm $algorithm Signature suite the credential was enrolled with
     * @param int $signCount Initial signature counter reported at registration
     * @param string $aaguid Canonical UUID string of the authenticator model
     */
    public function __construct(
        public string $credentialId,
        public string $publicKeyPem,
        public PasskeyAlgorithm $algorithm,
        public int $signCount,
        public string $aaguid,
    ) {
    }
}
