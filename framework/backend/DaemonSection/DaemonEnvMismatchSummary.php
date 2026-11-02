<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Core\Exception\InvalidArgumentException;

/** Own part of the environment mismatch page: cluster mode, serving node, and verdict counters; never carries values. */
final readonly class DaemonEnvMismatchSummary
{
    public const string clustered = 'clustered';
    public const string servingNodeId = 'servingNodeId';
    public const string servingNodeName = 'servingNodeName';
    public const string comparison = 'comparison';
    public const string nodes = 'nodes';
    public const string nodeId = 'nodeId';
    public const string nodeName = 'nodeName';
    public const string answering = 'answering';
    public const string diverged = 'diverged';
    public const string sourcesDiffer = 'sourcesDiffer';
    public const string perNode = 'perNode';

    /**
     * @param bool $clustered Whether the installation is clustered
     * @param string $servingNodeId Serving node id
     * @param ?ClusterEnvironmentComparison $comparison Comparison from cluster picture
     * @param ?ClusterDaemonPicture $picture Same picture used for the comparison and node names
     * @param ?string $servingNodeName Name resolved from the current node registry, null when absent
     */
    public function __construct(
        public bool $clustered,
        public string $servingNodeId,
        public ?ClusterEnvironmentComparison $comparison,
        private ?ClusterDaemonPicture $picture = null,
        public ?string $servingNodeName = null,
    ) {
    }

    /**
     * @param bool $clustered Whether the installation is clustered
     * @param string $servingNodeId Serving node id
     * @param ?ClusterDaemonPicture $picture Current page-agent mirror
     * @param ?string $servingNodeName Name resolved from the current node registry, null when absent
     * @return self Summary with verdict counters only when clustered and picture exists
     * @throws InvalidArgumentException When environment comparison generates an invalid cell
     */
    public static function of(
        bool $clustered,
        string $servingNodeId,
        ?ClusterDaemonPicture $picture,
        ?string $servingNodeName = null,
    ): self {
        $comparison = $clustered ? $picture?->environmentComparison() : null;

        return new self($clustered, $servingNodeId, $comparison, $picture, $servingNodeName);
    }

    /**
     * @return array{
     *     clustered: bool,
     *     servingNodeId: string,
     *     servingNodeName: ?string,
     *     comparison: ?array{
     *         nodes: list<array{nodeId: string, nodeName: ?string}>,
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
            self::servingNodeName => $this->servingNodeName,
            self::comparison => $this->comparison === null ? null : [
                self::nodes => array_map(fn (string $nodeId): array => [
                    self::nodeId => $nodeId,
                    self::nodeName => $this->picture?->node($nodeId)?->name,
                ], $this->comparison->nodes),
                self::answering => $this->comparison->answering,
                self::diverged => count($this->comparison->diverged),
                self::sourcesDiffer => count($this->comparison->sourcesDiffer),
                self::perNode => $this->comparison->perNode,
            ],
        ];
    }
}
