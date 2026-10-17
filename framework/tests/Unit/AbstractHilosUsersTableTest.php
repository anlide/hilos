<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Runtime\View\Collection\HilosPresenceSource;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\Tables\Users\AbstractHilosUsersTable;
use Hilos\Tables\Users\HilosUserTableRow;
use PHPUnit\Framework\TestCase;

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
                    ],
                    AbstractHilosUsersTable::SLOT_MERGE => [
                        HilosUserTableRow::merged => true,
                    ],
                ],
            ],
            $this->table()->browserRow(new HilosUserTableRow(5, name: 'Ann', merged: true)),
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
        $this->assertArrayNotHasKey(
            BrowserPageSignalData::staleSources,
            $this->table()->browserRow(new HilosUserTableRow(5, name: 'Ann')),
        );
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
