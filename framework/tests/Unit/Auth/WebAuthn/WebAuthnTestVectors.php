<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\WebAuthn;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use Hilos\Auth\WebAuthn\ClientData;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Builds real WebAuthn ceremony byte strings from a fresh key (HIL-284, HIL-658).
 *
 * A test-only fixture that lets the verifier suites exercise the crypto path
 * end-to-end with genuine openssl material instead of frozen blobs: it mints a key
 * of the asked-for {@see PasskeyAlgorithm}, exposes the matching COSE public key,
 * and assembles authenticatorData, attestationObject, clientDataJSON and a valid
 * signature exactly as a browser authenticator would.
 *
 * The signing path needs no branch of its own: `openssl_sign` with SHA-256 is
 * ECDSA on a P-256 key and RSASSA-PKCS1-v1_5 on an RSA one, which is exactly the
 * pair of schemes ES256 and RS256 name.
 */
final class WebAuthnTestVectors
{
    public const int FLAG_USER_PRESENT = 0x01;
    public const int FLAG_USER_VERIFIED = 0x04;
    public const int FLAG_ATTESTED_CREDENTIAL_DATA = 0x40;

    private const int COORDINATE_BYTES = 32;
    private const int RSA_KEY_BITS = 2048;

    private const int COSE_LABEL_KEY_TYPE = 1;
    private const int COSE_LABEL_ALGORITHM = 3;

    private const int COSE_KEY_TYPE_EC2 = 2;
    private const int COSE_KEY_TYPE_RSA = 3;

    private const int COSE_CURVE_P256 = 1;

    public readonly string $rpId;
    public readonly string $rpIdHash;
    public readonly string $publicKeyPem;
    public readonly PasskeyAlgorithm $algorithm;

    private readonly OpenSSLAsymmetricKey $privateKey;
    private readonly string $coseKeyCbor;

    public function __construct(string $rpId = 'localhost', PasskeyAlgorithm $algorithm = PasskeyAlgorithm::Es256)
    {
        $key = openssl_pkey_new(match ($algorithm) {
            PasskeyAlgorithm::Es256 => ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'],
            PasskeyAlgorithm::Rs256 => ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => self::RSA_KEY_BITS],
        });
        if ($key === false) {
            throw new RuntimeException('Unable to generate a test ' . $algorithm->label() . ' key');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new RuntimeException('Unable to read the test ' . $algorithm->label() . ' key details');
        }

        $this->rpId = $rpId;
        $this->rpIdHash = hash('sha256', $rpId, true);
        $this->algorithm = $algorithm;
        $this->privateKey = $key;
        $this->publicKeyPem = $details['key'];
        $this->coseKeyCbor = match ($algorithm) {
            PasskeyAlgorithm::Es256 => self::ec2CoseKey($details),
            PasskeyAlgorithm::Rs256 => self::rsaCoseKey($details),
        };
    }

    /**
     * @return string CBOR-encoded COSE_Key of this fixture's algorithm
     */
    public function coseKeyCbor(): string
    {
        return $this->coseKeyCbor;
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
     * @return string Raw assertion signature of this fixture's algorithm
     */
    public function sign(string $authData, string $clientDataJson): string
    {
        openssl_sign($authData . hash('sha256', $clientDataJson, true), $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return $signature;
    }

    /**
     * @param array<string, mixed> $details openssl key details of a P-256 key
     * @return string CBOR-encoded ES256 P-256 COSE_Key
     */
    private static function ec2CoseKey(array $details): string
    {
        return (string)MapObject::create()
            ->add(UnsignedIntegerObject::create(self::COSE_LABEL_KEY_TYPE), UnsignedIntegerObject::create(self::COSE_KEY_TYPE_EC2))
            ->add(UnsignedIntegerObject::create(self::COSE_LABEL_ALGORITHM), NegativeIntegerObject::create(PasskeyAlgorithm::Es256->value))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(self::COSE_CURVE_P256))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(
                str_pad($details['ec']['x'], self::COORDINATE_BYTES, "\0", STR_PAD_LEFT),
            ))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(
                str_pad($details['ec']['y'], self::COORDINATE_BYTES, "\0", STR_PAD_LEFT),
            ));
    }

    /**
     * @param array<string, mixed> $details openssl key details of an RSA key
     * @return string CBOR-encoded RS256 COSE_Key
     */
    private static function rsaCoseKey(array $details): string
    {
        return (string)MapObject::create()
            ->add(UnsignedIntegerObject::create(self::COSE_LABEL_KEY_TYPE), UnsignedIntegerObject::create(self::COSE_KEY_TYPE_RSA))
            ->add(UnsignedIntegerObject::create(self::COSE_LABEL_ALGORITHM), NegativeIntegerObject::create(PasskeyAlgorithm::Rs256->value))
            ->add(NegativeIntegerObject::create(-1), ByteStringObject::create($details['rsa']['n']))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($details['rsa']['e']));
    }
}
