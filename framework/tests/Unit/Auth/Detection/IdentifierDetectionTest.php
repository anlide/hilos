<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Detection;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the identifier-lookup reply the surface reveals from (HIL-414).
 *
 * What is guarded here is the shape, not the lookup: the nine keys the frontend
 * `IdentifierDetection` interface declares are always present, the verbatim echo
 * survives normalization, and each status carries only the method list that makes
 * sense for it — a `pending` or `proven` hold naming a way in, or a `none` naming an
 * account's methods, would both send the surface somewhere the backend refuses to
 * follow. The
 * seventh key is the reason registration is not offered (HIL-830), and it rides
 * `none` alone for the same reason the two lists are kept apart. The eighth is its
 * sign-in twin (HIL-973): why an account is offered no way in, riding `active` alone.
 * The ninth is the server's sentence for that refusal, or null without one (HIL-1516).
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
        self::assertNull($detection->signInBlock, 'A free identifier has no account to sign in to');
        self::assertNull($detection->message);
        self::assertArrayHasKey('message', $detection->toArray());
        self::assertNull($detection->toArray()['message']);
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
        self::assertNull($noChannel->signInBlock, 'A refused registration is not a refused sign-in');
        self::assertSame('No account for this, and registration is closed.', $closed->message);
        self::assertSame('No account for this, and there is nothing to send a code with.', $noChannel->message);
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
        self::assertNull($detection->signInBlock, 'A hold has no account to sign in to');
        self::assertNull($detection->message);
        self::assertArrayHasKey('message', $detection->toArray());
        self::assertNull($detection->toArray()['message']);
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
        self::assertNull($detection->signInBlock, 'A proved hold has no account to sign in to yet');
        self::assertNull($detection->message);
        self::assertArrayHasKey('message', $detection->toArray());
        self::assertNull($detection->toArray()['message']);
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
        self::assertNull($detection->signInBlock, 'An account with a way in is not blocked by anything');
        self::assertNull($detection->message);
        self::assertArrayHasKey('message', $detection->toArray());
        self::assertNull($detection->toArray()['message']);
    }

    /**
     * An owned identifier with nothing to sign in with carries why, and only in its own slot.
     */
    public function testOwnedIdentifierCarriesTheReasonSignInIsNotOffered(): void
    {
        $detection = IdentifierDetection::owned(
            self::TYPED_EMAIL,
            self::NORMALIZED_EMAIL,
            IdentifierDetection::KIND_EMAIL,
            [],
            IdentifierDetection::BLOCK_NO_CHANNEL,
        );

        self::assertSame(IdentifierDetection::STATUS_ACTIVE, $detection->status);
        self::assertSame([], $detection->methods);
        self::assertSame(IdentifierDetection::BLOCK_NO_CHANNEL, $detection->signInBlock);
        self::assertNull($detection->registrationBlock, 'A refused sign-in is not a refused registration');
        self::assertSame(
            'This account signs in by a mailed link, and this installation has nothing to send it with. Whoever runs it can set that up.',
            $detection->message,
        );
    }

    /**
     * The wire form carries all nine keys, and the echo is what was asked, not what it normalized to.
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
            'signInBlock' => null,
            'message' => null,
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
     * A refused sign-in survives the wire round-trip unchanged.
     *
     * @throws InvalidFormatException Never in the success path
     */
    public function testRefusedSignInRoundTrips(): void
    {
        $detection = IdentifierDetection::owned(
            '+1 555 010 1234',
            '+15550101234',
            IdentifierDetection::KIND_PHONE,
            [],
            IdentifierDetection::BLOCK_NO_CHANNEL,
        );

        self::assertSame(
            'This account signs in by a code, and this installation has nothing to send it with. Whoever runs it can set that up.',
            $detection->message,
        );
        self::assertSame($detection->toArray(), IdentifierDetection::fromArray($detection->toArray())->toArray());
    }

    /** An empty method list without a named block carries no invented refusal. */
    public function testOwnedIdentifierWithoutNamedBlockHasNoMessage(): void
    {
        $detection = IdentifierDetection::owned(
            self::TYPED_EMAIL,
            self::NORMALIZED_EMAIL,
            IdentifierDetection::KIND_EMAIL,
            [],
        );

        self::assertSame([], $detection->methods);
        self::assertNull($detection->signInBlock);
        self::assertNull($detection->message);
    }

    /**
     * @return list<array{string, ?string, ?string}> Status and its two block slots
     */
    public static function statuses(): array
    {
        return [
            ['none', 'closed', null],
            ['none', 'no_channel', null],
            ['active', null, 'no_channel'],
            ['active', null, null],
            ['pending', null, null],
            ['proven', null, null],
        ];
    }

    /**
     * @param string $status Account or reservation state
     * @param ?string $registrationBlock Registration refusal code
     * @param ?string $signInBlock Sign-in refusal code
     * @throws InvalidFormatException Never for a complete reply
     */
    #[DataProvider('statuses')]
    public function testWireMessageIsPreservedWithoutRebuildingFromTheCodes(
        string $status,
        ?string $registrationBlock,
        ?string $signInBlock,
    ): void {
        foreach ([" Server sentence.\nKeep the spaces and line break. ", '', null] as $message) {
            $wire = [
                'identifier' => self::TYPED_EMAIL,
                'normalized' => self::NORMALIZED_EMAIL,
                'kind' => IdentifierDetection::KIND_EMAIL,
                'status' => $status,
                'methods' => [],
                'registerable' => [],
                'registrationBlock' => $registrationBlock,
                'signInBlock' => $signInBlock,
                'message' => $message,
            ];

            $detection = IdentifierDetection::fromArray($wire);

            self::assertSame($message, $detection->message);
            self::assertSame($wire, $detection->toArray());
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}> Missing or mistyped message keys
     */
    public static function invalidMessages(): array
    {
        return [
            'missing' => [[]],
            'integer' => [['message' => 42]],
            'float' => [['message' => 1.5]],
            'boolean' => [['message' => false]],
            'array' => [['message' => []]],
        ];
    }

    /**
     * @param array<string, mixed> $messageField Invalid wire fragment
     * @throws InvalidFormatException Always for a missing or mistyped message
     */
    #[DataProvider('invalidMessages')]
    public function testWireRequiresTheNullableStringMessage(array $messageField): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('message');

        IdentifierDetection::fromArray([
            'identifier' => self::TYPED_EMAIL,
            'normalized' => self::NORMALIZED_EMAIL,
            'kind' => IdentifierDetection::KIND_EMAIL,
            'status' => IdentifierDetection::STATUS_ACTIVE,
            'methods' => [AuthMethodKey::PASSWORD],
            'registerable' => [],
            'registrationBlock' => null,
            'signInBlock' => null,
            ...$messageField,
        ]);
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
            'signInBlock' => null,
            'message' => null,
        ]);
    }
}
