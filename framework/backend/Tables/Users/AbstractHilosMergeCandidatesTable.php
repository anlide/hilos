<?php

declare(strict_types=1);

namespace Hilos\Tables\Users;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
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
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\View\Item\Identity as DbIdentity;
use Hilos\Hilos;
use Hilos\HilosException;
use Throwable;

/**
 * Framework table of accounts that may be merged into the user whose card is open (HIL-411).
 *
 * A project supplies its user rows and decides whether one is a candidate; null from
 * {@see candidateRowForUserId()} means missing or already merged. The framework excludes the
 * survivor, enriches each candidate with safe identity metadata, and owns search and live
 * mutations for both the project user source and the framework identity source.
 */
abstract class AbstractHilosMergeCandidatesTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'mergeCandidates';
    public const string FILTER_SURVIVOR = 'survivor';
    public const string SLOT_USER = 'users';
    public const string SLOT_MERGE = 'merge';
    public const string FIELD_IDENTITIES = 'identities';
    public const string FIELD_HAS_PASSWORD = 'hasPassword';
    public const string FIELD_NAME = 'name';

    /** @return string Project DB user source key */
    abstract protected function usersSourceKey(): string;

    /**
     * @return iterable<int> Current project user ids, including rows the candidate seam may reject
     * @throws HilosException Whatever the project's user reader raises
     */
    abstract protected function userIds(): iterable;

    /**
     * @param int $userId User id to project
     * @return ?AbstractHilosUserTableRow Candidate row, or null when missing or not mergeable
     * @throws HilosException Whatever the project's row builder raises
     */
    abstract protected function candidateRowForUserId(int $userId): ?AbstractHilosUserTableRow;

    /** @return int Rows the first candidate window carries */
    public function windowSize(): int
    {
        return 10;
    }

    /** @return ?TableSortOrderDTO First window ordered by user id ascending */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(AbstractHilosUserTableRow::id));
    }

    /**
     * @param SourceChange $change Project-user or identity source change
     * @return ?TableRowMutationDTO Candidate mutation, or null when unaffected
     * @throws Throwable Whatever the project row or identity readers raise
     */
    final public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey === $this->usersSourceKey()) {
            return $this->mutationForUser($change);
        }
        if ($change->sourceKey === HilosDbContext::identities) {
            return $this->mutationForIdentity($change);
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
     * @throws Throwable Whatever the project row or identity readers raise
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
     * @throws HilosException Whatever the project row or identity readers raise
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
            AbstractHilosUserTableRow::id => AbstractHilosUserTableRow::id,
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
     * @param int $userId Owning user id
     * @return list<array{type: string, identifier: string, provider: ?string, verified: bool}> Safe identity metadata
     * @throws HilosException When identities cannot be read
     */
    protected function identityFieldsForUser(int $userId): array
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
    protected function hasPassword(int $userId): bool
    {
        return Hilos::$db->identities->findPasswordByUser($userId) !== null;
    }

    /**
     * @param int $identityId Identity row id
     * @return int Owning user id, or 0 when the identity is gone
     * @throws HilosException When the identity cannot be read
     */
    protected function identityOwnerUserId(int $identityId): int
    {
        return (int) (Hilos::$db->identities[$identityId]?->userId ?? 0);
    }

    /**
     * @param SourceChange $change Project-user source change
     * @return ?TableRowMutationDTO Candidate mutation
     * @throws Throwable Whatever the project row or identity readers raise
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
     * @throws Throwable Whatever the project row or identity readers raise
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
     * @param AbstractHilosUserTableRow $user Project user row
     * @param ?string $search Current search term, used only for exact numeric-id matching
     * @return HilosMergeCandidateTableRow Enriched candidate row
     * @throws HilosException When identities cannot be read
     */
    private function candidateRow(AbstractHilosUserTableRow $user, ?string $search = null): HilosMergeCandidateTableRow
    {
        $identities = $this->identityFieldsForUser($user->id);
        $userFields = $user->toArray();
        unset(
            $userFields[AbstractHilosUserTableRow::presence],
            $userFields[AbstractHilosUserTableRow::onlineSessionCount],
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
