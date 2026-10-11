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
use Hilos\Core\Table\TableSearchMatch;
use Hilos\Core\Table\TableSearchTerm;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Pages\Daemon\AbstractHilosDaemonPage;

/**
 * Every declared project type has a row; instances come only from the page worker's mirror.
 * A silent node is unknown, never its last roster. Unplaced ids are the leader's verdict.
 * Instance search narrows cells here; {@see AbstractHilosDaemonPage::onPictureChanged()}
 * resends a complete window after a changed picture portion.
 */
final class HilosDaemonPlacementTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosDaemonPlacement';

    private const string ROW_SLOT = 'type';

    /** @return int One window holds every declared type */
    public function windowSize(): int
    {
        return max(1, count(Hilos::appClass()::AGENTS));
    }

    /** @return ?TableRowMutationDTO The mirror raises no row source events */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * @param AbstractTableRow $row Placement row
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

    /** @return array<string, WireField> Origins of every non-personal placement field */
    public function wireFields(): array
    {
        return [
            HilosDaemonPlacementTableRow::rowKey => WireField::notPersonal(),
            HilosDaemonPlacementTableRow::agentType => WireField::notPersonal(),
            HilosDaemonPlacementTableRow::placement => WireField::notPersonal(),
            HilosDaemonPlacementTableRow::idle => WireField::notPersonal(),
            HilosDaemonPlacementTableRow::nodes => WireField::each([
                HilosDaemonPlacementTableRow::NODE_ID => WireField::notPersonal(),
                HilosDaemonPlacementTableRow::NODE_AGENT_IDS => WireField::notPersonal(),
            ]),
            HilosDaemonPlacementTableRow::unplacedAgentIds => WireField::notPersonal(),
        ];
    }

    /**
     * @return ?bool Whether the searched placement set contains the type
     * @throws TableSearchNotSupportedException When search is unsupported
     * @throws TableSearchFieldUnknownException When a declared field is absent
     * @throws EnvException When cluster mode cannot be read
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return $this->containsRowInMemory($this->collectRows($query), $rowKey, $query->withoutSearch());
    }

    /**
     * @param ?ClusterDaemonPicture $picture Current worker-local picture
     * @return list<string> Unplaced ids from the leader, or the sole node in a single installation
     * @throws EnvException When cluster mode cannot be read
     */
    public static function unplacedOf(?ClusterDaemonPicture $picture): array
    {
        if (Hilos::$cluster?->isEnabled() === true) {
            return $picture?->leader()?->slot?->picture->processes?->unplacedAgentIds ?? [];
        }

        return ($picture?->nodes()[0] ?? null)?->slot?->picture->processes?->unplacedAgentIds ?? [];
    }

    /**
     * @return TableSnapshotDTO Rows in declared placement order
     * @throws TableSearchNotSupportedException When search is unsupported
     * @throws TableSearchFieldUnknownException When a declared field is absent
     * @throws EnvException When cluster mode cannot be read
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory($this->collectRows($query), $query->withoutSearch());
    }

    /** Configures the placement row representation. */
    protected function init(): void
    {
        $this->setRowClass(HilosDaemonPlacementTableRow::class);
    }

    /** @return array<string, string> Type and instance-bearing fields searched by this table */
    protected function searchableFields(): array
    {
        return [
            HilosDaemonPlacementTableRow::agentType => HilosDaemonPlacementTableRow::agentType,
            HilosDaemonPlacementTableRow::nodes => HilosDaemonPlacementTableRow::nodes,
            HilosDaemonPlacementTableRow::unplacedAgentIds => HilosDaemonPlacementTableRow::unplacedAgentIds,
        ];
    }

    /**
     * @param TableQueryDTO $query Window search scope
     * @return list<array<string, mixed>> One row per declared type
     */
    private function collectRows(TableQueryDTO $query): array
    {
        $picture = ClusterDaemonPictureMirror::picture();
        $nodes = $picture?->nodes() ?? [];
        $agentsByNode = [];
        foreach ($nodes as $node) {
            if (!$node->online || $node->slot?->picture->processes === null) {
                continue;
            }
            foreach ($node->slot->picture->processes->workers as $worker) {
                foreach ($worker->agents as $agent) {
                    $agentsByNode[$node->nodeId][AgentId::fromId($agent->id)->type][] = $agent->id;
                }
            }
        }
        foreach ($agentsByNode as &$types) {
            foreach ($types as &$ids) {
                usort($ids, strnatcmp(...));
            }
            unset($ids);
        }
        unset($types);

        $unplacedByType = [];
        foreach (self::unplacedOf($picture) as $id) {
            $unplacedByType[AgentId::fromId($id)->type][] = $id;
        }
        foreach ($unplacedByType as &$ids) {
            sort($ids, SORT_STRING);
        }
        unset($ids);

        $term = $query->searchableFields === [] ? null : TableSearchTerm::normalize($query->search);
        $matches = $term === null ? null : TableSearchTerm::matcher($term, TableSearchMatch::Substring);
        $rows = [];
        foreach (Hilos::appClass()::AGENTS as $type => $entry) {
            $typeMatches = $matches !== null && $matches($type);
            $nodeRows = [];
            $found = false;
            foreach ($nodes as $node) {
                $ids = !$node->online || $node->slot?->picture->processes === null
                    ? null
                    : ($agentsByNode[$node->nodeId][$type] ?? []);
                if ($ids !== null && $matches !== null && !$typeMatches) {
                    $ids = array_values(array_filter($ids, $matches));
                }
                $found = $found || $ids !== null && $ids !== [];
                $nodeRows[] = [HilosDaemonPlacementTableRow::NODE_ID => $node->nodeId,
                    HilosDaemonPlacementTableRow::NODE_AGENT_IDS => $ids];
            }
            $unplaced = $unplacedByType[$type] ?? [];
            if ($matches !== null && !$typeMatches) {
                $unplaced = array_values(array_filter($unplaced, $matches));
                if (!$found && $unplaced === []) {
                    continue;
                }
            }
            $rows[] = [
                HilosDaemonPlacementTableRow::rowKey => $type,
                HilosDaemonPlacementTableRow::agentType => $type,
                HilosDaemonPlacementTableRow::placement => DaemonAgentPicture::placementOf($entry),
                HilosDaemonPlacementTableRow::idle => AgentRegistry::idleTimeout($entry) !== null,
                HilosDaemonPlacementTableRow::nodes => $nodeRows,
                HilosDaemonPlacementTableRow::unplacedAgentIds => $unplaced,
            ];
        }
        $rank = [DaemonAgentPicture::PLACEMENT_LEADER => 0,
            DaemonAgentPicture::PLACEMENT_POLICY => 1, DaemonAgentPicture::PLACEMENT_NODE => 2];
        usort($rows, static function (array $left, array $right) use ($rank): int {
            return (int) ($right[HilosDaemonPlacementTableRow::unplacedAgentIds] !== [])
                <=> (int) ($left[HilosDaemonPlacementTableRow::unplacedAgentIds] !== [])
                ?: $rank[$left[HilosDaemonPlacementTableRow::placement]] <=> $rank[$right[HilosDaemonPlacementTableRow::placement]]
                ?: strcmp($left[HilosDaemonPlacementTableRow::agentType], $right[HilosDaemonPlacementTableRow::agentType]);
        });

        return $rows;
    }
}
