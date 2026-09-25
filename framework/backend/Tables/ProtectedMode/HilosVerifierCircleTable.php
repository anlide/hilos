<?php

declare(strict_types=1);

namespace Hilos\Tables\ProtectedMode;

use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\VerifierCircleMember as ObjectVerifierCircleMember;
use Hilos\Database\View\Item\VerifierCircleMember as ViewVerifierCircleMember;
use Hilos\Hilos;
use Hilos\ProtectedMode\VerifierCircleSnapshot;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\State\Item\RtState;

/**
 * Table definition for the verifier circle: who an administrator named to check the system after
 * a freeze (HIL-643).
 *
 * The circle belongs to the freeze and not to backup: it serves every freeze, whatever operation
 * raised it (HIL-1022, HIL-1118). A framework table over a framework DB collection, in the shape
 * the settings table established. It delivers its own snapshot because one of its four fields is
 * not in the database at all: `online` is asked of the live connections while a row is built, so
 * a plain source projection would hand the browser a row with a hole in it.
 *
 * **The online mark is live (HIL-1119).** Besides the circle's own changes, the table hears the
 * project's collection of live session connections, found by the name the runtime context mounts
 * it under: a connection of a named person opening, closing or signing in re-draws that person's
 * row, so an administrator sees who has a tab open right now without reloading. The mark is only
 * the view: who is let in is still decided once, by the photograph the freeze takes
 * ({@see VerifierCircleSnapshot::capture()}).
 *
 * Two limits come from the way live tables work rather than from this one:
 *
 * - a person named by two addresses has only one of the two rows re-drawn live, because one
 *   source change gives a table exactly one row mutation ({@see TableContext::buildMutationSignalsForSourceEvent()});
 *   the other row catches up the next time it is drawn;
 * - signing out without closing the tab clears the binding in place, and the change carries only
 *   the new, empty binding ({@see RtState::sync()}), so it cannot name who left - that person's
 *   mark stays on until the tab closes or the page is opened again. The users table's presence
 *   has the same gap.
 */
final class HilosVerifierCircleTable extends TableDefinition implements SelfSnapshotTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosVerifierCircle';

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            self::DB_CIRCLE_SOURCE,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => self::DB_CIRCLE_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectVerifierCircleMember::id,
                BrowserTableFieldKey::FIELDS => [
                    ObjectVerifierCircleMember::id => HilosVerifierCircleTableRow::id,
                    ObjectVerifierCircleMember::identityType => HilosVerifierCircleTableRow::identityType,
                    ObjectVerifierCircleMember::identifier => HilosVerifierCircleTableRow::identifier,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    HilosVerifierCircleTableRow::online,
                ],
            ],
        ],
    ];

    private const array DB_CIRCLE_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::verifierCircle,
    ];

    /**
     * Declares how many rows the first window of the verifier circle carries.
     *
     * @return int Rows the first window carries
     */
    public function windowSize(): int
    {
        return 10;
    }

    /**
     * Declares the order the first window of the verifier circle runs in.
     *
     * @return ?TableSortOrderDTO First window ordered by identifier ascending
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosVerifierCircleTableRow::identifier));
    }

    /**
     * Builds a circle row mutation from a circle DB change or from a change of a live connection.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Circle row mutation, or null when the change does not affect this table
     * @throws DatabaseException When the circle or identity lookup fails
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a loaded object type does not match its collection
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey === HilosDbContext::verifierCircle) {
            return $this->mutationForMember($change);
        }

        // The connections collection is the project's, so its name is asked of the context
        // rather than written here; the process holds it without this table declaring it.
        $connections = Hilos::$rt?->sessionConnectionsSource();
        if ($connections !== null && $change->isRt() && $change->sourceKey === $connections->getCollectionName()) {
            return $this->mutationForConnection($change);
        }

        return null;
    }

    /**
     * Serializes one circle row into its internal browser-row envelope.
     *
     * One slot, named after the source, exactly as the settings table does - and the membership
     * id is dropped from it for the same reason the setting's is: the frontend normalizer reads
     * any slot object carrying a non-null `id` as an entity fragment and replaces it with a
     * reference, which would leave the view resolving its fields off a reference to a collection
     * nothing owns. Nothing replaces it either, because nothing is lost: the id IS the row key,
     * which travels beside the slot and is what a removal names.
     *
     * @param AbstractTableRow $row Circle row from this table's snapshot or mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        $slot = $row->toArray();
        unset($slot[HilosVerifierCircleTableRow::id]);

        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [
                HilosDbContext::verifierCircle => $slot,
            ],
        ];
    }

    /**
     * Builds the current table row for one membership.
     *
     * @param ViewVerifierCircleMember $member Membership to project
     * @return HilosVerifierCircleTableRow Circle table row payload
     * @throws DatabaseException When the identity lookup fails
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a loaded object type does not match its collection
     */
    public function rowFromMember(ViewVerifierCircleMember $member): HilosVerifierCircleTableRow
    {
        return new HilosVerifierCircleTableRow(
            id: (int)$member->id,
            identityType: $member->identityType,
            identifier: $member->identifier,
            online: $this->isOnline($member->identityType, $member->identifier),
        );
    }

    /**
     * Lists the whole circle, oldest membership first.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Circle table snapshot
     * @throws DatabaseException When the circle or identity lookup fails
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a loaded object type does not match its collection
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = [];
        foreach (Hilos::$db->verifierCircle->listAll() as $member) {
            $rows[] = $this->rowFromMember($member)->toArray();
        }

        return $this->filterInMemory($rows, $query);
    }

    /**
     * Declares what a circle row is searched by: the identity, and the kind of identity it is.
     *
     * @return array<string, string> Searched fields mapped to themselves, these rows being searched in memory
     */
    protected function searchableFields(): array
    {
        return [
            HilosVerifierCircleTableRow::identityType => HilosVerifierCircleTableRow::identityType,
            HilosVerifierCircleTableRow::identifier => HilosVerifierCircleTableRow::identifier,
        ];
    }

    /**
     * Configures the row shape used by the circle table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosVerifierCircleTableRow::class);
    }

    /**
     * Builds a circle row mutation from a circle DB source change.
     *
     * @param SourceChange $change Circle source change
     * @return ?TableRowMutationDTO Circle row mutation, or null when the change names no membership
     * @throws DatabaseException When the circle or identity lookup fails
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a loaded object type does not match its collection
     */
    private function mutationForMember(SourceChange $change): ?TableRowMutationDTO
    {
        $memberId = (int)$change->sourceId;
        if ($memberId <= 0) {
            return null;
        }

        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, $memberId);
        }

        $member = Hilos::$db->verifierCircle[$memberId] ?? null;

        return $member === null
            ? $this->mutation(TableMutationType::Delete, $memberId)
            : $this->mutation($change->mutationType, $memberId, $this->rowFromMember($member));
    }

    /**
     * Re-draws the row of the named person whose live connection changed.
     *
     * The person is read off the change: a removal carries the row the connection held, a creation
     * the whole new row, and an update the fields that moved. An update that leaves the binding
     * alone cannot change whether anybody holds a connection, so it re-draws nothing - a project
     * connection moves its own fields often (a file upload counts every chunk), and each of those
     * would otherwise cost the identity and circle lookups and a frame to every open window, for
     * a mark that cannot have changed. A connection change never adds or removes a row: the mark
     * is recomputed by {@see self::rowFromMember()} over the connections as they are after the
     * change, so closing one of two tabs keeps the person signed in.
     *
     * The person's addresses are walked in the order their identities were stored, and the first
     * one the circle names is the row re-drawn: one change yields one row mutation.
     *
     * @param SourceChange $change Change of the live connections collection
     * @return ?TableRowMutationDTO Update of the person's circle row, or null when the change binds
     *     nobody or its person is not named in the circle
     * @throws DatabaseException When the circle or identity lookup fails
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a loaded object type does not match its collection
     */
    private function mutationForConnection(SourceChange $change): ?TableRowMutationDTO
    {
        $userId = $change->row[HilosConnection::userId] ?? null;
        if (!is_int($userId) || $userId <= 0) {
            return null;
        }

        foreach (Hilos::$db->identities->listByUser($userId) as $identity) {
            $member = Hilos::$db->verifierCircle->findByIdentity($identity->type, $identity->identifier);
            if ($member !== null) {
                return $this->mutation(TableMutationType::Update, (int)$member->id, $this->rowFromMember($member));
            }
        }

        return null;
    }

    /**
     * Whether the person named by an identity pair holds a live connection right now.
     *
     * The pair is resolved to a person on every read rather than stored as a number, for the
     * reason the circle table has no `user_id` column: the number would name somebody else once
     * an archive is in place. An address nobody has proven answers false and stays in the list -
     * it was named, it is simply nobody yet.
     *
     * @param string $identityType Identity type the person was named under
     * @param string $identifier Address the person was named by
     * @return bool True when that person has at least one live connection on this node
     * @throws DatabaseException When the identity lookup fails
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a loaded object type does not match its collection
     */
    private function isOnline(string $identityType, string $identifier): bool
    {
        $identity = Hilos::$db->identities->findByIdentity($identityType, $identifier);
        if ($identity === null) {
            return false;
        }

        return (Hilos::$rt?->sessionConnectionsSource()?->findByUser($identity->userId) ?? []) !== [];
    }
}
