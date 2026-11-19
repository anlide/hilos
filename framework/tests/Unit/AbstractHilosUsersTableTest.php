<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\ViewerFields;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Table\Definition\MultiRowViewportTable;
use Hilos\Tables\Users\HilosMergeCandidatesTable;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Runtime\View\Collection\HilosPresenceSource;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\Tables\Users\AbstractHilosUsersTable;
use Hilos\Tables\Users\HilosUserTableRow;
use Hilos\Users\AccountErasureProgress;
use Hilos\Users\AccountMergeProgress;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Constants\HilosAgentType;
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
    public function testEveryProjectObservesAllMergeDependenciesWithoutProjectingRawMetadata(): void
    {
        $keys = ['accountOperations', 'accountOperationAssignments', 'accountOperationResults', 'accountDeletions', 'userMerges'];
        self::assertSame($keys, array_column(AbstractHilosUsersTable::MERGE_SOURCES, BrowserSourceKey::KEY));
        self::assertSame(['id', 'id', 'id', 'userId', 'userId'],
            array_column(AbstractHilosUsersTable::MERGE_ROWS, BrowserTableFieldKey::ROW_KEY));
        self::assertCount(5, AbstractHilosUsersTable::MERGE_ROWS);
        foreach (AbstractHilosUsersTable::MERGE_ROWS as $index => $row) {
            self::assertSame([
                BrowserTableFieldKey::SOURCE => [BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => $keys[$index]],
                BrowserTableFieldKey::ROW_KEY => $index < 3 ? 'id' : 'userId',
                BrowserTableFieldKey::FIELDS => [],
            ], $row);
        }
        $projects = ['binance-btc-tracker', 'chat', 'concert-tickets', 'ecommerce-shop', 'file-gallery-manager', 'online-testing',
            'pmd', 'polls', 'preferans', 'roblox-game-server', 'solar-system-model', 'tasks'];
        foreach ($projects as $project) {
            $path = dirname(__DIR__, 3) . '/demo/' . $project . '/backend/Tables/HilosUser/HilosUsersTable.php';
            $source = (string) file_get_contents($path);
            self::assertMatchesRegularExpression('/BrowserTableConfigKey::SOURCES => \[\s*self::USERS_SOURCE,\s*'
                . '\\.\\.\\.self::MERGE_SOURCES,/', $source, $project);
            self::assertMatchesRegularExpression('/BrowserTableConfigKey::ROWS => \[\s*self::USERS_ROW,\s*'
                . '\\.\\.\\.self::MERGE_ROWS,/', $source, $project);
            self::assertStringContainsString('BrowserTableFieldKey::COMPUTED', $source, $project);
        }
        self::assertInstanceOf(MultiRowViewportTable::class, $this->table());
        self::assertInstanceOf(MultiRowViewportTable::class, new HilosMergeCandidatesTable());
        $candidateSources = HilosMergeCandidatesTable::BROWSER[BrowserTableConfigKey::SOURCES];
        self::assertSame(['users', 'identities', 'secondFactors', ...$keys], array_column($candidateSources, BrowserSourceKey::KEY));
        $candidateRows = HilosMergeCandidatesTable::BROWSER[BrowserTableConfigKey::ROWS];
        self::assertSame($candidateSources[0], $candidateRows[0][BrowserTableFieldKey::SOURCE]);
        self::assertSame(AbstractHilosUsersTable::MERGE_ROWS, array_slice($candidateRows, 1, 5));
        self::assertCount(8, $candidateRows);
    }

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
                        HilosUserTableRow::FIELD_ACCOUNT_MERGE_AVAILABILITY => null,
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
        $fields = $this->table()->progressDetailFields();
        $this->assertEquals(AccountErasureProgress::wireFields() + AccountMergeProgress::wireFields(), $fields);
        foreach (['parts', 'waits'] as $key) {
            self::assertEquals(AccountErasureProgress::wireFields()[$key], AccountMergeProgress::wireFields()[$key]);
            self::assertEquals(AccountMergeProgress::wireFields()[$key], $fields[$key]);
        }
    }

    public function testMergeBarsCountOnlyDonePartsAndEqualityDoesNotEndWork(): void
    {
        $parts = [
            ['participant' => 'prepare_survivor', 'owner' => HilosAgentType::HILOS_USER, 'userId' => 41, 'done' => true],
            ['participant' => 'prepare_loser', 'owner' => HilosAgentType::HILOS_USER, 'userId' => 43, 'done' => false],
        ];
        foreach ([[], $parts, [$parts[0]]] as $known) {
            $progress = new AccountMergeProgress(7, 41, 43, 'preparing', 'preparing', null, false, false,
                new AccountMergeChoice(null, null, null, null), null, $known, []);
            $bar = AbstractHilosUsersTable::mergeBar(43, $progress);
            self::assertSame($known === [] ? 0 : 1, $bar->current);
            self::assertSame($known === [] ? null : count($known), $bar->total);
            self::assertFalse($bar->ended);
            self::assertSame([
                'scope' => 'row', 'progressKey' => 'hilos-account-merge', 'current' => $bar->current, 'rowKey' => '43',
                ...($known === [] ? [] : ['total' => count($known)]), 'detail' => $progress->toArray(),
            ], $bar->toArray());
            self::assertSame($bar->toArray(), TableProgressDTO::fromArray($bar->toArray())->toArray());
        }
        self::assertSame([
            'scope' => 'row', 'progressKey' => 'hilos-account-merge', 'current' => 0, 'rowKey' => '43', 'ended' => true,
        ], AbstractHilosUsersTable::mergeBarEnded(43)->toArray());
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
