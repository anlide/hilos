<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalDocumentStanding;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSettings;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\LegalStanding;
use Hilos\Users\AccountStandingKind;
use Hilos\Users\AccountStandingResolver;
use PHPUnit\Framework\TestCase;

/**
 * The composition of an account's standing (HIL-945): which fact is shown, when a lapse freezes,
 * and the one shape the verdict travels in. What is read from storage, and the memory kept of it,
 * is pinned against a real database in the integration case.
 */
final class AccountStandingResolverTest extends TestCase
{
    private ?HilosDbContext $previousDb;

    private ?SettingsAccessor $previousSetting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousSetting = Hilos::$setting;
        AccountStandingResolver::forgetAll();
    }

    protected function tearDown(): void
    {
        AccountStandingResolver::forgetAll();
        Hilos::$setting = $this->previousSetting;
        Hilos::$db = $this->previousDb;
        parent::tearDown();
    }

    public function testTheShownFactIsTheOneThatTakesTheMostAway(): void
    {
        $lapsed = [$this->lapsedTerms()];
        $freeze = LegalSettings::REFUSAL_FREEZE;

        self::assertSame(AccountStandingKind::MERGED, AccountStandingResolver::compose(true, 9, 'S', true, 1000, $lapsed, [], $freeze)->shown);
        self::assertSame(AccountStandingKind::BLOCKED, AccountStandingResolver::compose(false, null, null, true, 1000, $lapsed, [], $freeze)->shown);
        self::assertSame(AccountStandingKind::FROZEN, AccountStandingResolver::compose(false, null, null, false, 1000, $lapsed, [], $freeze)->shown);
        self::assertSame(
            AccountStandingKind::DELETION_SCHEDULED,
            AccountStandingResolver::compose(false, null, null, false, 1000, [], [], $freeze)->shown,
        );
        self::assertSame(AccountStandingKind::NONE, AccountStandingResolver::compose(false, null, null, false, null, [], [], $freeze)->shown);
    }

    public function testEveryFactIsNamedWhateverIsShown(): void
    {
        $standing = AccountStandingResolver::compose(false, null, null, true, 1000, [$this->lapsedTerms()], [], LegalSettings::REFUSAL_FREEZE);

        self::assertTrue($standing->blocked);
        self::assertTrue($standing->frozen);
        self::assertSame(1000, $standing->deletionEffectiveAt);
        self::assertCount(1, $standing->lapsed);
    }

    public function testALapseFreezesOnlyUnderTheFreezeSetting(): void
    {
        $remind = AccountStandingResolver::compose(false, null, null, false, null, [$this->lapsedTerms()], [], LegalSettings::REFUSAL_REMIND);

        self::assertFalse($remind->frozen);
        self::assertSame(AccountStandingKind::NONE, $remind->shown);
        // The card still says the deadline passed: the lapse is named under either setting.
        self::assertCount(1, $remind->lapsed);
        self::assertFalse(AccountStandingResolver::compose(false, null, null, false, null, [], [], LegalSettings::REFUSAL_FREEZE)->frozen);
    }

    public function testTheVerdictTravelsInOneShape(): void
    {
        $standing = AccountStandingResolver::compose(
            false,
            null,
            null,
            false,
            1767225600000,
            [$this->lapsedTerms()],
            [],
            LegalSettings::REFUSAL_FREEZE,
        );

        self::assertSame([
            'shown' => 'frozen',
            'blocked' => false,
            'frozen' => true,
            'deletionEffectiveAt' => 1767225600000,
            'lapsed' => [['document' => 'terms', 'deadline' => '2026-03-01']],
            'window' => [],
            'mergedInto' => null,
            'mergedIntoName' => null,
        ], $standing->toArray());
    }

    /**
     * A merged account is shown merged ahead of every other fact (HIL-1292): the merge closed its
     * sign-in with the block flag, and that flag stays named, but what the account is now is the
     * tombstone, and where it went travels with the verdict.
     */
    public function testAMergeIsShownAheadOfTheBlockAndNamesWhereTheAccountWent(): void
    {
        $lapsed = [$this->lapsedTerms()];
        $standing = AccountStandingResolver::compose(true, 9, 'Survivor', true, 1000, $lapsed, [], LegalSettings::REFUSAL_FREEZE);

        self::assertSame(AccountStandingKind::MERGED, $standing->shown);
        self::assertTrue($standing->blocked);
        self::assertTrue($standing->frozen);
        self::assertSame(1000, $standing->deletionEffectiveAt);
        self::assertSame(9, $standing->toArray()['mergedInto']);
        self::assertSame('Survivor', $standing->toArray()['mergedIntoName']);
    }

    /**
     * A merge whose chain leads nowhere - the survivor erased before HIL-1200, or a loop - is still
     * shown merged, with nobody to point at.
     */
    public function testAMergeWithNoLiveEndIsShownMergedWithNobodyToPointAt(): void
    {
        $standing = AccountStandingResolver::compose(true, null, null, true, null, [], [], LegalSettings::REFUSAL_FREEZE);

        self::assertSame(AccountStandingKind::MERGED, $standing->shown);
        self::assertNull($standing->mergedInto);
        self::assertNull($standing->mergedIntoName);
    }

    public function testTheDocumentsInsideTheirWindowRideAlongAndTakeNothingAway(): void
    {
        $standing = AccountStandingResolver::compose(false, null, null, false, null, [], [$this->termsInWindow()], LegalSettings::REFUSAL_FREEZE);

        self::assertFalse($standing->frozen);
        self::assertSame(AccountStandingKind::NONE, $standing->shown);
        self::assertSame([], $standing->toArray()['lapsed']);
        self::assertSame([['document' => 'terms', 'deadline' => '2026-11-10']], $standing->toArray()['window']);
    }

    public function testAProcessWithoutADatabaseHoldsNothingAgainstAnyone(): void
    {
        Hilos::$db = null;
        Hilos::$setting = null;

        $standing = AccountStandingResolver::of(5);

        self::assertSame(AccountStandingKind::NONE, $standing->shown);
        self::assertFalse(AccountStandingResolver::isFrozen(5));
        self::assertSame([], AccountStandingResolver::lapsedUserIds(LegalDocument::TERMS));
    }

    /**
     * @return LegalDocumentStanding Terms held at their first revision, past the second's deadline
     */
    private function lapsedTerms(): LegalDocumentStanding
    {
        return new LegalDocumentStanding(
            LegalDocument::TERMS,
            LegalStanding::LAPSED,
            new LegalRevision(LegalDocument::TERMS, 'first', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
            '2026-03-01',
        );
    }

    /**
     * @return LegalDocumentStanding Terms held at their first revision, the second's deadline still ahead
     */
    private function termsInWindow(): LegalDocumentStanding
    {
        return new LegalDocumentStanding(
            LegalDocument::TERMS,
            LegalStanding::WINDOW,
            new LegalRevision(LegalDocument::TERMS, 'first', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
            '2026-11-10',
        );
    }
}
