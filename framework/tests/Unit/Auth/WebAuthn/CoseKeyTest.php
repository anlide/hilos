<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\WebAuthn;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\UnsignedIntegerObject;
use Hilos\Auth\WebAuthn\CoseKey;
use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the COSE_Key -> PEM parser (HIL-284).
 *
 * A real ES256 P-256 COSE key parses to a PEM that openssl can load; anything
 * that is not a well-formed ES256 P-256 key is rejected before it reaches storage.
 */
final class CoseKeyTest extends TestCase
{
    /**
     * A genuine ES256 COSE key parses to a PEM openssl accepts as a public key.
     */
    public function testParsesEs256KeyToLoadablePem(): void
    {
        $vectors = new WebAuthnTestVectors();

        $pem = CoseKey::fromCbor($vectors->coseKeyCbor())->toPem();

        self::assertStringContainsString('BEGIN PUBLIC KEY', $pem);
        self::assertNotFalse(openssl_pkey_get_public($pem));
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
     * A key that is not EC2 (e.g. an RSA kty) is rejected.
     */
    public function testRejectsNonEc2KeyType(): void
    {
        $cbor = (string)MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(3))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_repeat("\x01", 32)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_repeat("\x02", 32)));

        $this->expectException(WebAuthnVerificationException::class);
        CoseKey::fromCbor($cbor);
    }

    /**
     * A key whose coordinates are not 32 bytes is rejected.
     */
    public function testRejectsMalformedCoordinates(): void
    {
        $cbor = (string)MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_repeat("\x01", 16)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_repeat("\x02", 32)));

        $this->expectException(WebAuthnVerificationException::class);
        CoseKey::fromCbor($cbor);
    }
}
