<?php

declare(strict_types=1);

namespace Hilos\Auth\WebAuthn;

/**
 * PasskeyAlgorithm - the signature suites a passkey ceremony asks for (HIL-658).
 *
 * Declared once and read by both halves of the subsystem: the register ceremony
 * offers the whole set as `pubKeyCredParams`, and {@see CoseKey} accepts only a
 * credential whose COSE `alg` is one of them. Case order is the RP's order of
 * preference, so an authenticator that speaks both enrolls with {@see Es256}.
 *
 * {@see Rs256} is here because a Windows Hello platform authenticator offers
 * nothing else, and a browser that finds no match in the set drops the device out
 * of the OS picker entirely — an offer of ES256 alone is what made the desktop
 * path unreachable rather than merely awkward.
 *
 * The case value is the COSE algorithm identifier itself, which is also what the
 * credential row stores: an RSA key cannot be told apart by its material alone
 * (PKCS1 and PSS share one key shape), so the algorithm has to be remembered
 * rather than re-derived at login.
 */
enum PasskeyAlgorithm: int
{
    /** ECDSA over NIST P-256 with SHA-256 — what every phone and security key speaks. */
    case Es256 = -7;

    /** RSASSA-PKCS1-v1_5 with SHA-256 — what a Windows Hello TPM offers. */
    case Rs256 = -257;

    /** COSE key type (`kty`) of an elliptic-curve key with x/y coordinates. */
    private const int COSE_KEY_TYPE_EC2 = 2;

    /** COSE key type (`kty`) of an RSA key with modulus and exponent. */
    private const int COSE_KEY_TYPE_RSA = 3;

    /** The one `PublicKeyCredentialType` WebAuthn defines. */
    private const string CREDENTIAL_TYPE = 'public-key';

    /**
     * @return list<array{type: string, alg: int}> `pubKeyCredParams` wire shape, in the RP's order of preference
     */
    public static function credentialParameters(): array
    {
        return array_map(
            static fn(self $algorithm): array => ['type' => self::CREDENTIAL_TYPE, 'alg' => $algorithm->value],
            self::cases(),
        );
    }

    /**
     * @return string The whole set spelled out for a refusal message, e.g. `ES256, RS256`
     */
    public static function declaredLabels(): string
    {
        return implode(', ', array_map(static fn(self $algorithm): string => $algorithm->label(), self::cases()));
    }

    /**
     * @return int COSE `kty` the algorithm's key material has to carry
     */
    public function coseKeyType(): int
    {
        return match ($this) {
            self::Es256 => self::COSE_KEY_TYPE_EC2,
            self::Rs256 => self::COSE_KEY_TYPE_RSA,
        };
    }

    /**
     * @return int OpenSSL key type a loaded PEM of this algorithm has to report
     */
    public function opensslKeyType(): int
    {
        return match ($this) {
            self::Es256 => OPENSSL_KEYTYPE_EC,
            self::Rs256 => OPENSSL_KEYTYPE_RSA,
        };
    }

    /**
     * @return string The algorithm's registered name, as a refusal message spells it
     */
    public function label(): string
    {
        return match ($this) {
            self::Es256 => 'ES256',
            self::Rs256 => 'RS256',
        };
    }
}
