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
 * What is guarded here is the shape, not the lookup: the seven keys the frontend
 * `IdentifierDetection` interface declares are always present, the verbatim echo
 * survives normalization, and each status carries only the method list that makes
 * sense for it — a `pending` or `proven` hold naming a way in, or a `none` naming an
 * account's methods, would both send the surface somewhere the backend refuses to
 * follow. The
 * seventh key is the reason registration is not offered (HIL-830), and it rides
 * `none` alone for the same reason the two lists are kept apart.
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
        self::assertNull($detection->registrationBlock, 'Something registerable is not blocked by anything');
    }

    /**
     * A free identifier with nothing registerable names WHICH of the two reasons emptied it.
     */
    public function testFreeIdentifierCarriesTheReasonRegistrationIsNotOffered(): void
    {
        $closed = IdentifierDetection::free(
            self::TYPED_EMAIL,
            self::NORMALIZED_EMAIL,
            IdentifierDetection::KIND_EMAIL,
            [],
            IdentifierDetection::BLOCK_CLOSED,
        );
        $noChannel = IdentifierDetection::free(
            self::TYPED_EMAIL,
            self::NORMALIZED_EMAIL,
            IdentifierDetection::KIND_EMAIL,
            [],
            IdentifierDetection::BLOCK_NO_CHANNEL,
        );

        // Both answer the same empty list, which is exactly why the reason exists: before
        // it the surface saw only the emptiness and blamed a decision nobody had taken.
        self::assertSame([], $closed->registerable);
        self::assertSame([], $noChannel->registerable);
        self::assertSame(IdentifierDetection::BLOCK_CLOSED, $closed->registrationBlock);
        self::assertSame(IdentifierDetection::BLOCK_NO_CHANNEL, $noChannel->registrationBlock);
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
        self::assertNull($detection->registrationBlock, 'A hold is not a refused registration');
    }

    /**
     * A proved identifier answers `proven` with both lists empty: the surface goes to the password step.
     *
     * The fourth state (HIL-825) carries as little as `pending` does, and for a reason of
     * its own: the account this address is about to get does not exist yet, so there is no
     * way in to name, and offering to register it again would deny the proof this very
     * browser just gave.
     */
    public function testProvedIdentifierCarriesNeitherMethodList(): void
    {
        $detection = IdentifierDetection::proven(
            self::TYPED_EMAIL,
            self::NORMALIZED_EMAIL,
            IdentifierDetection::KIND_EMAIL,
        );

        self::assertSame(IdentifierDetection::STATUS_PROVEN, $detection->status);
        self::assertSame(self::TYPED_EMAIL, $detection->identifier);
        self::assertSame(self::NORMALIZED_EMAIL, $detection->normalized);
        self::assertSame([], $detection->methods);
        self::assertSame([], $detection->registerable);
        self::assertNull($detection->registrationBlock, 'A proved hold is not a refused registration');
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
        self::assertNull($detection->registrationBlock, 'An owned identifier is not up for registration at all');
    }

    /**
     * The wire form carries all seven keys, and the echo is what was asked, not what it normalized to.
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
            'registrationBlock' => null,
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
            [],
            IdentifierDetection::BLOCK_NO_CHANNEL,
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
            'registrationBlock' => null,
        ]);
    }
}
