<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon\DTO;

use Hilos\Constants\TimeConstants;
use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Daemon\DaemonMeasurements;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\DaemonWorkerPicture;

/** Value-free boundary projection of exactly one addressed node's complete picture. */
final readonly class HilosDaemonNodeOverviewData
{
    public const string nodeId = 'nodeId';
    public const string online = 'online';
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
    public const string environmentComparison = 'environmentComparison';
    public const string answeringNodes = 'answeringNodes';
    public const string divergedKeys = 'divergedKeys';
    public const string sourcesDifferKeys = 'sourcesDifferKeys';

    /**
     * Arrays here are final wire projections, not internal picture state.
     *
     * @param string $nodeId Addressed node
     * @param bool $online Membership verdict
     * @param ?list<array{index: int, kind: string, agentCount: int}> $workers Actual reported workers, null before a roster
     * @param ?int $workerRestarts24h Reported worker restarts
     * @param ?int $uptimeMinutes Uptime at the report's own sample
     * @param ?DaemonMeasurements $measurements Full reported measurements
     * @param ?array{sessions: int, connections: int} $standing Reported browser counts
     * @param ?array{missingRequired: int, fromExample: int, drifted: int, orphans: int} $environment Value-free environment counts
     * @param int $answeringNodes Online nodes with an environment report
     * @param ?int $divergedKeys Comparable keys whose values differ
     * @param ?int $sourcesDifferKeys Comparable equal values from different sources
     */
    private function __construct(
        private string $nodeId,
        private bool $online,
        private ?array $workers,
        private ?int $workerRestarts24h,
        private ?int $uptimeMinutes,
        private ?DaemonMeasurements $measurements,
        private ?array $standing,
        private ?array $environment,
        private int $answeringNodes,
        private ?int $divergedKeys,
        private ?int $sourcesDifferKeys,
    ) {
    }

    /**
     * @param ?ClusterDaemonPicture $picture Current worker-local mirror
     * @param string $nodeId Requested node, never substituted with another node
     * @param bool $clustered Whether this installation is clustered
     * @param bool $hasFullSnapshot Whether the mirror received a complete snapshot
     * @return ?self Null until a complete picture contains the requested node
     * @throws InvalidArgumentException When a generated environment cell contradicts its state
     */
    public static function fromPicture(
        ?ClusterDaemonPicture $picture,
        string $nodeId,
        bool $clustered,
        bool $hasFullSnapshot,
    ): ?self {
        if (!$hasFullSnapshot || $picture === null || $picture->node($nodeId) === null) {
            return null;
        }

        $node = $picture->node($nodeId);
        $report = $node->slot?->picture;
        $measurements = $report?->measurements;
        $standing = $report?->standing;
        $environment = $report?->environment;
        $comparison = $picture->environmentComparison();
        $answeringNodes = count($comparison->answering);
        $comparable = $clustered && $answeringNodes >= 2;

        return new self(
            $nodeId,
            $node->online,
            $report?->processes === null ? null : array_map(
                static fn (DaemonWorkerPicture $worker): array => [
                    self::index => $worker->index,
                    self::kind => $worker->kind,
                    self::agentCount => count($worker->agents),
                ],
                $report->processes->workers,
            ),
            $report?->processes?->workerRestarts24h,
            $measurements === null ? null
                : max(0, intdiv($report->sampledAt - $measurements->startedAt, TimeConstants::SECONDS_PER_MINUTE)),
            $measurements,
            $standing === null ? null : [self::sessions => $standing->sessions, self::connections => $standing->connections],
            $environment === null ? null : [
                self::missingRequired => $environment->missingRequired,
                self::fromExample => $environment->fromExample,
                self::drifted => $environment->drifted,
                self::orphans => $environment->orphans,
            ],
            $answeringNodes,
            $comparable ? count($comparison->diverged) : null,
            $comparable ? count($comparison->sourcesDiffer) : null,
        );
    }

    /** @return array<string, mixed> Full diagnostics of the addressed node in the existing page_response */
    public function toArray(): array
    {
        return [
            self::nodeId => $this->nodeId,
            self::online => $this->online,
            self::workers => $this->workers,
            self::workerRestarts24h => $this->workerRestarts24h,
            self::uptimeMinutes => $this->uptimeMinutes,
            self::measurements => $this->measurements === null ? null : DaemonStatusDTO::measurementsToArray($this->measurements),
            self::standing => $this->standing,
            self::environment => $this->environment,
            self::environmentComparison => [
                self::answeringNodes => $this->answeringNodes,
                self::divergedKeys => $this->divergedKeys,
                self::sourcesDifferKeys => $this->sourcesDifferKeys,
            ],
        ];
    }
}
