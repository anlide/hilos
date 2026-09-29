<?php

declare(strict_types=1);

namespace Hilos\Tables\Users;

use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Actions\Item\UserActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\Object\Item\UserMerge as ObjectUserMerge;
use Hilos\Database\View\Item\Identity as DbIdentity;
use Hilos\Database\View\Item\User as DbUser;
use Hilos\Hilos;
use Hilos\HilosException;
use Throwable;

/**
 * Framework table of accounts that may be merged into the user whose card is open (HIL-411).
 *
 * The window is the framework's whole: it reads the people of `hilos_user`, leaves out the
 * survivor and every account already folded into another one (the framework merge table,
 * HIL-1199), enriches each candidate with safe identity metadata, and owns search and live
 * mutations over the people, the identities, and the merges. A project registers the class as
 * it is.
 */
final class HilosMergeCandidatesTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'mergeCandidates';
    public const string FILTER_SURVIVOR = 'survivor';
    public const string SLOT_USER = 'users';
    public const string SLOT_MERGE = 'merge';
    public const string FIELD_IDENTITIES = 'identities';
    public const string FIELD_HAS_PASSWORD = 'hasPassword';
    public const string FIELD_NAME = 'name';
    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            AbstractHilosUsersTable::USERS_SOURCE,
            self::IDENTITIES_SOURCE,
            self::MERGES_SOURCE,
        ],
        BrowserTableConfigKey::ROWS => [
            AbstractHilosUsersTable::USERS_ROW,
            [
                BrowserTableFieldKey::SOURCE => self::IDENTITIES_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectIdentity::userId,
                BrowserTableFieldKey::MANY => true,
                BrowserTableFieldKey::FIELDS => [
                    ObjectIdentity::userId,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => self::MERGES_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectUserMerge::userId,
                BrowserTableFieldKey::FIELDS => [
                    ObjectUserMerge::userId,
                ],
            ],
        ],
    ];

    private const array IDENTITIES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::identities,
    ];
    private const array MERGES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::userMerges,
    ];

    /** @return int Rows the first candidate window carries */
    public function windowSize(): int
    {
        return 10;
    }

    /** @return ?TableSortOrderDTO First window ordered by user id ascending */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosUserTableRow::id));
    }

    /**
     * @param SourceChange $change People, identity, or merge source change
     * @return ?TableRowMutationDTO Candidate mutation, or null when unaffected
     * @throws Throwable Whatever the user or identity readers raise
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey === HilosDbContext::users) {
            return $this->mutationForUser($change);
        }
        if ($change->sourceKey === HilosDbContext::identities) {
            return $this->mutationForIdentity($change);
        }
        if ($change->sourceKey === HilosDbContext::userMerges) {
            return $this->mutationForMerge($change);
        }

        return null;
    }

    /**
     * @param AbstractTableRow $row Candidate row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Browser-row envelope
     * @throws LogicException When another table row type is passed
     * @throws TableRowKeyMissingException When the candidate has no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        if (!$row instanceof HilosMergeCandidateTableRow) {
            throw new LogicException('Merge candidates table row must be ' . HilosMergeCandidateTableRow::class);
        }

        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [
                self::SLOT_USER => $row->userFields,
                self::SLOT_MERGE => [
                    self::FIELD_IDENTITIES => $row->identities,
                    self::FIELD_HAS_PASSWORD => $row->hasPassword,
                ],
            ],
        ];
    }

    /**
     * @param string|int $rowKey Candidate user id
     * @param TableQueryDTO $query Window query
     * @return ?bool Whether the candidate belongs to the filtered and searched set
     * @throws Throwable Whatever the user or identity readers raise
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        $userId = (int) $rowKey;
        $survivorId = self::survivorId($query);
        if ($userId <= 0 || $userId === $survivorId) {
            return false;
        }

        $user = $this->candidateRowForUserId($userId);
        if ($user === null) {
            return false;
        }

        return $this->containsRowInMemory(
            [$this->candidateRow($user, $query->search)->toArray()],
            $userId,
            $this->scopeSearch($query),
        );
    }

    /**
     * @param TableQueryDTO $query Window query
     * @return TableSnapshotDTO Candidate window
     * @throws HilosException Whatever the user or identity readers raise
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $survivorId = self::survivorId($query);
        $rows = [];
        foreach ($this->userIds() as $userId) {
            if ($userId === $survivorId) {
                continue;
            }
            $user = $this->candidateRowForUserId($userId);
            if ($user === null) {
                continue;
            }
            $rows[] = $this->candidateRow($user, $query->search)->toArray();
        }

        return $this->filterInMemory($rows, $query);
    }

    /** @return array<string, string> Sortable candidate fields */
    protected function sortableFields(): array
    {
        return [
            HilosUserTableRow::id => HilosUserTableRow::id,
            self::FIELD_NAME => self::FIELD_NAME,
        ];
    }

    /** @return array<string, string> Name, sign-in address, and exact numeric-id search fields */
    protected function searchableFields(): array
    {
        return [
            self::FIELD_NAME => self::FIELD_NAME,
            HilosMergeCandidateTableRow::identityAddresses => HilosMergeCandidateTableRow::identityAddresses,
            HilosMergeCandidateTableRow::exactUserId => HilosMergeCandidateTableRow::exactUserId,
        ];
    }

    /** Configures the framework-owned candidate row shape. */
    protected function init(): void
    {
        $this->setRowClass(HilosMergeCandidateTableRow::class);
    }

    /**
     * @return list<int> Current user ids, including merged accounts the candidate reader rejects
     * @throws DatabaseException When the user query fails
     * @throws InvalidArgumentException When a loaded user object does not match the collection
     * @throws LogicException When the user collection is not configured
     * @throws TableSearchFieldUnknownException When a search field is absent from the user row
     * @throws TableSearchNotSupportedException When a search term has no searchable fields
     */
    private function userIds(): array
    {
        $result = Hilos::$db->users->queryPageItems(new TableQueryDTO());

        return array_map(
            static fn(DbUser $user): int => (int) $user->id,
            $result[TableConstants::RESULT_KEY_ROWS],
        );
    }

    /**
     * @param int $userId User id to project
     * @return ?HilosUserTableRow Candidate row, or null for a missing or merged account
     * @throws HilosException When the user or the merge record cannot be read
     */
    private function candidateRowForUserId(int $userId): ?HilosUserTableRow
    {
        $user = Hilos::$db->users[$userId] ?? null;
        if ($user === null || Hilos::$db->userMerges[$userId] !== null) {
            return null;
        }

        return new HilosUserTableRow(
            id: (int) $user->id,
            admin: $user->admin,
            block: $user->block,
            name: $user->name,
            lastActivity: $user->lastActivity,
        );
    }

    /**
     * @param int $userId Owning user id
     * @return list<array{type: string, identifier: string, provider: ?string, verified: bool}> Safe identity metadata
     * @throws HilosException When identities cannot be read
     */
    private function identityFieldsForUser(int $userId): array
    {
        return array_map(
            static fn(DbIdentity $identity): array => [
                ObjectIdentity::type => $identity->type,
                ObjectIdentity::identifier => $identity->identifier,
                ObjectIdentity::provider => $identity->provider,
                ObjectIdentity::verified => $identity->verified,
            ],
            Hilos::$db->identities->listByUser($userId),
        );
    }

    /**
     * @param int $userId Owning user id
     * @return bool Whether the account has a password identity
     * @throws HilosException When identities cannot be read
     */
    private function hasPassword(int $userId): bool
    {
        return Hilos::$db->identities->findPasswordByUser($userId) !== null;
    }

    /**
     * @param int $identityId Identity row id
     * @return int Owning user id, or 0 when the identity is gone
     * @throws HilosException When the identity cannot be read
     */
    private function identityOwnerUserId(int $identityId): int
    {
        return (int) (Hilos::$db->identities[$identityId]?->userId ?? 0);
    }

    /**
     * @param SourceChange $change People source change
     * @return ?TableRowMutationDTO Candidate mutation
     * @throws Throwable Whatever the user or identity readers raise
     */
    private function mutationForUser(SourceChange $change): ?TableRowMutationDTO
    {
        $userId = (int) $change->sourceId;
        if ($userId <= 0) {
            return null;
        }
        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, $userId);
        }

        $user = $this->candidateRowForUserId($userId);
        if ($user === null) {
            return $change->mutationType === TableMutationType::Update
                ? $this->mutation(TableMutationType::Delete, $userId)
                : null;
        }

        return $this->mutation($change->mutationType, $userId, $this->candidateRow($user));
    }

    /**
     * @param SourceChange $change Framework identity source change
     * @return ?TableRowMutationDTO Candidate update, or null when no owner can be resolved
     * @throws Throwable Whatever the user or identity readers raise
     */
    private function mutationForIdentity(SourceChange $change): ?TableRowMutationDTO
    {
        $userId = (int) ($change->row[ObjectIdentity::userId] ?? 0);
        if ($userId <= 0 && ctype_digit($change->sourceId)) {
            $userId = $this->identityOwnerUserId((int) $change->sourceId);
        }
        if ($userId <= 0) {
            return null;
        }

        $user = $this->candidateRowForUserId($userId);

        return $user === null
            ? $this->mutation(TableMutationType::Delete, $userId)
            : $this->mutation(TableMutationType::Update, $userId, $this->candidateRow($user));
    }

    /**
     * Takes a folded account out of the window the moment its merge row is written (P-449).
     *
     * A merge does not always change the person's own row: it blocks the loser, and a loser that
     * was blocked before is not written again ({@see UserActions::setBlock()} writes nothing when
     * the flag does not change), so the people source may stay silent and the merge row is the
     * one fact that the account left the set. Removing a merge row is not the other way round - it happens only when the folded
     * account itself is erased, and its person row leaves through the people source right after.
     *
     * @param SourceChange $change Framework merge source change
     * @return ?TableRowMutationDTO Removal of the folded account, or null for a removed merge row or an unknown account
     */
    private function mutationForMerge(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->mutationType === TableMutationType::Delete) {
            return null;
        }

        $userId = (int) ($change->row[ObjectUserMerge::userId] ?? $change->sourceId);
        if ($userId <= 0) {
            return null;
        }

        return $this->mutation(TableMutationType::Delete, $userId);
    }

    /**
     * @param HilosUserTableRow $user User row of the candidate
     * @param ?string $search Current search term, used only for exact numeric-id matching
     * @return HilosMergeCandidateTableRow Enriched candidate row
     * @throws HilosException When identities cannot be read
     */
    private function candidateRow(HilosUserTableRow $user, ?string $search = null): HilosMergeCandidateTableRow
    {
        $identities = $this->identityFieldsForUser($user->id);
        $userFields = $user->toArray();
        unset(
            $userFields[HilosUserTableRow::presence],
            $userFields[HilosUserTableRow::onlineSessionCount],
        );
        $normalizedSearch = $search === null ? null : trim($search);

        return new HilosMergeCandidateTableRow(
            userFields: $userFields,
            identities: $identities,
            hasPassword: $this->hasPassword($user->id),
            exactUserId: $normalizedSearch !== null
                && ctype_digit($normalizedSearch)
                && (int) $normalizedSearch === $user->id
                    ? $user->id
                    : null,
        );
    }

    /**
     * @param TableQueryDTO $query Candidate window query
     * @return int Survivor user id, or 0 when the preset is absent or invalid
     */
    private static function survivorId(TableQueryDTO $query): int
    {
        $value = $query->filter[self::FILTER_SURVIVOR] ?? null;
        if (!is_int($value) && !is_string($value)) {
            return 0;
        }

        $trimmed = trim((string) $value);

        return ctype_digit($trimmed) ? (int) $trimmed : 0;
    }
}
