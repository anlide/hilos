<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon\DTO;

use Hilos\Cluster\NodeRole;
use Hilos\Constants\TimeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonNodeHeading;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;

/** Value-free boundary projection of the Daemon root's cluster picture. */
final readonly class HilosDaemonOverviewData
{
    public const string clustered = 'clustered';
    public const string nodes = 'nodes';
    public const string nodeId = 'nodeId';
    public const string online = 'online';
    public const string role = 'role';
    public const string state = 'state';
    public const string silentSince = 'silentSince';
    public const string workers = 'workers';
    public const string index = 'index';
    public const string kind = 'kind';
    public const string agentCount = 'agentCount';
    public const string workerRestarts24h = 'workerRestarts24h';
    public const string uptimeMinutes = 'uptimeMinutes';
    public const string measurements = 'measurements';
    public const string standing = 'standing';
    public const string sessions = 'sessions';
    public const string connections = 'connections';
    public const string environment = 'environment';
    public const string missingRequired = 'missingRequired';
    public const string fromExample = 'fromExample';
    public const string drifted = 'drifted';
    public const string orphans = 'orphans';
    public const string consensus = 'consensus';
    public const string term = 'term';
    public const string leaderId = 'leaderId';
    public const string onlineMasters = 'onlineMasters';
    public const string masters = 'masters';
    public const string quorumSize = 'quorumSize';
    public const string environmentComparison = 'environmentComparison';
    public const string answeringNodes = 'answeringNodes';
    public const string divergedKeys = 'divergedKeys';
    public const string sourcesDifferKeys = 'sourcesDifferKeys';
    public const string placement = 'placement';
    public const string instances = 'instances';
    public const string types = 'types';
    public const string clusterTypes = 'clusterTypes';
    public const string unplacedTypes = 'unplacedTypes';

    /**
     * Arrays here are the final wire projection, not internal picture state.
     *
     * @param bool $clustered Whether this installation is clustered
     * @param list<array<string, mixed>> $nodes Projected nodes in node-id order
     * @param ?array{nodeId: string, term: int, leaderId: ?string, onlineMasters: int, masters: int, quorumSize: int} $consensus Report
     * @param int $answeringNodes Nodes with an online environment report
     * @param ?int $divergedKeys Comparable keys whose values differ
     * @param ?int $sourcesDifferKeys Comparable keys whose equal values come from different sources
     * @param array{instances: ?int, types: int, clusterTypes: int, unplacedTypes: ?list<string>} $placement Full placement projection
     */
    private function __construct(
        private bool $clustered,
        private array $nodes,
        private ?array $consensus,
        private int $answeringNodes,
        private ?int $divergedKeys,
        private ?int $sourcesDifferKeys,
        private array $placement,
    ) {
    }

    /**
     * @param ?ClusterDaemonPicture $picture Current worker-local mirror
     * @param bool $clustered Whether this installation is clustered
     * @param bool $hasFullSnapshot Whether the mirror received a complete snapshot
     * @return ?self Null until the first full snapshot, including after a mirror reset
     * @throws InvalidArgumentException When a generated environment cell contradicts its state
     * @throws EnvException When placement source cluster mode cannot be read
     */
    public static function fromPicture(?ClusterDaemonPicture $picture, bool $clustered, bool $hasFullSnapshot): ?self
    {
        if (!$hasFullSnapshot || $picture === null) {
            return null;
        }

        $nodes = [];
        foreach ($picture->nodes() as $node) {
            $nodes[] = self::nodeToArray($picture, $node, $clustered);
        }
        $comparison = $picture->environmentComparison();
        $answeringNodes = count($comparison->answering);
        $comparable = $clustered && $answeringNodes >= 2;

        return new self(
            $clustered,
            $nodes,
            $clustered ? self::consensusToArray($picture) : null,
            $answeringNodes,
            $comparable ? count($comparison->diverged) : null,
            $comparable ? count($comparison->sourcesDiffer) : null,
            self::placementToArray($picture, $clustered),
        );
    }

    /** @return array<string, mixed> Own daemon data in the existing page_response */
    public function toArray(): array
    {
        return [
            self::clustered => $this->clustered,
            self::nodes => $this->nodes,
            self::consensus => $this->consensus,
            self::environmentComparison => [
                self::answeringNodes => $this->answeringNodes,
                self::divergedKeys => $this->divergedKeys,
                self::sourcesDifferKeys => $this->sourcesDifferKeys,
            ],
            self::placement => $this->placement,
        ];
    }

    /**
     * @param ClusterDaemonPicture $picture Same complete picture as the node projection
     * @param bool $clustered Installation mode used to select the verdict source
     * @return array{instances: ?int, types: int, clusterTypes: int, unplacedTypes: ?list<string>} Search-independent wire totals
     * @throws EnvException When placement source cluster mode cannot be read
     */
    private static function placementToArray(ClusterDaemonPicture $picture, bool $clustered): array
    {
        $instances = $picture->nodes() === [] ? null : 0;
        foreach ($picture->nodes() as $node) {
            if (!$node->online || $node->slot?->picture->processes === null) {
                $instances = null;
                break;
            }
            foreach ($node->slot->picture->processes->workers as $worker) {
                $instances += count($worker->agents);
            }
        }

        $registry = Hilos::appClass()::AGENTS;
        $clusterTypes = 0;
        foreach ($registry as $entry) {
            if (in_array(DaemonAgentPicture::placementOf($entry), [
                DaemonAgentPicture::PLACEMENT_LEADER, DaemonAgentPicture::PLACEMENT_POLICY,
            ], true)) {
                $clusterTypes++;
            }
        }

        $source = $clustered ? $picture->leader() : ($picture->nodes()[0] ?? null);
        $unplacedTypes = null;
        if ($source?->online === true && $source->slot?->picture->processes !== null) {
            $types = [];
            foreach (HilosDaemonPlacementTable::unplacedOf($picture) as $id) {
                $type = AgentId::fromId($id)->type;
                if (array_key_exists($type, $registry)) {
                    $types[$type] = true;
                }
            }
            $unplacedTypes = array_keys($types);
            usort($unplacedTypes, strcmp(...));
        }

        return [
            self::instances => $instances,
            self::types => count($registry),
            self::clusterTypes => $clusterTypes,
            self::unplacedTypes => $unplacedTypes,
        ];
    }

    /**
     * @param ClusterDaemonPicture $picture Source of node state
     * @param ClusterDaemonNodeView $node Known node, possibly without a report
     * @param bool $clustered Installation mode
     * @return array<string, mixed> Final node wire projection with unknown sections kept null
     */
    private static function nodeToArray(ClusterDaemonPicture $picture, ClusterDaemonNodeView $node, bool $clustered): array
    {
        $heading = DaemonNodeHeading::of($picture, $node->nodeId, $clustered);
        $report = $node->slot?->picture;
        $measurements = $report?->measurements;
        $standing = $report?->standing;
        $environment = $report?->environment;

        return [
            self::nodeId => $node->nodeId,
            self::online => $node->online,
            self::role => $report?->role->value,
            self::state => $heading->state?->value,
            self::silentSince => $heading->silentSince,
            self::workers => $report?->processes === null ? null : array_map(
                static fn (DaemonWorkerPicture $worker): array => [
                    self::index => $worker->index,
                    self::kind => match ($worker->kind) {
                        WorkerConstants::TYPE_REGULAR => WorkerConstants::TYPE_REGULAR,
                        WorkerConstants::TYPE_MONOPOLISTIC => WorkerConstants::TYPE_MONOPOLISTIC,
                    },
                    self::agentCount => count($worker->agents),
                ],
                $report->processes->workers,
            ),
            self::workerRestarts24h => $report?->processes?->workerRestarts24h,
            self::uptimeMinutes => $measurements === null
                ? null : max(0, intdiv($report->sampledAt - $measurements->startedAt, TimeConstants::SECONDS_PER_MINUTE)),
            self::measurements => $measurements === null ? null : DaemonStatusDTO::measurementsToArray($measurements),
            self::standing => $standing === null ? null : [
                self::sessions => $standing->sessions,
                self::connections => $standing->connections,
            ],
            self::environment => $environment === null ? null : [
                self::missingRequired => $environment->missingRequired,
                self::fromExample => $environment->fromExample,
                self::drifted => $environment->drifted,
                self::orphans => $environment->orphans,
            ],
        ];
    }

    /**
     * @param ClusterDaemonPicture $picture Source of the leader or highest-term live master report
     * @return ?array{nodeId: string, term: int, leaderId: ?string, onlineMasters: int, masters: int, quorumSize: int} Report
     */
    private static function consensusToArray(ClusterDaemonPicture $picture): ?array
    {
        $source = $picture->leader();
        if ($source === null) {
            foreach ($picture->nodes() as $node) {
                $consensus = $node->slot?->picture->standing?->consensus;
                if (!$node->online || $node->slot?->picture->role !== NodeRole::Master || $consensus === null) {
                    continue;
                }
                if ($source === null || $consensus->term > $source->slot->picture->standing->consensus->term) {
                    $source = $node;
                }
            }
        }
        $consensus = $source?->slot?->picture->standing?->consensus;
        if ($consensus === null) {
            return null;
        }

        return [
            self::nodeId => $source->nodeId,
            self::term => $consensus->term,
            self::leaderId => $consensus->leaderId,
            self::onlineMasters => $consensus->onlineMasters,
            self::masters => $consensus->masters,
            self::quorumSize => $consensus->quorumSize,
        ];
    }
}
