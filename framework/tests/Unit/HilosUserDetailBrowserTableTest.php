<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\AccountDeletion;
use Hilos\Database\Object\Item\Identity;
use Hilos\Database\Object\Item\User;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\Tables\Users\AbstractHilosUsersTable;
use Hilos\Tables\Users\HilosMergeCandidatesTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\Tables\Users\HilosUserTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Declaration tests for the framework card of one person (HIL-1254).
 *
 * The card is a browser table every project registers as it is, so what it declares is the
 * framework's promise to each of them: the wire name and slots the frontend reads, the binding a
 * project drops into PAGE_TABLES, and the runtime key presence is read under. What the card sends
 * is covered by the projects' integration suites.
 */
final class HilosUserDetailBrowserTableTest extends TestCase
{
    private const array TABLE_USER_ID = [
        BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
        BrowserRefKey::KEY => HilosPageRouteParams::HILOS_USER_USER_ID,
    ];
    private const array ACCOUNT_DELETIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::accountDeletions,
    ];
    private const array CONNECTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => HilosUserDetailBrowserTable::CONNECTIONS,
    ];
    private const array IDENTITIES = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::identities,
    ];

    private const array SECOND_FACTORS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::secondFactors,
    ];

    public function testTheCardKeepsTheWireNamesTheFrontendReads(): void
    {
        $this->assertSame('userDetail', HilosUserDetailBrowserTable::TABLE);
        $this->assertSame('connections', HilosUserDetailBrowserTable::CONNECTIONS);
        $this->assertSame(
            [
                AbstractHilosUsersTable::USERS_SOURCE,
                self::ACCOUNT_DELETIONS,
                self::CONNECTIONS,
                self::IDENTITIES,
                self::SECOND_FACTORS,
            ],
            HilosUserDetailBrowserTable::BROWSER[BrowserTableConfigKey::SOURCES],
        );
    }

    public function testTheBindingHandsTheUserPageIdToTheCard(): void
    {
        $this->assertSame(
            [
                BrowserParamKey::PARAMS => [
                    HilosPageRouteParams::HILOS_USER_USER_ID => [
                        BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
                        BrowserRefKey::KEY => HilosPageRouteParams::HILOS_USER_USER_ID,
                    ],
                ],
            ],
            HilosUserDetailBrowserTable::BINDING,
        );
        $this->assertSame(
            [
                HilosPageRouteParams::HILOS_USER_USER_ID => [
                    BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                    BrowserParamKey::REQUIRED => true,
                ],
            ],
            HilosUserDetailBrowserTable::BROWSER[BrowserTableConfigKey::PARAMS],
        );
    }

    public function testThePersonRowCarriesTheUsersSlotFields(): void
    {
        $row = $this->rowOf(AbstractHilosUsersTable::USERS_SOURCE);

        // The person's fields are their columns', so the row opens nothing of its own.
        $this->assertArrayNotHasKey(BrowserTableFieldKey::NOT_PERSONAL, $row);

        $this->assertSame(User::id, $row[BrowserTableFieldKey::ROW_KEY]);
        $this->assertSame([User::id => self::TABLE_USER_ID], $row[BrowserTableFieldKey::WHERE]);
        $this->assertSame(
            [
                User::id => HilosUserTableRow::id,
                User::name => HilosUserTableRow::name,
                User::lastActivity => HilosUserTableRow::lastActivity,
                User::admin => HilosUserTableRow::admin,
                User::block => HilosUserTableRow::block,
            ],
            $row[BrowserTableFieldKey::FIELDS],
        );
    }

    public function testTheConnectionsRowComputesPresenceUnderTheFrameworkKey(): void
    {
        $row = $this->rowOf(self::CONNECTIONS);

        $this->assertSame(HilosConnection::userId, $row[BrowserTableFieldKey::ROW_KEY]);
        $this->assertSame([HilosConnection::userId => self::TABLE_USER_ID], $row[BrowserTableFieldKey::WHERE]);
        $this->assertSame([HilosConnection::userId], $row[BrowserTableFieldKey::FIELDS]);
        $this->assertSame(
            [HilosUserPresenceSummary::presence, HilosUserPresenceSummary::onlineSessionCount],
            $row[BrowserTableFieldKey::COMPUTED],
        );
        $this->assertSame(
            [HilosConnection::userId, HilosUserPresenceSummary::presence, HilosUserPresenceSummary::onlineSessionCount],
            $row[BrowserTableFieldKey::NOT_PERSONAL],
        );
    }

    public function testSurvivorPasswordPresenceDeclaresTheIdentitySource(): void
    {
        $row = $this->rowOf(self::IDENTITIES);

        $this->assertSame(Identity::userId, $row[BrowserTableFieldKey::ROW_KEY]);
        $this->assertSame([Identity::userId => self::TABLE_USER_ID], $row[BrowserTableFieldKey::WHERE]);
        $this->assertSame(
            [
                HilosMergeCandidatesTable::FIELD_HAS_PASSWORD,
                HilosMergeCandidatesTable::FIELD_UNVERIFIED_PASSWORD_ADDRESS,
            ],
            $row[BrowserTableFieldKey::COMPUTED],
        );
        $this->assertSame([HilosMergeCandidatesTable::FIELD_HAS_PASSWORD], $row[BrowserTableFieldKey::NOT_PERSONAL]);
    }

    public function testSecondFactorPresenceRemainsHiddenForViewers(): void
    {
        $row = $this->rowOf(self::SECOND_FACTORS);
        $this->assertSame([HilosMergeCandidatesTable::FIELD_HAS_SECOND_FACTOR], $row[BrowserTableFieldKey::COMPUTED]);
        $this->assertArrayNotHasKey(BrowserTableFieldKey::NOT_PERSONAL, $row);
    }

    public function testTheDeletionRowComputesTheScheduledDate(): void
    {
        $row = $this->rowOf(self::ACCOUNT_DELETIONS);

        $this->assertSame(AccountDeletion::userId, $row[BrowserTableFieldKey::ROW_KEY]);
        $this->assertSame([AccountDeletion::userId => self::TABLE_USER_ID], $row[BrowserTableFieldKey::WHERE]);
        $this->assertSame([HilosUserTableRow::FIELD_DELETION_EFFECTIVE_AT], $row[BrowserTableFieldKey::COMPUTED]);
        $this->assertSame([HilosUserTableRow::FIELD_DELETION_EFFECTIVE_AT], $row[BrowserTableFieldKey::NOT_PERSONAL]);
    }

    /**
     * @param array<string, string> $source Browser source the row reads
     * @return array<string, mixed> The one card row over that source
     */
    private function rowOf(array $source): array
    {
        $rows = array_values(array_filter(
            HilosUserDetailBrowserTable::BROWSER[BrowserTableConfigKey::ROWS],
            static fn (array $row): bool => ($row[BrowserTableFieldKey::SOURCE] ?? null) === $source,
        ));
        $this->assertCount(1, $rows);

        return $rows[0];
    }
}
