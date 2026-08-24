<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\WebAuthn;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\UnsignedIntegerObject;
use Hilos\Auth\WebAuthn\CoseKey;
use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the COSE_Key -> PEM parser (HIL-284, HIL-658).
 *
 * A genuine key of either declared algorithm parses to a PEM that openssl can
 * load; a key whose algorithm is outside the set, whose key type contradicts its
 * algorithm, or whose material is malformed is rejected before it reaches storage.
 */
final class CoseKeyTest extends TestCase
{
    private const int COSE_LABEL_KEY_TYPE = 1;
    private const int COSE_LABEL_ALGORITHM = 3;

    private const int COSE_KEY_TYPE_EC2 = 2;
    private const int COSE_KEY_TYPE_RSA = 3;
    private const int COSE_KEY_TYPE_OKP = 1;

    private const int ALGORITHM_EDDSA = -8;

    /**
     * @return list<array{PasskeyAlgorithm}> Every algorithm the ceremony offers
     */
    public static function declaredAlgorithms(): array
    {
        return array_map(static fn(PasskeyAlgorithm $algorithm): array => [$algorithm], PasskeyAlgorithm::cases());
    }

    /**
     * A genuine key of a declared algorithm parses to a PEM openssl accepts.
     *
     * @param PasskeyAlgorithm $algorithm Algorithm under test
     * @throws WebAuthnVerificationException Never in the success path
     */
    #[DataProvider('declaredAlgorithms')]
    public function testParsesDeclaredKeyToLoadablePem(PasskeyAlgorithm $algorithm): void
    {
        $vectors = new WebAuthnTestVectors(algorithm: $algorithm);

        $key = CoseKey::fromCbor($vectors->coseKeyCbor());
        $pem = $key->toPem();

        self::assertSame($algorithm, $key->algorithm());
        self::assertStringContainsString('BEGIN PUBLIC KEY', $pem);
        $publicKey = openssl_pkey_get_public($pem);
        self::assertNotFalse($publicKey);
        $details = openssl_pkey_get_details($publicKey);
        self::assertNotFalse($details);
        self::assertSame($algorithm->opensslKeyType(), $details['type']);
    }

    /**
     * A non-CBOR byte string is rejected.
     */
    public function testRejectsInvalidCbor(): void
    {
        $this->expectException(WebAuthnVerificationException::class);
        CoseKey::fromCbor("\xff\xff\xff");
    }

    /**
     * A key type outside the declared algorithms' own types is rejected.
     */
    public function testRejectsUnknownKeyType(): void
    {
        $this->expectException(WebAuthnVerificationException::class);
        CoseKey::fromCbor(self::ec2Map(self::COSE_KEY_TYPE_OKP, PasskeyAlgorithm::Es256->value));
    }

    /**
     * An RSA key declaring the elliptic-curve algorithm is rejected.
     */
    public function testRejectsRsaKeyDeclaredAsEs256(): void
    {
        $parts = self::rsaParts(new WebAuthnTestVectors(algorithm: PasskeyAlgorithm::Rs256)->publicKeyPem);

        $this->expectException(WebAuthnVerificationException::class);
        CoseKey::fromCbor(self::rsaMap(self::COSE_KEY_TYPE_RSA, PasskeyAlgorithm::Es256->value, $parts['n'], $parts['e']));
    }

    /**
     * An elliptic-curve key declaring the RSA algorithm is rejected.
     */
    public function testRejectsEc2KeyDeclaredAsRs256(): void
    {
        $this->expectException(WebAuthnVerificationException::class);
        CoseKey::fromCbor(self::ec2Map(self::COSE_KEY_TYPE_EC2, PasskeyAlgorithm::Rs256->value));
    }

    /**
     * An RSA key shorter than 2048 bits is rejected however well-formed it is.
     */
    public function testRejectsShortRsaModulus(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertNotFalse($details);

        $this->expectException(WebAuthnVerificationException::class);
        CoseKey::fromCbor(self::rsaMap(
            self::COSE_KEY_TYPE_RSA,
            PasskeyAlgorithm::Rs256->value,
            $details['rsa']['n'],
            $details['rsa']['e'],
        ));
    }

    /**
     * A short RSA modulus padded up to the accepted length is still rejected.
     *
     * Leading zero bytes are padding, not size: the floor has to be read off the
     * magnitude, or a 1024-bit key dressed as a 256-byte string walks past it and
     * openssl loads exactly the weak key that was sent.
     */
    public function testRejectsShortRsaModulusPaddedToLength(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertNotFalse($details);

        $padded = str_pad($details['rsa']['n'], 256, "\0", STR_PAD_LEFT);
        self::assertSame(256, strlen($padded));

        $this->expectException(WebAuthnVerificationException::class);
        CoseKey::fromCbor(self::rsaMap(self::COSE_KEY_TYPE_RSA, PasskeyAlgorithm::Rs256->value, $padded, $details['rsa']['e']));
    }

    /**
     * An RSA key whose exponent is zero is rejected.
     */
    public function testRejectsZeroRsaExponent(): void
    {
        $parts = self::rsaParts(new WebAuthnTestVectors(algorithm: PasskeyAlgorithm::Rs256)->publicKeyPem);

        $this->expectException(WebAuthnVerificationException::class);
        CoseKey::fromCbor(self::rsaMap(self::COSE_KEY_TYPE_RSA, PasskeyAlgorithm::Rs256->value, $parts['n'], "\0\0\0"));
    }

    /**
     * An algorithm outside the set is refused by a message that names the whole set.
     */
    public function testRefusalNamesEveryDeclaredAlgorithm(): void
    {
        $this->expectException(WebAuthnVerificationException::class);
        $this->expectExceptionMessageMatches('/\bES256\b.*\bRS256\b/');
        CoseKey::fromCbor(self::ec2Map(self::COSE_KEY_TYPE_EC2, self::ALGORITHM_EDDSA));
    }

    /**
     * A key whose coordinates are not 32 bytes is rejected.
     */
    public function testRejectsMalformedCoordinates(): void
    {
        $cbor = (string)MapObject::create()
            ->add(UnsignedIntegerObject::create(self::COSE_LABEL_KEY_TYPE), UnsignedIntegerObject::create(self::COSE_KEY_TYPE_EC2))
            ->add(UnsignedIntegerObject::create(self::COSE_LABEL_ALGORITHM), NegativeIntegerObject::create(PasskeyAlgorithm::Es256->value))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_repeat("\x01", 16)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_repeat("\x02", 32)));

        $this->expectException(WebAuthnVerificationException::class);
        CoseKey::fromCbor($cbor);
    }

    /**
     * @param string $pem PEM-encoded RSA public key
     * @return array{n: string, e: string} Its modulus and public exponent bytes
     */
    private static function rsaParts(string $pem): array
    {
        $publicKey = openssl_pkey_get_public($pem);
        self::assertNotFalse($publicKey);
        $details = openssl_pkey_get_details($publicKey);
        self::assertNotFalse($details);

        return ['n' => $details['rsa']['n'], 'e' => $details['rsa']['e']];
    }

    /**
     * @param int $keyType COSE key type to declare
     * @param int $algorithm COSE algorithm to declare
     * @return string CBOR-encoded EC2-shaped COSE key with well-formed P-256 coordinates
     */
    private static function ec2Map(int $keyType, int $algorithm): string
    {
        return (string)MapObject::create()
            ->add(UnsignedIntegerObject::create(self::COSE_LABEL_KEY_TYPE), UnsignedIntegerObject::create($keyType))
            ->add(UnsignedIntegerObject::create(self::COSE_LABEL_ALGORITHM), NegativeIntegerObject::create($algorithm))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_repeat("\x01", 32)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_repeat("\x02", 32)));
    }

    /**
     * @param int $keyType COSE key type to declare
     * @param int $algorithm COSE algorithm to declare
     * @param string $modulus RSA modulus bytes
     * @param string $exponent RSA public exponent bytes
     * @return string CBOR-encoded RSA-shaped COSE key
     */
    private static function rsaMap(int $keyType, int $algorithm, string $modulus, string $exponent): string
    {
        return (string)MapObject::create()
            ->add(UnsignedIntegerObject::create(self::COSE_LABEL_KEY_TYPE), UnsignedIntegerObject::create($keyType))
            ->add(UnsignedIntegerObject::create(self::COSE_LABEL_ALGORITHM), NegativeIntegerObject::create($algorithm))
            ->add(NegativeIntegerObject::create(-1), ByteStringObject::create($modulus))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($exponent));
    }
}
