<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Core\Exception\InvalidArgumentException;

/** Own part of the environment mismatch page: cluster mode, serving node, and verdict counters; never carries values. */
final readonly class DaemonEnvMismatchSummary
{
    public const string clustered = 'clustered';
    public const string servingNodeId = 'servingNodeId';
    public const string comparison = 'comparison';
    public const string nodes = 'nodes';
    public const string answering = 'answering';
    public const string diverged = 'diverged';
    public const string sourcesDiffer = 'sourcesDiffer';
    public const string perNode = 'perNode';

    /**
     * @param bool $clustered Whether the installation is clustered
     * @param string $servingNodeId Serving node id
     * @param ?ClusterEnvironmentComparison $comparison Comparison from cluster picture
     */
    public function __construct(
        public bool $clustered,
        public string $servingNodeId,
        public ?ClusterEnvironmentComparison $comparison,
    ) {
    }

    /**
     * @param bool $clustered Whether the installation is clustered
     * @param string $servingNodeId Serving node id
     * @param ?ClusterDaemonPicture $picture Current page-agent mirror
     * @return self Summary with verdict counters only when clustered and picture exists
     * @throws InvalidArgumentException When environment comparison generates an invalid cell
     */
    public static function of(bool $clustered, string $servingNodeId, ?ClusterDaemonPicture $picture): self
    {
        $comparison = $clustered ? $picture?->environmentComparison() : null;

        return new self($clustered, $servingNodeId, $comparison);
    }

    /**
     * @return array{
     *     clustered: bool,
     *     servingNodeId: string,
     *     comparison: ?array{
     *         nodes: list<string>,
     *         answering: list<string>,
     *         diverged: int,
     *         sourcesDiffer: int,
     *         perNode: list<string>
     *     }
     * } Wire representation of the summary
     */
    public function toArray(): array
    {
        return [
            self::clustered => $this->clustered,
            self::servingNodeId => $this->servingNodeId,
            self::comparison => $this->comparison === null ? null : [
                self::nodes => $this->comparison->nodes,
                self::answering => $this->comparison->answering,
                self::diverged => count($this->comparison->diverged),
                self::sourcesDiffer => count($this->comparison->sourcesDiffer),
                self::perNode => $this->comparison->perNode,
            ],
        ];
    }
}
