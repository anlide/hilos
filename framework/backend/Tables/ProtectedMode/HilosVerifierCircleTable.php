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
use Hilos\Core\Table\DTO\TableSortDTO;
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

/**
 * Table definition for the verifier circle: who an operator named to check a restore (HIL-643).
 *
 * A framework table over a framework DB collection, in the shape the settings table established.
 * It delivers its own snapshot because one of its four fields is not in the database at all:
 * `online` is asked of the live connections while a row is built, so a plain source projection
 * would hand the browser a row with a hole in it.
 *
 * **The online mark is a photograph, not a subscription.** It refreshes when the row does - a
 * membership added, removed, or re-drawn on subscribe - and no connection event moves it. That is
 * the honest shape rather than a saving: the mark answers "was this person here just now", and the
 * question that decides anything is asked once, by the freeze
 * ({@see VerifierCircleSnapshot::capture()}). A mark tracking every socket would look like a
 * promise the circle does not make.
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
     * Builds a circle row mutation from a circle DB source change.
     *
     * @param SourceChange $change Circle source change
     * @return ?TableRowMutationDTO Circle row mutation, or null when the change does not affect this table
     * @throws DatabaseException When the circle or identity lookup fails
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a loaded object type does not match its collection
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== HilosDbContext::verifierCircle) {
            return null;
        }

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
     * Configures the row shape used by the circle table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosVerifierCircleTableRow::class);
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
