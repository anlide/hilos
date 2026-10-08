<?php

declare(strict_types=1);

namespace Hilos\Tables\Users;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\View\Item\User as DbUser;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\LegalDocument;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotReadableException;
use Hilos\Runtime\State\Item\HilosConnection as StateHilosConnection;
use Hilos\Runtime\View\Collection\HilosConnections as ViewHilosConnections;
use Hilos\Runtime\View\Collection\HilosPresenceSource;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\Users\AccountStandingResolver;
use OutOfBoundsException;
use Throwable;

/**
 * Base definition for the Hilos users table: the people of the framework's own table.
 *
 * The framework reads the people, builds their rows, and selects, sorts, and searches them; a
 * DB user create/update/delete projects to a row mutation, and a connection lifecycle event
 * refreshes the user's presence as an update. A project names one thing: the key of its runtime
 * connections collection, which is the project's own by the way runtime keys are laid out. How
 * presence is read out of that collection and how a connection leads to its person are defaults
 * here too; a project whose presence is a collection of another kind overrides them.
 */
abstract class AbstractHilosUsersTable extends TableDefinition implements ViewportTable
{
    /**
     * Wire slot the user entity rides — the entity-bearing slot the frontend
     * normalizer reduces to a reference (matches the FE users admin USER_SLOT).
     */
    public const string SLOT_USER = 'users';

    /**
     * Wire slot the inline runtime presence summary rides (matches the FE users
     * admin CONNECTIONS_SLOT). It carries no entity id, so it stays inline.
     */
    public const string SLOT_CONNECTIONS = 'connections';

    /**
     * Wire slot the merge flag rides, inline (matches the FE users admin MERGE_SLOT; HIL-1292).
     *
     * Not the users slot: that one is normalized into the person entity on the frontend, and a
     * fact of the people table would settle in the entity. The flag is read off the merge table,
     * not off a column of the person, and a database row has no second copy to fall behind, so
     * the slot is never stale.
     */
    public const string SLOT_MERGE = 'merge';

    /**
     * Filter-map key: narrow the people to those past the deadline of one legal document (HIL-945).
     *
     * The value is the document's key (`terms`, `privacy`). The people are those the legal section's
     * root counts in its third number for that document, whatever the refusal setting says, and the
     * list is judged the same way ({@see AccountStandingResolver::lapsedUserIds()}), so the link from
     * the number opens exactly the people it counted.
     */
    public const string FILTER_LAPSED = 'lapsed';

    /** The framework's people, as a browser source every project declares the same way. */
    public const array USERS_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::users,
    ];

    /**
     * The browser row of a person: what `hilos_user` puts into the users slot.
     *
     * A project's table joins it with the row of its own connections collection; the merge
     * candidates window reads the same row.
     */
    public const array USERS_ROW = [
        BrowserTableFieldKey::SOURCE => self::USERS_SOURCE,
        BrowserTableFieldKey::ROW_KEY => ObjectUser::id,
        BrowserTableFieldKey::FIELDS => [
            ObjectUser::id => HilosUserTableRow::id,
            ObjectUser::admin => HilosUserTableRow::admin,
            ObjectUser::block => HilosUserTableRow::block,
            ObjectUser::name => HilosUserTableRow::name,
            ObjectUser::lastActivity => HilosUserTableRow::lastActivity,
        ],
    ];

    /**
     * Declares how many rows the first window of the Hilos users table carries.
     *
     * @return int Rows the first window carries
     */
    public function windowSize(): int
    {
        return 10;
    }

    /**
     * Declares the order the first window of the Hilos users table runs in.
     *
     * @return ?TableSortOrderDTO First window ordered by id ascending
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosUserTableRow::id));
    }

    /**
     * Dispatches a DB user or presence source change to a users-table mutation.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Row mutation to fan out, or null when unaffected
     * @throws Throwable Propagated from the row builder or presence resolution
     */
    final public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey === HilosDbContext::users) {
            return $this->mutationForUser($change);
        }
        if ($change->sourceKey === $this->presenceSourceKey()) {
            return $this->mutationForPresence($change);
        }

        return null;
    }

    /**
     * Serializes one users-table row into its internal browser-row envelope.
     *
     * The window/delta path splits the typed row the same way the declarative
     * source fan-out does: the runtime presence fields ride the inline
     * {@see self::SLOT_CONNECTIONS} slot, the merge flag rides the inline
     * {@see self::SLOT_MERGE} slot, and the rest — the user identity and
     * profile fields — ride the {@see self::SLOT_USER} entity slot the frontend
     * resolves through its user collection (so a rename still fans out for free).
     *
     * Only the connections slot can go quiet. The user slot is a database row, and a cluster
     * shares one database — there is no second copy of it to fall behind (HIL-800). The
     * presence source is asked at serialization rather than read off the row, because the
     * freshness is a fact about the runtime rows the summary was drawn from and the row keeps
     * only the number they add up to.
     *
     * @param AbstractTableRow $row Users-table row from this table's window or mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>, staleSources?: list<string>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     * @throws HilosException When the presence source cannot be found or cannot read its runtime state
     */
    public function browserRow(AbstractTableRow $row): array
    {
        $fields = $row->toArray();
        $connections = [
            HilosUserTableRow::presence => $fields[HilosUserTableRow::presence] ?? null,
            HilosUserTableRow::onlineSessionCount => $fields[HilosUserTableRow::onlineSessionCount] ?? 0,
        ];
        $merge = [
            HilosUserTableRow::merged => $fields[HilosUserTableRow::merged] ?? false,
        ];
        unset(
            $fields[HilosUserTableRow::presence],
            $fields[HilosUserTableRow::onlineSessionCount],
            $fields[HilosUserTableRow::merged],
        );

        $rowKey = $row->requireRowKey();
        $browserRow = [
            BrowserPageSignalData::rowKey => $rowKey,
            BrowserPageSignalData::sources => [
                self::SLOT_USER => $fields,
                self::SLOT_CONNECTIONS => $connections,
                self::SLOT_MERGE => $merge,
            ],
        ];
        if ($this->presenceForUser((int) $rowKey)->stale) {
            $browserRow[BrowserPageSignalData::staleSources] = [self::SLOT_CONNECTIONS];
        }

        return $browserRow;
    }

    /**
     * Builds the Hilos users row from DB fields plus runtime presence, and the merge table's word on the account.
     *
     * The merge is read at the moment the row is built. A merge writes its row before the loser's
     * block flag, so the row rebuilt for that flag's change already sees the tombstone (HIL-1292).
     *
     * @param DbUser $user User DB item to project into the Hilos users table
     * @return HilosUserTableRow Runtime-enriched Hilos users table row
     * @throws HilosException When the presence source cannot be found or cannot read its runtime state, or the merge row cannot be read
     */
    public function rowFromUser(DbUser $user): HilosUserTableRow
    {
        $summary = $this->presenceForUser((int) $user->id);

        return new HilosUserTableRow(
            id: (int) $user->id,
            admin: $user->admin,
            block: $user->block,
            name: $user->name,
            lastActivity: $user->lastActivity,
            onlineSessionCount: $summary->onlineSessionCount,
            presence: $summary->presence,
            merged: Hilos::$db->userMerges[(int) $user->id] !== null,
        );
    }

    /**
     * The key of the project's runtime connections collection, which this table merges presence from.
     */
    abstract protected function presenceSourceKey(): string;

    /**
     * The presence source this table merges: the runtime collection under {@see self::presenceSourceKey()}.
     *
     * A project's connections are a collection of the framework's {@see ViewHilosConnections},
     * which reports presence, so the key is all the table needs. A project whose presence is a
     * collection of another kind overrides this.
     *
     * @return HilosPresenceSource Runtime collection reporting presence
     * @throws LogicException When the key names no collection that reports presence
     * @throws RtCollectionNotFoundException When nothing is mounted under the key
     * @throws RtCollectionNotReadableException When nothing in this process reads the collection, or its state is still on its way
     */
    protected function presenceSource(): HilosPresenceSource
    {
        $key = $this->presenceSourceKey();
        try {
            $source = Hilos::$rt->{$key};
        } catch (OutOfBoundsException $exception) {
            throw $this->notAPresenceSource($key, $exception);
        }
        if (!$source instanceof HilosPresenceSource) {
            throw $this->notAPresenceSource($key);
        }

        return $source;
    }

    /**
     * Resolves the user id affected by a presence (connection) source change.
     *
     * On create the row carries the user id; an update that clears the binding in place — a
     * sign-out on a tab that stays open — carries the id it replaced among its previous values
     * (HIL-288); an update that does not touch the binding carries neither, so the live
     * runtime row answers. A project whose presence is a collection of another kind overrides
     * this together with {@see self::presenceSource()}.
     *
     * @param SourceChange $change Presence source change
     * @return int Affected user id, or 0 when it cannot be resolved
     * @throws LogicException When the key names no collection that reports presence
     * @throws RtCollectionNotFoundException When nothing is mounted under the key
     * @throws RtCollectionNotReadableException When nothing in this process reads the collection, or its state is still on its way
     * @throws RtActionsStateCollectionNullException When runtime connection state is unavailable
     */
    protected function resolveUserIdForPresence(SourceChange $change): int
    {
        $userId = (int) ($change->row[StateHilosConnection::userId] ?? 0);
        if ($userId > 0) {
            return $userId;
        }

        $userId = (int) ($change->previous[StateHilosConnection::userId] ?? 0);
        if ($userId > 0) {
            return $userId;
        }

        $source = $this->presenceSource();

        return $source instanceof ViewHilosConnections ? ($source[$change->sourceId]?->userId ?? 0) : 0;
    }

    /**
     * Queries the people for the Hilos users table.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Hilos users table snapshot
     * @throws DatabaseException When user query execution fails
     * @throws SettingException When the refusal setting a lapsed filter reads is invalid
     * @throws RtActionsStateCollectionNullException When runtime connection state is unavailable
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     * @throws InvalidArgumentException When a loaded user object does not match the collection
     * @throws LogicException When the user collection is not configured, or the presence key names no collection that reports presence
     * @throws HilosException When the presence source cannot be found or cannot read its runtime state
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $result = Hilos::$db->users->queryPageItems(new TableQueryDTO());

        return $this->filterInMemory(
            rows: $this->narrowByLapsed(
                array_map(
                    fn(DbUser $user): array => $this->rowFromUser($user)->toArray(),
                    $result[TableConstants::RESULT_KEY_ROWS],
                ),
                $query->filter,
            ),
            query: $query,
        );
    }

    /**
     * Declares the sortable columns of the users row, which here are the row payload keys themselves.
     *
     * The rows are ordered in PHP by the in-memory filter, where a field name is an array key and
     * no identifier is built out of it. Presence and the session count are computed on the fly
     * and could not be handed to an index, but a set filtered in memory needs none
     * (`docs/agents/frontend/table-sort-orders.md`).
     *
     * @return array<string, string> Wire row fields mapped to the payload keys they order by
     */
    protected function sortableFields(): array
    {
        return [
            HilosUserTableRow::id => HilosUserTableRow::id,
            HilosUserTableRow::presence => HilosUserTableRow::presence,
            HilosUserTableRow::onlineSessionCount => HilosUserTableRow::onlineSessionCount,
            HilosUserTableRow::name => HilosUserTableRow::name,
            HilosUserTableRow::lastActivity => HilosUserTableRow::lastActivity,
        ];
    }

    /**
     * Declares what a user row is searched by: the name, the one field of the row written in words.
     *
     * @return array<string, string> Searched fields mapped to themselves, these rows being searched in memory
     */
    protected function searchableFields(): array
    {
        return [
            HilosUserTableRow::name => HilosUserTableRow::name,
        ];
    }

    /**
     * Declares where each field of a person's row comes from, for a viewer of the admin view mode (HIL-1254).
     *
     * The name is declared as a column and hidden by the column's verdict (FAKE_NAME) rather than by
     * omission in the map; the id, the admin and block flags and the last activity are non-personal by
     * their column verdicts; presence and onlineSessionCount are aggregates of runtime connections and
     * declared non-personal, as is whether the account was merged - a fact of the merge table, not a
     * column of the person (HIL-1292). The name being the one field searched, a viewer's window is
     * served without the search and without the order by name.
     *
     * @return array<string, WireField> Person's row field to where it comes from
     */
    public function wireFields(): array
    {
        return [
            HilosUserTableRow::id => WireField::column(HilosDbContext::users, ObjectUser::id),
            HilosUserTableRow::admin => WireField::column(HilosDbContext::users, ObjectUser::admin),
            HilosUserTableRow::block => WireField::column(HilosDbContext::users, ObjectUser::block),
            HilosUserTableRow::name => WireField::column(HilosDbContext::users, ObjectUser::name),
            HilosUserTableRow::lastActivity => WireField::column(HilosDbContext::users, ObjectUser::lastActivity),
            HilosUserTableRow::presence => WireField::notPersonal(),
            HilosUserTableRow::onlineSessionCount => WireField::notPersonal(),
            HilosUserTableRow::merged => WireField::notPersonal(),
        ];
    }

    /**
     * Configures the row shape used by the Hilos users table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosUserTableRow::class);
    }

    /**
     * Keeps the rows of the people past the deadline of the document the window filters on (HIL-945).
     *
     * The query hands its rows here before the in-memory filter, the way a table applies its
     * own filters. A window that names no document is not narrowed; a value that names no document
     * this framework knows empties it - a filter that cannot be judged shows nobody rather than
     * everybody.
     *
     * @param list<array<string, mixed>> $rows Rows of the whole table
     * @param array<string, mixed> $filters Open filter map of the window
     * @return list<array<string, mixed>> Rows the window asked for
     * @throws DatabaseException When the acceptance records or the setting cannot be read
     * @throws SettingException When the refusal setting is invalid
     */
    protected function narrowByLapsed(array $rows, array $filters): array
    {
        $value = $filters[self::FILTER_LAPSED] ?? null;
        if ($value === null) {
            return $rows;
        }
        $document = is_string($value) ? LegalDocument::tryFrom($value) : null;
        if ($document === null) {
            return [];
        }

        $lapsed = array_flip(AccountStandingResolver::lapsedUserIds($document));

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => isset($row[HilosUserTableRow::id]) && isset($lapsed[(int) $row[HilosUserTableRow::id]]),
        ));
    }

    /**
     * Summarizes a user's runtime presence through the bound presence source.
     *
     * @param int $userId User id to summarize
     * @return HilosUserPresenceSummary Presence and active session count
     * @throws HilosException When the presence source cannot be found or cannot read its runtime state
     */
    protected function presenceForUser(int $userId): HilosUserPresenceSummary
    {
        return $this->presenceSource()->summaryForUser($userId);
    }

    /**
     * Builds the current row for a user id, or null when the user is gone.
     *
     * @param int $userId User id to project into a row
     * @return ?HilosUserTableRow Current row, or null when the user no longer exists
     * @throws HilosException When the user or the presence source cannot be read
     */
    private function rowForUserId(int $userId): ?HilosUserTableRow
    {
        $dbUser = Hilos::$db->users[$userId] ?? null;

        return $dbUser === null ? null : $this->rowFromUser($dbUser);
    }

    /**
     * Builds a row mutation for a DB user create, update, or delete.
     *
     * @param SourceChange $change DB user source change
     * @return ?TableRowMutationDTO Row mutation, or null for an invalid id or missing user
     * @throws Throwable Propagated from {@see self::rowForUserId()}
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

        $row = $this->rowForUserId($userId);

        return $row === null ? null : $this->mutation($change->mutationType, $userId, $row);
    }

    /**
     * A presence change never removes a row; it refreshes the user's row as an
     * update with the recomputed online/presence aggregates.
     *
     * @param SourceChange $change Presence source change
     * @return ?TableRowMutationDTO Row update, or null when no user can be resolved
     * @throws Throwable Propagated from {@see self::rowForUserId()} or presence resolution
     */
    private function mutationForPresence(SourceChange $change): ?TableRowMutationDTO
    {
        $userId = $this->resolveUserIdForPresence($change);
        if ($userId <= 0) {
            return null;
        }

        $row = $this->rowForUserId($userId);

        return $row === null ? null : $this->mutation(TableMutationType::Update, $userId, $row);
    }

    /**
     * @param string $key Runtime key the project named
     * @param ?Throwable $previous Refusal of the key itself, when reading it raised one
     * @return LogicException Refusal naming the key and the table that named it
     */
    private function notAPresenceSource(string $key, ?Throwable $previous = null): LogicException
    {
        return new LogicException(
            message: "The users table presence source '{$key}' does not report presence: " . static::class
                . ' must name a runtime collection implementing ' . HilosPresenceSource::class,
            previous: $previous,
        );
    }
}
