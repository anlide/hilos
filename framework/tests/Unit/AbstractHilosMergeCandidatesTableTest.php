<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Tables\Users\AbstractHilosMergeCandidatesTable;
use Hilos\Tables\Users\AbstractHilosUserTableRow;
use PHPUnit\Framework\TestCase;

/** Unit tests for the framework merge-candidate table engine (HIL-411). */
final class AbstractHilosMergeCandidatesTableTest extends TestCase
{
    public function testTheSurvivorAndProjectRejectedRowsAreAbsent(): void
    {
        $snapshot = $this->table()->getPage(new TableQueryDTO(
            filter: [AbstractHilosMergeCandidatesTable::FILTER_SURVIVOR => 1],
        ));

        self::assertSame([2, 12], array_map(static fn($row): int => $row->getRowKey(), $snapshot->rows));
    }

    public function testSearchMatchesASignInAddressSubstring(): void
    {
        $snapshot = $this->table()->getPage(new TableQueryDTO(search: 'beta@'));

        self::assertSame([2], array_map(static fn($row): int => $row->getRowKey(), $snapshot->rows));
    }

    public function testANumericSearchMatchesOnlyTheExactIdInAdditionToText(): void
    {
        $snapshot = $this->table()->getPage(new TableQueryDTO(search: '12'));

        self::assertSame([12], array_map(static fn($row): int => $row->getRowKey(), $snapshot->rows));
    }

    public function testBrowserRowSplitsTheUserAndMergeSlotsWithoutASecret(): void
    {
        $row = $this->table()->getPage(new TableQueryDTO(search: '2'))->rows[0];
        $browserRow = $this->table()->browserRow($row);

        self::assertSame(2, $browserRow[BrowserPageSignalData::rowKey]);
        self::assertSame('Beta', $browserRow[BrowserPageSignalData::sources]
            [AbstractHilosMergeCandidatesTable::SLOT_USER][AbstractHilosMergeCandidatesTable::FIELD_NAME]);
        self::assertArrayNotHasKey(
            AbstractHilosUserTableRow::presence,
            $browserRow[BrowserPageSignalData::sources][AbstractHilosMergeCandidatesTable::SLOT_USER],
        );
        self::assertArrayNotHasKey(
            AbstractHilosUserTableRow::onlineSessionCount,
            $browserRow[BrowserPageSignalData::sources][AbstractHilosMergeCandidatesTable::SLOT_USER],
        );
        self::assertTrue($browserRow[BrowserPageSignalData::sources]
            [AbstractHilosMergeCandidatesTable::SLOT_MERGE][AbstractHilosMergeCandidatesTable::FIELD_HAS_PASSWORD]);
        self::assertSame(
            'beta@example.test',
            $browserRow[BrowserPageSignalData::sources][AbstractHilosMergeCandidatesTable::SLOT_MERGE]
                [AbstractHilosMergeCandidatesTable::FIELD_IDENTITIES][0][ObjectIdentity::identifier],
        );
        self::assertArrayNotHasKey(
            'secret',
            $browserRow[BrowserPageSignalData::sources][AbstractHilosMergeCandidatesTable::SLOT_MERGE]
                [AbstractHilosMergeCandidatesTable::FIELD_IDENTITIES][0],
        );
    }

    public function testAnIdentityChangeRefreshesItsOwnersCandidateRow(): void
    {
        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::dbUpdated(HilosDbContext::identities, '99', [ObjectIdentity::identifier => 'changed']),
        );

        self::assertNotNull($mutation);
        self::assertSame(TableMutationType::Update, $mutation->type);
        self::assertSame(2, $mutation->rowKey);
    }

    public function testAUserThatBecameIneligibleIsDeletedFromTheCandidateTable(): void
    {
        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::dbUpdated('users', '3', ['mergedInto' => 1]),
        );

        self::assertNotNull($mutation);
        self::assertSame(TableMutationType::Delete, $mutation->type);
        self::assertSame(3, $mutation->rowKey);
    }

    private function table(): AbstractHilosMergeCandidatesTable
    {
        return new class extends AbstractHilosMergeCandidatesTable {
            protected function usersSourceKey(): string
            {
                return 'users';
            }

            protected function userIds(): iterable
            {
                return [1, 2, 3, 12];
            }

            protected function candidateRowForUserId(int $userId): ?AbstractHilosUserTableRow
            {
                if ($userId === 3) {
                    return null;
                }

                $names = [1 => 'Alpha', 2 => 'Beta', 12 => 'Twelve'];

                return new MergeCandidateTestUserRow($userId, $names[$userId] ?? 'Unknown');
            }

            protected function identityFieldsForUser(int $userId): array
            {
                return [[
                    ObjectIdentity::type => 'magic_link',
                    ObjectIdentity::identifier => match ($userId) {
                        1 => 'alpha@example.test',
                        2 => 'beta@example.test',
                        default => 'twelve@example.test',
                    },
                    ObjectIdentity::provider => null,
                    ObjectIdentity::verified => true,
                ]];
            }

            protected function hasPassword(int $userId): bool
            {
                return $userId === 2;
            }

            protected function identityOwnerUserId(int $identityId): int
            {
                return $identityId === 99 ? 2 : 0;
            }
        };
    }
}

/** Project user-row fixture carrying the name merge-candidate search reads. */
final class MergeCandidateTestUserRow extends AbstractHilosUserTableRow
{
    public function __construct(int $id, public string $name)
    {
        parent::__construct($id);
    }

    public function toArray(): array
    {
        return $this->baseFields() + [AbstractHilosMergeCandidatesTable::FIELD_NAME => $this->name];
    }

    /**
     * @param array<string, mixed> $data Raw user row
     * @return static Restored user row
     * @throws InvalidFormatException When id or name is missing
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireInt($data, self::id),
            self::requireString($data, AbstractHilosMergeCandidatesTable::FIELD_NAME),
        );
    }
}
