<?php

declare(strict_types=1);

namespace Hilos\Tables\Daemon;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\Hilos;
use Hilos\Log\AgentLogStream;
use Hilos\Pages\Daemon\AbstractHilosDaemonAgentsPage;

/**
 * Started agents of one node, projected from the page worker's daemon picture mirror.
 *
 * Ownership comes from declarations on each agent class, not the picture: all nodes run
 * the same code. A viewport without a node filter is empty. The mirror has no row source,
 * so {@see AbstractHilosDaemonAgentsPage::onPictureChanged()} resends complete windows
 * after a changed process portion.
 */
final class HilosDaemonAgentsTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosDaemonAgents';
    public const string FILTER_NODE = 'node';

    private const string ROW_SLOT = 'agent';
    private const int WINDOW_SIZE = 25;

    /**
     * @var array<string, array{idle: bool, ownsRt: list<array{collection: string, width: string}>,
     *     ownsDb: list<array{collection: string, width: string}>}> Declarations cached by agent type
     */
    private static array $declarations = [];

    /** @return int Rows in the first window */
    public function windowSize(): int
    {
        return self::WINDOW_SIZE;
    }

    /**
     * The mirror raises no row source events.
     *
     * @param SourceChange $change Ignored source change
     * @return ?TableRowMutationDTO Always null
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * @param AbstractTableRow $row Agent table row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Browser row envelope
     * @throws TableRowKeyMissingException When a placeholder row has no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::ROW_SLOT => $row->toArray()],
        ];
    }

    /** @return array<string, WireField> Origins of every non-personal agent field */
    public function wireFields(): array
    {
        $ownership = WireField::each([
            HilosDaemonAgentsTableRow::OWNERSHIP_COLLECTION => WireField::notPersonal(),
            HilosDaemonAgentsTableRow::OWNERSHIP_WIDTH => WireField::notPersonal(),
        ]);

        return [
            HilosDaemonAgentsTableRow::rowKey => WireField::notPersonal(),
            HilosDaemonAgentsTableRow::agentId => WireField::notPersonal(),
            HilosDaemonAgentsTableRow::workerIndex => WireField::notPersonal(),
            HilosDaemonAgentsTableRow::workerKind => WireField::notPersonal(),
            HilosDaemonAgentsTableRow::placement => WireField::notPersonal(),
            HilosDaemonAgentsTableRow::idle => WireField::notPersonal(),
            HilosDaemonAgentsTableRow::ownsRt => $ownership,
            HilosDaemonAgentsTableRow::ownsDb => $ownership,
            HilosDaemonAgentsTableRow::logStream => WireField::notPersonal(),
        ];
    }

    /**
     * @param string|int $rowKey Row key to find
     * @param TableQueryDTO $query Window filter and search scope
     * @return ?bool Whether the node's picture contains this row
     * @throws TableSearchNotSupportedException When search is requested
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return $this->containsRowInMemory($this->collectRows($query), $rowKey, $query);
    }

    /**
     * @param TableQueryDTO $query Window query
     * @return TableSnapshotDTO Rows in natural agent-id order
     * @throws TableSearchNotSupportedException When search is requested
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory($this->collectRows($query), $query);
    }

    /** Configures the agent row representation. */
    protected function init(): void
    {
        $this->setRowClass(HilosDaemonAgentsTableRow::class);
    }

    /** @return array<string, string> Search only by agent instance id */
    protected function searchableFields(): array
    {
        return [HilosDaemonAgentsTableRow::agentId => HilosDaemonAgentsTableRow::agentId];
    }

    /**
     * @param TableQueryDTO $query Window filter naming one node
     * @return list<array<string, mixed>> Started agents in natural id order
     */
    private function collectRows(TableQueryDTO $query): array
    {
        $node = $query->filter[self::FILTER_NODE] ?? null;
        if (!is_string($node) || trim($node) === '') {
            return [];
        }

        $roster = ClusterDaemonPictureMirror::picture()?->node(trim($node))?->slot?->picture->processes;
        if ($roster === null) {
            return [];
        }

        $rows = [];
        foreach ($roster->workers as $worker) {
            foreach ($worker->agents as $agent) {
                $declaration = self::declarationOf(AgentId::fromId($agent->id)->type);
                $rows[] = [
                    HilosDaemonAgentsTableRow::rowKey => $agent->id,
                    HilosDaemonAgentsTableRow::agentId => $agent->id,
                    HilosDaemonAgentsTableRow::workerIndex => $worker->index,
                    HilosDaemonAgentsTableRow::workerKind => $worker->kind,
                    HilosDaemonAgentsTableRow::placement => $agent->placement,
                    HilosDaemonAgentsTableRow::idle => $declaration[HilosDaemonAgentsTableRow::idle],
                    HilosDaemonAgentsTableRow::ownsRt => $declaration[HilosDaemonAgentsTableRow::ownsRt],
                    HilosDaemonAgentsTableRow::ownsDb => $declaration[HilosDaemonAgentsTableRow::ownsDb],
                    HilosDaemonAgentsTableRow::logStream => AgentLogStream::streamName($agent->id, false),
                ];
            }
        }

        usort($rows, static fn (array $left, array $right): int => strnatcmp(
            $left[HilosDaemonAgentsTableRow::agentId],
            $right[HilosDaemonAgentsTableRow::agentId],
        ));

        return $rows;
    }

    /**
     * @param string $type Agent type from the instance id
     * @return array{idle: bool, ownsRt: list<array{collection: string, width: string}>,
     *     ownsDb: list<array{collection: string, width: string}>} Declared state of the type
     */
    private static function declarationOf(string $type): array
    {
        if (isset(self::$declarations[$type])) {
            return self::$declarations[$type];
        }

        $entry = Hilos::appClass()::AGENTS[$type] ?? null;
        $class = AgentRegistry::workerClass($entry);
        if ($class === null || !class_exists($class)) {
            return self::$declarations[$type] = [
                HilosDaemonAgentsTableRow::idle => false,
                HilosDaemonAgentsTableRow::ownsRt => [],
                HilosDaemonAgentsTableRow::ownsDb => [],
            ];
        }

        return self::$declarations[$type] = [
            HilosDaemonAgentsTableRow::idle => AgentRegistry::idleTimeout($entry) !== null,
            HilosDaemonAgentsTableRow::ownsRt => self::ownership(
                OwnershipDeclaration::rtCollectionsOf($class),
                OwnershipDeclaration::rtRowCollectionsOf($class),
                OwnershipDeclaration::rtSetCollectionsOf($class),
            ),
            HilosDaemonAgentsTableRow::ownsDb => self::ownership(
                OwnershipDeclaration::dbCollectionsOf($class),
                OwnershipDeclaration::dbRowCollectionsOf($class),
                OwnershipDeclaration::dbSetCollectionsOf($class),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $whole Whole-collection claims
     * @param array<string, mixed> $rows Row claims
     * @param array<string, mixed> $set Set claims
     * @return list<array{collection: string, width: string}> Claims sorted by collection
     */
    private static function ownership(array $whole, array $rows, array $set): array
    {
        $entries = [];
        foreach ([
            HilosDaemonAgentsTableRow::WIDTH_WHOLE => $whole,
            HilosDaemonAgentsTableRow::WIDTH_ROWS => $rows,
            HilosDaemonAgentsTableRow::WIDTH_SET => $set,
        ] as $width => $collections) {
            foreach (array_keys($collections) as $collection) {
                $entries[] = [
                    HilosDaemonAgentsTableRow::OWNERSHIP_COLLECTION => $collection,
                    HilosDaemonAgentsTableRow::OWNERSHIP_WIDTH => $width,
                ];
            }
        }
        usort($entries, static fn (array $left, array $right): int => strcmp(
            $left[HilosDaemonAgentsTableRow::OWNERSHIP_COLLECTION],
            $right[HilosDaemonAgentsTableRow::OWNERSHIP_COLLECTION],
        ));

        return $entries;
    }
}
