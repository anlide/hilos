<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Detection;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the identifier-lookup reply the surface reveals from (HIL-414).
 *
 * What is guarded here is the shape, not the lookup: the six keys the frontend
 * `IdentifierDetection` interface declares are always present, the verbatim echo
 * survives normalization, and each status carries only the method list that makes
 * sense for it — a `pending` hold naming a way in, or a `none` naming an account's
 * methods, would both send the surface somewhere the backend refuses to follow.
 */
final class IdentifierDetectionTest extends TestCase
{
    private const string TYPED_EMAIL = 'Person@Example.COM';
    private const string NORMALIZED_EMAIL = 'person@example.com';

    /**
     * A free identifier answers `none`, names what it can be registered with, and no methods.
     */
    public function testFreeIdentifierCarriesRegisterableAndNoMethods(): void
    {
        $detection = IdentifierDetection::free(
            self::TYPED_EMAIL,
            self::NORMALIZED_EMAIL,
            IdentifierDetection::KIND_EMAIL,
            [AuthMethodKey::PASSWORD, AuthMethodKey::MAGIC_LINK],
        );

        self::assertSame(IdentifierDetection::STATUS_NONE, $detection->status);
        self::assertSame([], $detection->methods);
        self::assertSame([AuthMethodKey::PASSWORD, AuthMethodKey::MAGIC_LINK], $detection->registerable);
    }

    /**
     * A held identifier answers `pending` with both lists empty: the surface parks on the code step.
     */
    public function testHeldIdentifierCarriesNeitherMethodList(): void
    {
        $detection = IdentifierDetection::held(
            self::TYPED_EMAIL,
            self::NORMALIZED_EMAIL,
            IdentifierDetection::KIND_EMAIL,
        );

        self::assertSame(IdentifierDetection::STATUS_PENDING, $detection->status);
        self::assertSame([], $detection->methods);
        self::assertSame([], $detection->registerable);
    }

    /**
     * An owned identifier answers `active`, names the account's methods, and nothing registerable.
     */
    public function testOwnedIdentifierCarriesMethodsAndNothingRegisterable(): void
    {
        $detection = IdentifierDetection::owned(
            '+1 555 010 1234',
            '+15550101234',
            IdentifierDetection::KIND_PHONE,
            [AuthMethodKey::SMS],
        );

        self::assertSame(IdentifierDetection::STATUS_ACTIVE, $detection->status);
        self::assertSame([AuthMethodKey::SMS], $detection->methods);
        self::assertSame([], $detection->registerable);
    }

    /**
     * The wire form carries all six keys, and the echo is what was asked, not what it normalized to.
     */
    public function testWireFormCarriesEveryKeyAndTheVerbatimEcho(): void
    {
        $detection = IdentifierDetection::owned(
            self::TYPED_EMAIL,
            self::NORMALIZED_EMAIL,
            IdentifierDetection::KIND_EMAIL,
            [AuthMethodKey::PASSWORD],
        );

        self::assertSame([
            'identifier' => self::TYPED_EMAIL,
            'normalized' => self::NORMALIZED_EMAIL,
            'kind' => IdentifierDetection::KIND_EMAIL,
            'status' => IdentifierDetection::STATUS_ACTIVE,
            'methods' => [AuthMethodKey::PASSWORD],
            'registerable' => [],
        ], $detection->toArray());
    }

    /**
     * A detection survives the wire round-trip unchanged.
     *
     * @throws InvalidFormatException Never in the success path
     */
    public function testWireFormRoundTrips(): void
    {
        $detection = IdentifierDetection::free(
            self::TYPED_EMAIL,
            self::NORMALIZED_EMAIL,
            IdentifierDetection::KIND_EMAIL,
            [AuthMethodKey::MAGIC_LINK],
        );

        self::assertSame($detection->toArray(), IdentifierDetection::fromArray($detection->toArray())->toArray());
    }

    /**
     * A method list holding something other than a string is rejected rather than coerced.
     *
     * @throws InvalidFormatException Always — that is what is asserted
     */
    public function testMethodListRejectsNonStringEntry(): void
    {
        $this->expectException(InvalidFormatException::class);

        IdentifierDetection::fromArray([
            'identifier' => self::NORMALIZED_EMAIL,
            'normalized' => self::NORMALIZED_EMAIL,
            'kind' => IdentifierDetection::KIND_EMAIL,
            'status' => IdentifierDetection::STATUS_ACTIVE,
            'methods' => [17],
            'registerable' => [],
        ]);
    }
}
