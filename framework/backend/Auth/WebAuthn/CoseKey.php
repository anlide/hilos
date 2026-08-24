<?php

declare(strict_types=1);

namespace Hilos\Auth\WebAuthn;

use CBOR\Decoder;
use CBOR\MapObject;
use CBOR\StringStream;
use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;
use Throwable;

/**
 * A COSE_Key credential public key, parsed from attestation CBOR to PEM (HIL-284, HIL-658).
 *
 * WebAuthn ships the credential public key inside authenticatorData as a
 * CBOR-encoded COSE_Key map. This parser supports the algorithms the ceremony
 * requests — the set {@see PasskeyAlgorithm} declares — and has one branch per
 * key shape: an EC2 key on the NIST P-256 curve for ES256, an RSA key of at least
 * 2048 bits for RS256. Either way {@see toPem()} hands back a
 * SubjectPublicKeyInfo `openssl_pkey_get_public()` can load at assertion time, and
 * {@see algorithm()} names the suite the credential row has to remember.
 *
 * The key type is read before anything else, because the two shapes disagree on
 * what the negative COSE labels mean: -1 and -2 are the curve and the x coordinate
 * of an EC2 key but the modulus and the exponent of an RSA one. Reading them
 * before the shape is settled would decode an RSA key as elliptic — rubbish that
 * looks like a key. Anything whose algorithm is outside the set, or whose key type
 * disagrees with its algorithm, is rejected before it reaches storage.
 */
final class CoseKey
{
    private const int COSE_KEY_TYPE = 1;
    private const int COSE_ALGORITHM = 3;

    private const int COSE_EC2_CURVE = -1;
    private const int COSE_EC2_X = -2;
    private const int COSE_EC2_Y = -3;

    private const int COSE_RSA_MODULUS = -1;
    private const int COSE_RSA_EXPONENT = -2;

    private const int CURVE_P256 = 1;
    private const int COORDINATE_BYTES = 32;

    /** 2048 bits, the shortest RSA modulus worth accepting into a table rows outlive us in. */
    private const int MODULUS_MIN_BYTES = 256;

    /** 4096 bits, the longest modulus any authenticator mints. */
    private const int MODULUS_MAX_BYTES = 512;

    private const int EXPONENT_MAX_BYTES = 8;

    /**
     * DER prefix of a P-256 SubjectPublicKeyInfo up to and including the leading
     * uncompressed-point marker (0x04); the 32-byte X and Y coordinates follow.
     */
    private const string P256_SPKI_PREFIX_HEX = '3059301306072a8648ce3d020106082a8648ce3d03010703420004';

    /**
     * DER AlgorithmIdentifier of rsaEncryption (OID 1.2.840.113549.1.1.1) followed by
     * the explicit NULL parameters RFC 8017 requires; the BIT STRING with the key
     * itself follows, so unlike the P-256 prefix this one cannot cover the whole
     * header — an RSA modulus has no fixed length.
     */
    private const string RSA_ALGORITHM_IDENTIFIER_HEX = '300d06092a864886f70d0101010500';

    private const int DER_TAG_INTEGER = 0x02;
    private const int DER_TAG_BIT_STRING = 0x03;
    private const int DER_TAG_SEQUENCE = 0x30;

    /** Lengths from this value up are written long form: a count byte, then the length itself. */
    private const int DER_LENGTH_LONG_FORM = 0x80;

    private const int DER_LENGTH_BYTE_MASK = 0xff;
    private const int DER_LENGTH_BITS_PER_BYTE = 8;

    /** A DER INTEGER is signed, so a leading byte with this bit set needs a zero in front. */
    private const int DER_INTEGER_SIGN_BIT = 0x80;

    /**
     * @param PasskeyAlgorithm $algorithm Signature suite the key belongs to
     * @param string $spkiDer DER-encoded SubjectPublicKeyInfo of the key
     */
    private function __construct(
        private readonly PasskeyAlgorithm $algorithm,
        private readonly string $spkiDer,
    ) {
    }

    /**
     * Parses a CBOR-encoded COSE_Key, accepting only a declared algorithm whose key type agrees.
     *
     * @param string $cbor Raw CBOR bytes of the credential public key
     * @return self Validated key
     * @throws WebAuthnVerificationException When the bytes are not a well-formed key of a declared algorithm
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
        $keyType = (int)($map[self::COSE_KEY_TYPE] ?? 0);

        $algorithm = PasskeyAlgorithm::tryFrom((int)($map[self::COSE_ALGORITHM] ?? 0));
        if ($algorithm === null) {
            throw new WebAuthnVerificationException(
                'Unsupported credential algorithm (expected one of ' . PasskeyAlgorithm::declaredLabels() . ')',
            );
        }

        if ($algorithm->coseKeyType() !== $keyType) {
            throw new WebAuthnVerificationException('Credential key type does not match its algorithm');
        }

        return match ($algorithm) {
            PasskeyAlgorithm::Es256 => self::fromEc2Map($map),
            PasskeyAlgorithm::Rs256 => self::fromRsaMap($map),
        };
    }

    /**
     * @return PasskeyAlgorithm Signature suite this credential signs with
     */
    public function algorithm(): PasskeyAlgorithm
    {
        return $this->algorithm;
    }

    /**
     * The public key as a PEM-encoded SubjectPublicKeyInfo.
     *
     * @return string PEM `-----BEGIN PUBLIC KEY-----` block
     */
    public function toPem(): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($this->spkiDer), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * @param array<int|string, mixed> $map Normalized COSE key map, known to carry the EC2 key type
     * @return self Validated ES256 key
     * @throws WebAuthnVerificationException When the curve is not P-256 or a coordinate is not 32 bytes
     */
    private static function fromEc2Map(array $map): self
    {
        if ((int)($map[self::COSE_EC2_CURVE] ?? 0) !== self::CURVE_P256) {
            throw new WebAuthnVerificationException('Unsupported credential curve (expected P-256)');
        }

        // external-boundary: the CBOR map arrives from the browser and may carry no x coordinate
        $x = $map[self::COSE_EC2_X] ?? '';
        // external-boundary: the CBOR map arrives from the browser and may carry no y coordinate
        $y = $map[self::COSE_EC2_Y] ?? '';
        if (!is_string($x) || !is_string($y) || strlen($x) !== self::COORDINATE_BYTES || strlen($y) !== self::COORDINATE_BYTES) {
            throw new WebAuthnVerificationException('Credential key coordinates are malformed');
        }

        return new self(PasskeyAlgorithm::Es256, hex2bin(self::P256_SPKI_PREFIX_HEX) . $x . $y);
    }

    /**
     * Reads the RSA branch, measuring the key by its magnitude rather than its length.
     *
     * Leading zero bytes are padding and say nothing about strength: a 1024-bit
     * modulus sent with 128 of them in front is 256 bytes long and would clear a
     * floor written in raw bytes, yet the key openssl ends up loading is half the
     * size the floor asks for. The attestation is unsigned (`none`), so these bytes
     * are exactly as trustworthy as whoever sent them.
     *
     * @param array<int|string, mixed> $map Normalized COSE key map, known to carry the RSA key type
     * @return self Validated RS256 key
     * @throws WebAuthnVerificationException When the modulus or the exponent is missing, malformed or out of range
     */
    private static function fromRsaMap(array $map): self
    {
        // external-boundary: the CBOR map arrives from the browser and may carry no modulus
        $modulus = $map[self::COSE_RSA_MODULUS] ?? '';
        // external-boundary: the CBOR map arrives from the browser and may carry no exponent
        $exponent = $map[self::COSE_RSA_EXPONENT] ?? '';
        if (!is_string($modulus) || !is_string($exponent)) {
            throw new WebAuthnVerificationException('Credential key material is malformed');
        }

        $modulusMagnitude = ltrim($modulus, "\0");
        if (strlen($modulusMagnitude) < self::MODULUS_MIN_BYTES || strlen($modulusMagnitude) > self::MODULUS_MAX_BYTES) {
            throw new WebAuthnVerificationException('Credential key modulus is malformed (expected an RSA key of 2048..4096 bits)');
        }

        $exponentMagnitude = ltrim($exponent, "\0");
        if ($exponentMagnitude === '' || strlen($exponentMagnitude) > self::EXPONENT_MAX_BYTES) {
            throw new WebAuthnVerificationException('Credential key exponent is malformed');
        }

        $rsaPublicKey = self::derSequence(self::derInteger($modulusMagnitude) . self::derInteger($exponentMagnitude));

        return new self(
            PasskeyAlgorithm::Rs256,
            self::derSequence(hex2bin(self::RSA_ALGORITHM_IDENTIFIER_HEX) . self::derBitString($rsaPublicKey)),
        );
    }

    /**
     * @param string $contents Bytes the sequence holds
     * @return string DER SEQUENCE wrapping the contents
     */
    private static function derSequence(string $contents): string
    {
        return chr(self::DER_TAG_SEQUENCE) . self::derLength(strlen($contents)) . $contents;
    }

    /**
     * @param string $contents Bytes the bit string holds
     * @return string DER BIT STRING wrapping the contents, with no unused trailing bits
     */
    private static function derBitString(string $contents): string
    {
        $body = "\0" . $contents;

        return chr(self::DER_TAG_BIT_STRING) . self::derLength(strlen($body)) . $body;
    }

    /**
     * Encodes a big-endian unsigned number as a DER INTEGER.
     *
     * DER integers are signed, so a magnitude whose leading byte already has the
     * high bit set takes a zero byte in front to keep it positive.
     *
     * @param string $magnitude Big-endian magnitude bytes, non-empty and already stripped of leading zeros
     * @return string DER INTEGER holding the same number
     */
    private static function derInteger(string $magnitude): string
    {
        if ((ord($magnitude[0]) & self::DER_INTEGER_SIGN_BIT) !== 0) {
            $magnitude = "\0" . $magnitude;
        }

        return chr(self::DER_TAG_INTEGER) . self::derLength(strlen($magnitude)) . $magnitude;
    }

    /**
     * @param int $length Number of content bytes to announce
     * @return string DER length header, short form below 128 bytes and long form above
     */
    private static function derLength(int $length): string
    {
        if ($length < self::DER_LENGTH_LONG_FORM) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & self::DER_LENGTH_BYTE_MASK) . $bytes;
            $length >>= self::DER_LENGTH_BITS_PER_BYTE;
        }

        return chr(self::DER_LENGTH_LONG_FORM | strlen($bytes)) . $bytes;
    }
}
