<?php

declare(strict_types=1);

namespace Hilos\Auth\WebAuthn;

use CBOR\Decoder;
use CBOR\MapObject;
use CBOR\StringStream;
use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;
use Throwable;

/**
 * A COSE_Key credential public key, parsed from attestation CBOR to PEM (HIL-284).
 *
 * WebAuthn ships the credential public key inside authenticatorData as a
 * CBOR-encoded COSE_Key map. This leaf supports the one algorithm the ceremony
 * requests — ES256 (COSE alg -7): an EC2 key on the NIST P-256 curve. Parsing
 * validates the key type / algorithm / curve and the 32-byte affine coordinates,
 * then {@see toPem()} wraps the uncompressed point in a fixed P-256
 * SubjectPublicKeyInfo so `openssl_pkey_get_public()` can load it at assertion
 * time. Anything that is not a well-formed ES256 P-256 key is rejected.
 */
final class CoseKey
{
    private const int COSE_KEY_TYPE = 1;
    private const int COSE_ALGORITHM = 3;
    private const int COSE_CURVE = -1;
    private const int COSE_X = -2;
    private const int COSE_Y = -3;

    private const int KEY_TYPE_EC2 = 2;
    private const int ALGORITHM_ES256 = -7;
    private const int CURVE_P256 = 1;
    private const int COORDINATE_BYTES = 32;

    /**
     * DER prefix of a P-256 SubjectPublicKeyInfo up to and including the leading
     * uncompressed-point marker (0x04); the 32-byte X and Y coordinates follow.
     */
    private const string P256_SPKI_PREFIX_HEX = '3059301306072a8648ce3d020106082a8648ce3d03010703420004';

    /**
     * @param string $x 32-byte P-256 affine x coordinate
     * @param string $y 32-byte P-256 affine y coordinate
     */
    private function __construct(
        private readonly string $x,
        private readonly string $y,
    ) {
    }

    /**
     * Parses a CBOR-encoded COSE_Key, accepting only an ES256 P-256 public key.
     *
     * @param string $cbor Raw CBOR bytes of the credential public key
     * @return self Validated key
     * @throws WebAuthnVerificationException When the bytes are not a well-formed ES256 P-256 COSE key
     */
    public static function fromCbor(string $cbor): self
    {
        try {
            $object = Decoder::create()->decode(new StringStream($cbor));
        } catch (Throwable $e) {
            throw new WebAuthnVerificationException('Credential public key is not valid CBOR', 0, $e);
        }

        if (!$object instanceof MapObject) {
            throw new WebAuthnVerificationException('Credential public key is not a COSE key map');
        }

        $map = $object->normalize();
        if ((int)($map[self::COSE_KEY_TYPE] ?? 0) !== self::KEY_TYPE_EC2) {
            throw new WebAuthnVerificationException('Unsupported credential key type (expected EC2)');
        }

        if ((int)($map[self::COSE_ALGORITHM] ?? 0) !== self::ALGORITHM_ES256) {
            throw new WebAuthnVerificationException('Unsupported credential algorithm (expected ES256)');
        }

        if ((int)($map[self::COSE_CURVE] ?? 0) !== self::CURVE_P256) {
            throw new WebAuthnVerificationException('Unsupported credential curve (expected P-256)');
        }

        // external-boundary: the CBOR map arrives from the browser and may carry no x coordinate
        $x = $map[self::COSE_X] ?? '';
        // external-boundary: the CBOR map arrives from the browser and may carry no y coordinate
        $y = $map[self::COSE_Y] ?? '';
        if (!is_string($x) || !is_string($y) || strlen($x) !== self::COORDINATE_BYTES || strlen($y) !== self::COORDINATE_BYTES) {
            throw new WebAuthnVerificationException('Credential key coordinates are malformed');
        }

        return new self($x, $y);
    }

    /**
     * The public key as a PEM-encoded P-256 SubjectPublicKeyInfo.
     *
     * @return string PEM `-----BEGIN PUBLIC KEY-----` block
     */
    public function toPem(): string
    {
        $der = hex2bin(self::P256_SPKI_PREFIX_HEX) . $this->x . $this->y;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
