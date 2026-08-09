<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\WebAuthn;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use Hilos\Auth\WebAuthn\ClientData;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Builds real WebAuthn ceremony byte strings from a fresh EC P-256 key (HIL-284).
 *
 * A test-only fixture that lets the verifier suites exercise the crypto path
 * end-to-end with genuine openssl material instead of frozen blobs: it mints a
 * P-256 key, exposes the COSE public key, and assembles authenticatorData,
 * attestationObject, clientDataJSON and a valid ES256 signature exactly as a
 * browser authenticator would.
 */
final class WebAuthnTestVectors
{
    public const int FLAG_USER_PRESENT = 0x01;
    public const int FLAG_USER_VERIFIED = 0x04;
    public const int FLAG_ATTESTED_CREDENTIAL_DATA = 0x40;

    private const int COORDINATE_BYTES = 32;

    public readonly string $rpId;
    public readonly string $rpIdHash;
    public readonly string $publicKeyPem;

    private readonly OpenSSLAsymmetricKey $privateKey;
    private readonly string $x;
    private readonly string $y;

    public function __construct(string $rpId = 'localhost')
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new RuntimeException('Unable to generate a test EC key');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new RuntimeException('Unable to read the test EC key details');
        }

        $this->rpId = $rpId;
        $this->rpIdHash = hash('sha256', $rpId, true);
        $this->privateKey = $key;
        $this->publicKeyPem = $details['key'];
        $this->x = str_pad($details['ec']['x'], self::COORDINATE_BYTES, "\0", STR_PAD_LEFT);
        $this->y = str_pad($details['ec']['y'], self::COORDINATE_BYTES, "\0", STR_PAD_LEFT);
    }

    /**
     * @return string CBOR-encoded ES256 P-256 COSE_Key for this key
     */
    public function coseKeyCbor(): string
    {
        return (string)MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($this->x))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($this->y));
    }

    /**
     * @param int $flags authenticatorData flags byte
     * @param int $signCount Signature counter
     * @param ?string $attestedCredentialData Attested credential data to append, or null
     * @return string Raw authenticatorData bytes
     */
    public function authenticatorData(int $flags, int $signCount, ?string $attestedCredentialData = null): string
    {
        // external-boundary: authenticatorData legitimately ends after the sign counter, appending nothing
        return $this->rpIdHash . chr($flags) . pack('N', $signCount) . ($attestedCredentialData ?? '');
    }

    /**
     * @param string $credentialId Raw credential id bytes
     * @param string $aaguid 16 raw AAGUID bytes
     * @return string Raw attested credential data (AAGUID | id-length | id | COSE key)
     */
    public function attestedCredentialData(string $credentialId, string $aaguid): string
    {
        return $aaguid . pack('n', strlen($credentialId)) . $credentialId . $this->coseKeyCbor();
    }

    /**
     * @param string $authData Raw authenticatorData bytes to wrap
     * @return string CBOR-encoded attestationObject with fmt=none
     */
    public function attestationObject(string $authData): string
    {
        return (string)MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));
    }

    /**
     * @param string $challenge base64url challenge to echo
     * @param string $origin Ceremony origin
     * @param string $type Ceremony type (defaults to the create type)
     * @return string clientDataJSON bytes
     */
    public function clientDataJson(string $challenge, string $origin, string $type = ClientData::TYPE_CREATE): string
    {
        return (string)json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => $origin,
            'crossOrigin' => false,
        ]);
    }

    /**
     * @param string $authData Raw authenticatorData bytes
     * @param string $clientDataJson clientDataJSON bytes
     * @return string Raw ES256 (DER) assertion signature
     */
    public function sign(string $authData, string $clientDataJson): string
    {
        openssl_sign($authData . hash('sha256', $clientDataJson, true), $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return $signature;
    }
}
