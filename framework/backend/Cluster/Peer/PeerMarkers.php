<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer;

use Hilos\Cluster\Peer\DTO\PeerHandshakeDTO;
use Hilos\Core\Daemon\Module\PeerModule;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\DatabaseMarker;
use Hilos\Fs\ClusterDirectoryMarker;

/**
 * The markers a node names to its peers on the handshake, one per kind, and the rule both ends
 * of a link judge them by (HIL-1206).
 *
 * A marker names something every node of a cluster must share, and the kinds are of three sorts.
 * One is the database ({@see DatabaseMarker}): nodes that name different database markers read
 * different databases, and a mesh of them would read wrong rows quietly. The other is one kind
 * `directory:<name>` per cluster directory of `$fs` ({@see ClusterDirectoryMarker}, HIL-1242):
 * nodes that name different markers for one directory do not read one directory, and a file one of
 * them writes is not there for the others. The third is the admin view mode variable (HIL-1274):
 * nodes with different values would answer admin viewers differently. The variable is compared,
 * not the startup verdict: in production the database latch itself closes each node on its next
 * start, and comparing the verdict would split the cluster during a rolling restart.
 *
 * Every hello and welcome carries the sender's markers ({@see PeerHandshakeDTO}), and both ends of
 * a link check them right after the certificate name: a kind named by either side must be named by
 * the other with the same value. A breach refuses the link on both ends before the peer is
 * remembered, so a node reading another database or another directory is admitted by nobody. Not
 * one judge but every link: a leader judging alone would find the stranger already linked to every
 * other node, and there is no leader before the first election.
 *
 * A new kind is a new key here, not a second mechanism. The markers are read once at the start of
 * the daemon ({@see PeerModule}) and live in memory; the handshake touches neither the database nor
 * the disk.
 */
final readonly class PeerMarkers
{
    /** @var string Kind of the marker naming the logical database a node reads */
    public const string DATABASE = 'database';

    /** @var string Start of the kind of the marker naming a cluster directory; the directory's name follows */
    public const string DIRECTORY_KIND_PREFIX = 'directory:';

    /** @var string Kind of the marker naming the admin view mode variable */
    public const string ADMIN_VIEW_MODE = 'admin-view-mode';

    /** @var string Marker value when the admin view mode variable is on */
    public const string ADMIN_VIEW_MODE_ON = 'on';

    /** @var string Marker value when the admin view mode variable is off */
    public const string ADMIN_VIEW_MODE_OFF = 'off';

    /**
     * @param array<string, string> $values Marker this node carries, per kind
     * @param array<string, string> $places Where this node read each marker from, per kind; printed in a refusal
     * @throws InvalidArgumentException When a kind has a value but no place, or a place but no value
     */
    public function __construct(
        public array $values,
        public array $places,
    ) {
        $unmatched = array_merge(array_diff_key($values, $places), array_diff_key($places, $values));
        if ($unmatched !== []) {
            throw new InvalidArgumentException(
                'Peer markers name a value and a place for every kind; unmatched: ' . implode(', ', array_keys($unmatched)),
            );
        }
    }

    /**
     * Names the kind of the marker of one cluster directory.
     *
     * By the directory's name, not its path: a refusal names the directory the way the project
     * registered it, and two names on one path are two kinds that read one file.
     *
     * @param string $name Logical name of the directory as registered in the `$fs` context
     * @return string The kind, `directory:<name>`
     */
    public static function directoryKind(string $name): string
    {
        return self::DIRECTORY_KIND_PREFIX . $name;
    }

    /**
     * Names the value of this node's admin view mode variable.
     *
     * @param bool $on Whether the variable is on
     * @return string The marker value
     */
    public static function adminViewMode(bool $on): string
    {
        return $on ? self::ADMIN_VIEW_MODE_ON : self::ADMIN_VIEW_MODE_OFF;
    }

    /**
     * Judges the markers a peer named on its handshake against this node's own.
     *
     * The first breach found is the one named: a node that breaks the rule once is refused
     * whatever else it says.
     *
     * @param string $nodeId Node id the peer's handshake introduces
     * @param array<string, string> $remote Markers the peer named, per kind
     * @return ?string Why the link is refused, or null when both ends name the same markers
     */
    public function refusalFor(string $nodeId, array $remote): ?string
    {
        foreach ($this->values as $kind => $local) {
            if (!array_key_exists($kind, $remote)) {
                return "Peer handshake from node '{$nodeId}' names no {$kind} marker,"
                    . " but this node reads '{$local}' from {$this->places[$kind]}";
            }

            if ($remote[$kind] !== $local) {
                return "Peer handshake from node '{$nodeId}' names {$kind} marker '{$remote[$kind]}',"
                    . " but this node reads '{$local}' from {$this->places[$kind]}: the two nodes do not read one {$kind}";
            }
        }

        foreach ($remote as $kind => $value) {
            if (!array_key_exists($kind, $this->values)) {
                return "Peer handshake from node '{$nodeId}' names {$kind} marker '{$value}', which this node does not carry";
            }
        }

        return null;
    }
}
