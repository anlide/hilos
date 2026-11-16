<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Item\OAuthPendingLogin;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Hilos\Runtime\State\Collection\OAuthPendingLogins;
use Hilos\Runtime\RtRowValues;

/**
 * Unit tests for the in-flight OAuth login RT op (HIL-281).
 *
 * Pins the row contract the callback writer and the async agent share: the accept
 * key is the id, a row round-trips through the RT-sync array form unchanged, and a
 * row that lost a field on the way is refused rather than completed from thin air.
 */
final class OAuthPendingLoginTest extends TestCase
{
    public function testCreateExposesTheAcceptKeyAsTheId(): void
    {
        $op = OAuthPendingLogin::create('accept-1', 'session-abc', 'oauth:github', 'code-xyz', 'trip-hash-1');

        $this->assertSame('accept-1', $op->getId());
        $this->assertSame('accept-1', $op->acceptKey);
        $this->assertSame(OAuthPendingLogin::MODE_LOGIN, $op->mode);
        $this->assertSame(0, $op->linkUserId);
    }

    public function testToArrayCarriesEveryField(): void
    {
        $op = OAuthPendingLogin::create('accept-1', 'session-abc', 'oauth:github', 'code-xyz', 'trip-hash-1');

        $this->assertSame(
            [
                OAuthPendingLogin::acceptKey => 'accept-1',
                OAuthPendingLogin::sessionToken => 'session-abc',
                OAuthPendingLogin::provider => 'oauth:github',
                OAuthPendingLogin::code => 'code-xyz',
                OAuthPendingLogin::tripKeyHash => 'trip-hash-1',
                OAuthPendingLogin::mode => OAuthPendingLogin::MODE_LOGIN,
                OAuthPendingLogin::linkUserId => 0,
                OAuthPendingLogin::awaitingIdentityAdd => false,
            ],
            $op->toArray(),
        );
    }

    public function testCreateWithLinkModeCarriesModeAndTargetUser(): void
    {
        $op = OAuthPendingLogin::create(
            'accept-9',
            'session-ghi',
            'oauth:github',
            'code-link',
            'trip-hash-9',
            OAuthPendingLogin::MODE_LINK,
            77,
        );

        $this->assertSame(OAuthPendingLogin::MODE_LINK, $op->mode);
        $this->assertSame(77, $op->linkUserId);
        $this->assertSame(OAuthPendingLogin::MODE_LINK, $op->toArray()[OAuthPendingLogin::mode]);
        $this->assertSame(77, $op->toArray()[OAuthPendingLogin::linkUserId]);
        $this->assertSame($op->toArray(), OAuthPendingLogin::fromRow($op->toArray())->toArray());
    }

    public function testFromRowRoundTripsToArray(): void
    {
        $row = OAuthPendingLogin::create('accept-2', 'session-def', 'oauth:stub', 'stub', 'trip-hash-2')->toArray();

        $this->assertSame($row, OAuthPendingLogin::fromRow($row)->toArray());
    }

    public function testFromRowRefusesARowMissingARequiredField(): void
    {
        $this->expectException(InvalidFormatException::class);

        OAuthPendingLogin::fromRow([
            OAuthPendingLogin::acceptKey => 'accept-3',
            OAuthPendingLogin::sessionToken => 'session-ghi',
            OAuthPendingLogin::provider => 'stub',
            OAuthPendingLogin::code => 'oauth:stub',
        ]);
    }

    public function testAwaitingMarkerRoundTripsAndPatchesWithoutChangingTheExchange(): void
    {
        $op = OAuthPendingLogin::create('accept', 'session', 'oauth:stub', 'code', 'trip', OAuthPendingLogin::MODE_LINK, 7);
        self::assertFalse($op->awaitingIdentityAdd);
        $original = $op->toArray();
        $op->applyDiff([OAuthPendingLogin::awaitingIdentityAdd => true, OAuthPendingLogin::code => 'ignored']);
        $expected = [...$original, OAuthPendingLogin::awaitingIdentityAdd => true];
        self::assertSame($expected, $op->toArray());
        self::assertSame($expected, OAuthPendingLogin::fromRow($expected)->toArray());
        $op->applyDiff([]);
        self::assertSame($expected, $op->toArray());

        $codec = $op->rowCodec();
        $restored = RtRowValues::completed($codec->restore(RtRowValues::fromNative($original)->reader()));
        RtRowValues::completed($codec->patch($restored, RtRowValues::fromNative([
            OAuthPendingLogin::awaitingIdentityAdd => true,
            OAuthPendingLogin::provider => 'ignored',
        ])->reader()));
        self::assertSame($expected, $restored->toArray());
    }

    /** @param array<string, mixed> $marker Missing or mistyped marker */
    #[DataProvider('invalidAwaitingMarkers')]
    public function testFullRowsRefuseMissingOrMistypedAwaitingMarkers(array $marker): void
    {
        $row = OAuthPendingLogin::create('accept', 'session', 'oauth:stub', 'code', 'trip')->toArray();
        unset($row[OAuthPendingLogin::awaitingIdentityAdd]);
        $row = [...$row, ...$marker];
        try {
            OAuthPendingLogin::fromRow($row);
            self::fail('A full row must carry its boolean marker');
        } catch (InvalidFormatException) {
        }
        $this->expectException(InvalidFormatException::class);
        RtRowValues::completed(OAuthPendingLogin::create('a', 's', 'oauth:stub', 'c', 't')->rowCodec()->restore(
            RtRowValues::fromNative($row)->reader(),
        ));
    }

    /** @return iterable<string, array{array<string, mixed>}> Strict full-row boolean cases */
    public static function invalidAwaitingMarkers(): iterable
    {
        yield 'missing' => [[]];
        foreach ([null, 0, 1, 'false', []] as $index => $value) {
            yield 'wrong type ' . $index => [[OAuthPendingLogin::awaitingIdentityAdd => $value]];
        }
    }

    public function testInvalidPatchesLeaveBothSynchronousAndSegmentedRowsUnchanged(): void
    {
        $op = OAuthPendingLogin::create('accept', 'session', 'oauth:stub', 'code', 'trip');
        $before = $op->toArray();
        $diff = [OAuthPendingLogin::awaitingIdentityAdd => 'true'];
        try {
            $op->applyDiff($diff);
            self::fail('A string cannot patch a boolean');
        } catch (InvalidFormatException) {
        }
        self::assertSame($before, $op->toArray());
        try {
            RtRowValues::completed($op->rowCodec()->patch($op, RtRowValues::fromNative($diff)->reader()));
            self::fail('Segmented patches must validate before replacement');
        } catch (InvalidFormatException) {
        }
        self::assertSame($before, $op->toArray());
    }

    public function testCollectionMarksOnlyTheCurrentTrip(): void
    {
        $pending = OAuthPendingLogins::init();
        $pending->add(OAuthPendingLogin::create('accept', 'session', 'oauth:stub', 'code', 'current'));
        $pending->markAwaitingIdentityAdd('missing', 'current');
        $pending->markAwaitingIdentityAdd('accept', 'old');
        self::assertFalse($pending['accept']->awaitingIdentityAdd);
        $pending->markAwaitingIdentityAdd('accept', 'current');
        self::assertTrue($pending['accept']->awaitingIdentityAdd);
        $pending->remove('accept');
        $pending->add(OAuthPendingLogin::create('accept', 'session', 'oauth:stub', 'code', 'next'));
        $pending->markAwaitingIdentityAdd('accept', 'current');
        self::assertFalse($pending['accept']->awaitingIdentityAdd);
    }

    public function testRtCollectionKeyIsStable(): void
    {
        $this->assertSame('hilosOAuthPendingLogins', OAuthPendingLogin::getRtCollectionKey());
        $this->assertSame(OAuthPendingLogin::RT_COLLECTION, OAuthPendingLogin::getRtCollectionKey());
    }
}
