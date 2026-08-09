<?php

declare(strict_types=1);

namespace Hilos\Auth\WebAuthn;

use CBOR\Decoder;
use CBOR\MapObject;
use CBOR\StringStream;
use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;
use Throwable;

/**
 * Verifies a WebAuthn registration (attestation) ceremony (HIL-284).
 *
 * The register half of the passkey subsystem: given the challenge recovered from
 * the signed token plus the clientDataJSON and attestationObject the browser
 * returned, it checks the client data binding, CBOR-decodes the attestation
 * object, validates authenticatorData (RP-id hash, user-present, and
 * user-verified when policy requires it, attested-credential-data present), and
 * extracts the credential id, ES256 public key and signature counter into an
 * {@see AttestationResult}. Attestation is requested as `none`, so the
 * attestation statement is not trust-evaluated — only the credential material is
 * bound and captured. A pure verifier: no DB, no session, no I/O.
 */
final class AttestationVerifier
{
    private const string ATTESTATION_KEY_AUTH_DATA = 'authData';

    /**
     * The 8-4-4-4-12 hex groups of a canonical UUID, written once as an unpack
     * format instead of as a run of offsets and lengths whose digits repeat.
     */
    private const string AAGUID_UNPACK_FORMAT = 'H8timeLow/H4timeMid/H4timeHigh/H4clockSeq/H12node';

    /**
     * @param WebAuthnConfig $config Resolved WebAuthn configuration
     */
    public function __construct(
        private readonly WebAuthnConfig $config,
    ) {
    }

    /**
     * Verifies a registration ceremony and returns the credential material to store.
     *
     * @param string $expectedChallenge base64url challenge recovered from the signed token
     * @param string $clientDataJson Raw clientDataJSON bytes returned by the client
     * @param string $attestationObject Raw CBOR attestationObject bytes returned by the client
     * @return AttestationResult The credential id, public key, sign counter and AAGUID to persist
     * @throws WebAuthnVerificationException When the client data, attestation object or authenticatorData fails any check
     */
    public function verify(string $expectedChallenge, string $clientDataJson, string $attestationObject): AttestationResult
    {
        ClientData::parse($clientDataJson)->assertValid($this->config, ClientData::TYPE_CREATE, $expectedChallenge);

        $authData = AuthenticatorData::parse($this->extractAuthData($attestationObject));

        if (!hash_equals($this->config->rpIdHash(), $authData->rpIdHash)) {
            throw new WebAuthnVerificationException('authenticatorData RP id hash does not match');
        }

        if (!$authData->userPresent) {
            throw new WebAuthnVerificationException('User-present flag is not set');
        }

        if ($this->config->requiresUserVerification() && !$authData->userVerified) {
            throw new WebAuthnVerificationException('User verification is required but was not performed');
        }

        if ($authData->credentialId === null || $authData->credentialPublicKeyCbor === null || $authData->aaguid === null) {
            throw new WebAuthnVerificationException('Attested credential data is missing');
        }

        $publicKeyPem = CoseKey::fromCbor($authData->credentialPublicKeyCbor)->toPem();

        return new AttestationResult(
            Base64Url::encode($authData->credentialId),
            $publicKeyPem,
            $authData->signCount,
            $this->formatAaguid($authData->aaguid),
        );
    }

    /**
     * CBOR-decodes the attestation object and returns its raw authenticatorData bytes.
     *
     * @param string $attestationObject Raw CBOR attestationObject bytes
     * @return string Raw authenticatorData bytes
     * @throws WebAuthnVerificationException When the object is not valid CBOR or lacks authData
     */
    private function extractAuthData(string $attestationObject): string
    {
        try {
            $object = Decoder::create()->decode(new StringStream($attestationObject));
        } catch (Throwable $e) {
            throw new WebAuthnVerificationException('attestationObject is not valid CBOR', 0, $e);
        }

        if (!$object instanceof MapObject) {
            throw new WebAuthnVerificationException('attestationObject is not a CBOR map');
        }

        $authData = $object->normalize()[self::ATTESTATION_KEY_AUTH_DATA] ?? null;
        if (!is_string($authData) || $authData === '') {
            throw new WebAuthnVerificationException('attestationObject is missing authData');
        }

        return $authData;
    }

    /**
     * Formats 16 raw AAGUID bytes as a canonical hyphenated UUID string.
     *
     * @param string $aaguid 16 raw AAGUID bytes
     * @return string Canonical UUID (8-4-4-4-12 lowercase hex)
     * @throws WebAuthnVerificationException When the AAGUID is shorter than the five groups
     */
    private function formatAaguid(string $aaguid): string
    {
        $groups = unpack(self::AAGUID_UNPACK_FORMAT, $aaguid);
        if ($groups === false) {
            throw new WebAuthnVerificationException('AAGUID is not 16 bytes');
        }

        return implode('-', $groups);
    }
}
