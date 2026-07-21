<?php

declare(strict_types=1);

namespace Hilos\Auth\WebAuthn;

use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;

/**
 * The parsed authenticatorData structure common to both ceremonies (HIL-284).
 *
 * authenticatorData is the authenticator-signed byte string both attestation and
 * assertion carry: a 32-byte RP-id hash, a flags byte (user-present / user-verified
 * / attested-credential-data-included), a 32-bit signature counter, and — only on
 * registration, when the AT flag is set — the attested credential data (AAGUID,
 * credential id, and the CBOR COSE public key). {@see parse()} validates the fixed
 * layout and, when present, the attested-credential section; trailing extension
 * bytes (ED flag) are left for the COSE decoder to ignore.
 */
final class AuthenticatorData
{
    private const int FLAG_USER_PRESENT = 0x01;
    private const int FLAG_USER_VERIFIED = 0x04;
    private const int FLAG_ATTESTED_CREDENTIAL_DATA = 0x40;

    private const int RP_ID_HASH_LENGTH = 32;
    private const int FLAGS_LENGTH = 1;
    private const int SIGN_COUNT_LENGTH = 4;
    private const int AAGUID_LENGTH = 16;
    private const int CREDENTIAL_ID_LENGTH_FIELD = 2;

    private const int FIXED_HEADER_LENGTH = self::RP_ID_HASH_LENGTH + self::FLAGS_LENGTH + self::SIGN_COUNT_LENGTH;

    /**
     * @param string $rpIdHash 32-byte SHA-256 of the RP id the authenticator scoped the credential to
     * @param bool $userPresent Whether the user-present (UP) flag is set
     * @param bool $userVerified Whether the user-verified (UV) flag is set
     * @param int $signCount Authenticator signature counter
     * @param ?string $aaguid Authenticator AAGUID (16 raw bytes), or null when no attested credential data
     * @param ?string $credentialId Raw credential id bytes, or null when no attested credential data
     * @param ?string $credentialPublicKeyCbor Raw COSE_Key CBOR, or null when no attested credential data
     */
    private function __construct(
        public readonly string $rpIdHash,
        public readonly bool $userPresent,
        public readonly bool $userVerified,
        public readonly int $signCount,
        public readonly ?string $aaguid,
        public readonly ?string $credentialId,
        public readonly ?string $credentialPublicKeyCbor,
    ) {
    }

    /**
     * Parses raw authenticatorData bytes into its fields.
     *
     * @param string $raw Raw authenticatorData bytes
     * @return self Parsed structure
     * @throws WebAuthnVerificationException When the bytes are too short or the attested credential section is malformed
     */
    public static function parse(string $raw): self
    {
        if (strlen($raw) < self::FIXED_HEADER_LENGTH) {
            throw new WebAuthnVerificationException('authenticatorData is too short');
        }

        $rpIdHash = substr($raw, 0, self::RP_ID_HASH_LENGTH);
        $flags = ord($raw[self::RP_ID_HASH_LENGTH]);
        $signCountBytes = substr($raw, self::RP_ID_HASH_LENGTH + self::FLAGS_LENGTH, self::SIGN_COUNT_LENGTH);
        $signCount = (int)unpack('N', $signCountBytes)[1];

        $userPresent = ($flags & self::FLAG_USER_PRESENT) !== 0;
        $userVerified = ($flags & self::FLAG_USER_VERIFIED) !== 0;
        $hasAttestedCredential = ($flags & self::FLAG_ATTESTED_CREDENTIAL_DATA) !== 0;

        if (!$hasAttestedCredential) {
            return new self($rpIdHash, $userPresent, $userVerified, $signCount, null, null, null);
        }

        $offset = self::FIXED_HEADER_LENGTH;
        if (strlen($raw) < $offset + self::AAGUID_LENGTH + self::CREDENTIAL_ID_LENGTH_FIELD) {
            throw new WebAuthnVerificationException('Attested credential data is truncated');
        }

        $aaguid = substr($raw, $offset, self::AAGUID_LENGTH);
        $offset += self::AAGUID_LENGTH;

        $credentialIdLength = (int)unpack('n', substr($raw, $offset, self::CREDENTIAL_ID_LENGTH_FIELD))[1];
        $offset += self::CREDENTIAL_ID_LENGTH_FIELD;

        if (strlen($raw) < $offset + $credentialIdLength) {
            throw new WebAuthnVerificationException('Attested credential id is truncated');
        }

        $credentialId = substr($raw, $offset, $credentialIdLength);
        $offset += $credentialIdLength;

        $credentialPublicKeyCbor = substr($raw, $offset);
        if ($credentialPublicKeyCbor === '') {
            throw new WebAuthnVerificationException('Credential public key is missing');
        }

        return new self($rpIdHash, $userPresent, $userVerified, $signCount, $aaguid, $credentialId, $credentialPublicKeyCbor);
    }
}
