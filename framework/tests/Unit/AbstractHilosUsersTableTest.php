<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\ViewerFields;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Runtime\View\Collection\HilosPresenceSource;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\Tables\Users\AbstractHilosUsersTable;
use Hilos\Tables\Users\HilosUserTableRow;
use Hilos\Users\AccountErasureProgress;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for the framework Hilos users table that need no database.
 *
 * The presence source is bound by a test subclass; the cases over the people themselves -
 * reading them, building their rows, selecting them - read the framework's own table and live
 * in the integration suite (`HilosUsersTableIntegrationTest`).
 */
final class AbstractHilosUsersTableTest extends TestCase
{
    public function testUnrelatedSourceIsIgnored(): void
    {
        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::dbUpdated('other', '5', []),
        );

        $this->assertNull($mutation);
    }

    /**
     * A change the build threw for is named by the row it would have built, so only the windows
     * holding that row freeze (HIL-1353).
     */
    public function testAUserChangeIsNamedByTheUserId(): void
    {
        $this->assertSame(7, $this->table()->rowKeyForSourceEvent(SourceChange::dbUpdated(HilosDbContext::users, '7', [])));
    }

    public function testAUserChangeWithNoUserIdNamesNoRow(): void
    {
        $this->assertNull($this->table()->rowKeyForSourceEvent(SourceChange::dbUpdated(HilosDbContext::users, '0', [])));
    }

    public function testAPresenceChangeIsNamedByTheUserItCarries(): void
    {
        $this->assertSame(
            4,
            $this->table()->rowKeyForSourceEvent(SourceChange::rtCreated('connections', 'c-1', ['userId' => 4])),
        );
    }

    /**
     * A sign-out on a tab that stays open clears the binding in place: the user is named by the id
     * the update replaced, the one the build reads too (HIL-288).
     */
    public function testAPresenceChangeThatClearedItsUserIsNamedByThePreviousOne(): void
    {
        $this->assertSame(
            9,
            $this->table()->rowKeyForSourceEvent(
                SourceChange::rtUpdated('connections', 'c-1', ['userId' => null], previous: ['userId' => 9]),
            ),
        );
    }

    public function testAnUnrelatedChangeNamesNoRow(): void
    {
        $this->assertNull($this->table()->rowKeyForSourceEvent(SourceChange::dbUpdated('other', '5', [])));
    }

    /**
     * The merge flag rides its own inline slot, never the users slot: that one is normalized into
     * the person entity on the frontend, and a fact of the merge table would settle there (HIL-1292).
     */
    public function testBrowserRowSplitsIntoUserConnectionsAndMergeSlots(): void
    {
        $this->assertSame(
            [
                BrowserPageSignalData::rowKey => 5,
                BrowserPageSignalData::sources => [
                    AbstractHilosUsersTable::SLOT_USER => [
                        HilosUserTableRow::id => 5,
                        HilosUserTableRow::admin => false,
                        HilosUserTableRow::block => false,
                        HilosUserTableRow::name => 'Ann',
                        HilosUserTableRow::lastActivity => null,
                    ],
                    AbstractHilosUsersTable::SLOT_CONNECTIONS => [
                        HilosUserTableRow::presence => null,
                        HilosUserTableRow::onlineSessionCount => 0,
                        HilosUserTableRow::openSessionCount => 2,
                    ],
                    AbstractHilosUsersTable::SLOT_MERGE => [
                        HilosUserTableRow::merged => true,
                    ],
                ],
            ],
            $this->table()->browserRow(new HilosUserTableRow(5, name: 'Ann', merged: true, openSessionCount: 2)),
        );
    }

    public function testDistinctSessionCountIsNotPersonalAndSortable(): void
    {
        $table = $this->table();
        $this->assertContains(
            HilosUserTableRow::openSessionCount,
            ViewerFields::shownNames($table->wireFields(), static fn (string $collection, string $field): bool => false),
        );
        $sortFields = (new ReflectionMethod($table, 'sortableFields'))->invoke($table);
        $this->assertSame(
            HilosUserTableRow::openSessionCount,
            $sortFields[HilosUserTableRow::openSessionCount],
        );
        $this->assertSame(
            HilosUserTableRow::onlineSessionCount,
            $sortFields[HilosUserTableRow::onlineSessionCount],
        );
    }

    /**
     * The presence slot is assembled out of runtime rows, so it is the one that can go quiet;
     * the user slot is a database row and a cluster shares one database (HIL-800).
     */
    public function testBrowserRowNamesTheConnectionsSlotWhenPresenceIsFrozen(): void
    {
        $browserRow = $this->table(presenceStale: true)->browserRow(new HilosUserTableRow(5, name: 'Ann'));

        $this->assertSame(
            [AbstractHilosUsersTable::SLOT_CONNECTIONS],
            $browserRow[BrowserPageSignalData::staleSources],
        );
    }

    public function testBrowserRowOfAUserWhosePresenceIsCurrentCarriesNoFreshnessKey(): void
    {
        $browserRow = $this->table()->browserRow(new HilosUserTableRow(5, name: 'Ann'));
        $this->assertArrayNotHasKey(
            BrowserPageSignalData::staleSources,
            $browserRow,
        );
        $this->assertNull(
            $browserRow[BrowserPageSignalData::sources][AbstractHilosUsersTable::SLOT_CONNECTIONS][HilosUserTableRow::openSessionCount],
        );
    }

    /** The erasure bar stands under the account's row, counts the parts that are done and carries the card's block (HIL-1955). */
    public function testAnErasureBarStandsUnderTheAccountsRowAndCountsTheDoneParts(): void
    {
        $progress = new AccountErasureProgress(
            1790416800000,
            [
                ['participant' => 'personal', 'owner' => 'hilos_user', 'userId' => 41, 'done' => true],
                ['participant' => 'users_library', 'owner' => 'hilos_users_library', 'userId' => 41, 'done' => false],
                ['participant' => 'personal', 'owner' => 'hilos_user', 'userId' => 43, 'done' => false],
            ],
            [['participant' => 'delivery:mail', 'userId' => 41]],
            [['userId' => 43, 'table' => 'chat_message']],
        );

        $this->assertSame(
            [
                'scope' => 'row',
                'progressKey' => 'hilos-account-erasure',
                'current' => 1,
                'rowKey' => '41',
                'total' => 3,
                'detail' => $progress->toArray(),
            ],
            AbstractHilosUsersTable::erasureBar(41, $progress)->toArray(),
        );
    }

    /** An erasure with no part known yet has no estimate, and the frame that ends a bar carries only its address. */
    public function testAnErasureWithNoPartHasNoTotalAndItsEndCarriesNeitherTotalNorDetail(): void
    {
        $progress = new AccountErasureProgress(1790416800000, [], [['participant' => 'delivery:mail', 'userId' => 43]], []);

        $this->assertSame(
            ['scope' => 'row', 'progressKey' => 'hilos-account-erasure', 'current' => 0, 'rowKey' => '43', 'detail' => $progress->toArray()],
            AbstractHilosUsersTable::erasureBar(43, $progress)->toArray(),
        );
        $this->assertSame(
            ['scope' => 'row', 'progressKey' => 'hilos-account-erasure', 'current' => 0, 'rowKey' => '43', 'ended' => true],
            AbstractHilosUsersTable::erasureBarEnded(43)->toArray(),
        );
    }

    /** A viewer of the admin view mode reads the bar's detail by the card's own declaration of the block. */
    public function testTheErasureDetailIsDeclaredTheWayTheCardDeclaresIt(): void
    {
        $this->assertEquals(AccountErasureProgress::wireFields(), $this->table()->progressDetailFields());
    }

    /**
     * Builds a test users table bound to an in-memory presence source.
     *
     * @param bool $presenceStale Whether the bound presence source reports a frozen summary
     * @return AbstractHilosUsersTable Concrete table over a fixed presence key
     */
    private function table(bool $presenceStale = false): AbstractHilosUsersTable
    {
        return new class($presenceStale) extends AbstractHilosUsersTable {
            public function __construct(public bool $presenceStale)
            {
                parent::__construct();
            }

            protected function presenceSourceKey(): string
            {
                return 'connections';
            }

            protected function presenceSource(): HilosPresenceSource
            {
                return new class($this->presenceStale) implements HilosPresenceSource {
                    public function __construct(private readonly bool $stale)
                    {
                    }

                    public function summaryForUser(?int $userId): HilosUserPresenceSummary
                    {
                        return new HilosUserPresenceSummary($userId ?? 0, $this->stale);
                    }
                };
            }
        };
    }
}
