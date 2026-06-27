<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Runtime\View\Collection\HilosPresenceSource;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\Tables\Users\AbstractHilosUsersTable;
use Hilos\Tables\Users\AbstractHilosUserTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the framework Hilos users table presence-merge engine.
 *
 * Source keys, the row builder, and presence resolution are bound by a test
 * subclass; the assertions exercise the framework dispatch only.
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

    public function testDbUserDeleteProjectsDeleteMutation(): void
    {
        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::dbDeleted('users', '5'),
        );

        $this->assertNotNull($mutation);
        $this->assertSame(TableMutationType::Delete, $mutation->type);
        $this->assertSame(5, $mutation->rowKey);
        $this->assertNull($mutation->row);
    }

    public function testDbUserUpdateProjectsRowMutation(): void
    {
        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::dbUpdated('users', '5', []),
        );

        $this->assertNotNull($mutation);
        $this->assertSame(TableMutationType::Update, $mutation->type);
        $this->assertSame(5, $mutation->rowKey);
        $this->assertNotNull($mutation->row);
        $this->assertSame(5, $mutation->row->getRowKey());
    }

    public function testInvalidUserIdIsIgnored(): void
    {
        $this->assertNull(
            $this->table()->buildMutationForSourceEvent(SourceChange::dbUpdated('users', '0', [])),
        );
    }

    public function testMissingUserIsIgnored(): void
    {
        $this->assertNull(
            $this->table(missingUserId: 99)->buildMutationForSourceEvent(
                SourceChange::dbUpdated('users', '99', []),
            ),
        );
    }

    public function testPresenceChangeRefreshesRowAsUpdate(): void
    {
        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::rtUpdated('connections', 'accept-key', ['userId' => 7]),
        );

        $this->assertNotNull($mutation);
        $this->assertSame(TableMutationType::Update, $mutation->type);
        $this->assertSame(7, $mutation->rowKey);
        $this->assertNotNull($mutation->row);
    }

    public function testPresenceChangeWithoutUserIsIgnored(): void
    {
        $this->assertNull(
            $this->table()->buildMutationForSourceEvent(
                SourceChange::rtUpdated('connections', 'accept-key', []),
            ),
        );
    }

    public function testBrowserRowSplitsIntoUserAndConnectionsSlots(): void
    {
        $table = $this->table();
        $mutation = $table->buildMutationForSourceEvent(SourceChange::dbUpdated('users', '5', []));
        $this->assertNotNull($mutation);
        $this->assertNotNull($mutation->row);

        $this->assertSame(
            [
                BrowserPageSignalData::rowKey => 5,
                BrowserPageSignalData::sources => [
                    AbstractHilosUsersTable::SLOT_USER => [
                        AbstractHilosUserTableRow::id => 5,
                        AbstractHilosUserTableRow::admin => false,
                        AbstractHilosUserTableRow::block => false,
                    ],
                    AbstractHilosUsersTable::SLOT_CONNECTIONS => [
                        AbstractHilosUserTableRow::presence => null,
                        AbstractHilosUserTableRow::onlineSessionCount => 0,
                    ],
                ],
            ],
            $table->browserRow($mutation->row),
        );
    }

    /**
     * Builds a test users table bound to in-memory sources.
     *
     * @param ?int $missingUserId User id the row builder treats as gone, or null
     * @return AbstractHilosUsersTable Concrete table over fixed source keys
     */
    private function table(?int $missingUserId = null): AbstractHilosUsersTable
    {
        return new class($missingUserId) extends AbstractHilosUsersTable {
            public function __construct(public ?int $missingUserId)
            {
                parent::__construct();
            }

            protected function usersSourceKey(): string
            {
                return 'users';
            }

            protected function presenceSourceKey(): string
            {
                return 'connections';
            }

            protected function presenceSource(): HilosPresenceSource
            {
                return new class implements HilosPresenceSource {
                    public function summaryForUser(?int $userId): HilosUserPresenceSummary
                    {
                        return new HilosUserPresenceSummary($userId ?? 0);
                    }
                };
            }

            protected function rowForUserId(int $userId): ?AbstractHilosUserTableRow
            {
                if ($userId === $this->missingUserId) {
                    return null;
                }

                return new class($userId) extends AbstractHilosUserTableRow {
                    public function toArray(): array
                    {
                        return $this->baseFields();
                    }

                    // TODO: fix me. Wrong return type
                    public static function fromArray(array $data): static
                    {
                        return new self((int) ($data[self::id] ?? 0));
                    }
                };
            }

            protected function resolveUserIdForPresence(SourceChange $change): int
            {
                return (int) ($change->row['userId'] ?? 0);
            }

            protected function query(TableQueryDTO $query): TableSnapshotDTO
            {
                return new TableSnapshotDTO();
            }
        };
    }
}
