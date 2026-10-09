<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\DTO\DaemonNodePictureSignalData;
use Hilos\DaemonSection\DTO\DaemonPictureWatchSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\DaemonSection\NodeEnvironmentFingerprint;
use Hilos\Hilos;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotReadableException;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Utils\Helpers\RandomHelper;
use Random\RandomException;

/** Cluster owner of the last whole Daemon frame from each node. */
final class DaemonCollectorAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_DAEMON_COLLECTOR;

    public const array AGENT_SIGNALS = [
        HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT => DaemonNodePictureSignalData::class,
        HilosSignalConstants::DAEMON_PICTURE_WATCH => DaemonPictureWatchSignalData::class,
    ];

    /** @var list<string> Cluster membership used only at delivery time */
    public const array READS_RT = [HilosClusterNode::RT_COLLECTION];

    private const float FANOUT_WINDOW_SECONDS = 0.5;
    private const float WATCH_LEASE_SECONDS = 90.0;
    private const int LABEL_SALT_BYTES = 32;
    private const string WATCH_RENEWED_AT = 'renewedAt';
    private const string WATCH_SENT_REVISION = 'sentRevision';

    private ClusterDaemonPicture $picture;
    private string $labelSalt;

    /** @var array<string, array{renewedAt: float, sentRevision: int}> */
    private array $watchers = [];

    /** @var array<string, int> Node id to last report or online-change revision */
    private array $nodeRevisions = [];

    /** @var array<string, bool> Last observed roster verdicts */
    private array $lastOnline = [];

    private int $revision = 0;
    private float $lastFanoutAt = 0.0;
    private float $lastProjectionAt = 0.0;

    /**
     * Starts with no reports and a fresh salt for this collector lifetime.
     *
     * @throws RandomException When the secure random source refuses a salt
     */
    public function onStart(): void
    {
        $this->labelSalt = RandomHelper::secureBytes(self::LABEL_SALT_BYTES);
        $this->picture = ClusterDaemonPicture::empty();
    }

    /**
     * Replaces one slot whole, independent of its timestamp and of any old contents.
     *
     * @param NodeDaemonPicture $picture Node's latest complete report
     */
    public function applyNodePicture(NodeDaemonPicture $picture): void
    {
        $environment = $picture->environment;
        if ($environment !== null) {
            $fingerprints = array_map(
                fn (NodeEnvironmentFingerprint $fingerprint): NodeEnvironmentFingerprint => $fingerprint->withDigest(
                    $fingerprint->digest === null ? null : substr(
                        hash_hmac('sha256', $fingerprint->digest, $this->labelSalt),
                        0,
                        NodeEnvironmentFingerprint::DIGEST_HEX_LENGTH,
                    ),
                ),
                $environment->fingerprints,
            );
            $picture = $picture->withEnvironment($environment->withFingerprints($fingerprints));
        }
        $slot = new ClusterDaemonNodeSlot($picture->nodeId, $picture, time());
        $this->picture = $this->picture->withNode(new ClusterDaemonNodeView($picture->nodeId, false, $slot));
        $this->nodeRevisions[$picture->nodeId] = ++$this->revision;
    }

    /**
     * @param AgentSignalData $data Parsed frame
     * @param string $sender Full sender address
     * @param string $name Signal name
     * @throws AgentUnknownSignalException When the signal is not this collector's
     * @throws InvalidArgumentException When the answer cannot be routed
     * @throws RtCollectionNotFoundException When the cluster register is not mounted
     * @throws RtCollectionNotReadableException When this agent lacks reader interest
     * @throws RtActionsStateCollectionNullException When the register has no backing state
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT:
                if ($data->data instanceof DaemonNodePictureSignalData) {
                    $this->applyNodePicture($data->data->picture);
                }

                return;

            case HilosSignalConstants::DAEMON_PICTURE_WATCH:
                if ($data->data instanceof DaemonPictureWatchSignalData) {
                    $this->applyWatch($sender, $data->data->viewers, microtime(true));
                }

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Merges roster members and retained reports; a missing member with a report is offline.
     *
     * @return list<ClusterDaemonNodeView> Whole node views sorted by id
     * @throws RtCollectionNotFoundException When the cluster register is not mounted
     * @throws RtCollectionNotReadableException When this agent lacks reader interest
     * @throws RtActionsStateCollectionNullException When the register has no backing state
     */
    public function nodeViews(): array
    {
        $views = [];
        foreach ($this->picture->nodes() as $node) {
            $views[$node->nodeId] = new ClusterDaemonNodeView($node->nodeId, false, $node->slot);
        }
        if (Hilos::$rt !== null) {
            foreach (Hilos::$rt->hilosClusterNodes as $row) {
                $slot = $views[$row->nodeId]->slot ?? null;
                $views[$row->nodeId] = new ClusterDaemonNodeView($row->nodeId, $row->online, $slot);
            }
        }
        ksort($views);

        return array_values($views);
    }

    /** @return ClusterDaemonPicture Reports held by the collector before roster projection */
    public function clusterPicture(): ClusterDaemonPicture
    {
        return $this->picture;
    }

    /**
     * Sends changed whole nodes after the coalescing window, while leases remain live.
     *
     * @param float $now Wall clock of this tick
     * @throws InvalidArgumentException When a portion cannot be routed
     * @throws RtCollectionNotFoundException When the cluster register is not mounted
     * @throws RtCollectionNotReadableException When this agent lacks reader interest
     * @throws RtActionsStateCollectionNullException When the register has no backing state
     */
    public function fanOutIfDue(float $now): void
    {
        foreach ($this->watchers as $sender => $watcher) {
            if ($now - $watcher[self::WATCH_RENEWED_AT] >= self::WATCH_LEASE_SECONDS) {
                unset($this->watchers[$sender]);
            }
        }
        if ($this->watchers === []) {
            return;
        }
        if ($now - $this->lastFanoutAt < self::FANOUT_WINDOW_SECONDS
            || $now - $this->lastProjectionAt < self::FANOUT_WINDOW_SECONDS) {
            return;
        }

        $views = $this->nodeViews();
        $this->lastProjectionAt = $now;
        $this->noteOnlineChanges($views);
        foreach ($this->watchers as $sender => $watcher) {
            $changed = array_values(array_filter(
                $views,
                fn (ClusterDaemonNodeView $view): bool => ($this->nodeRevisions[$view->nodeId] ?? 0) > $watcher[self::WATCH_SENT_REVISION],
            ));
            if ($changed !== []) {
                $this->sendPortion($sender, $changed, false, $now);
            }
        }
    }

    /**
     * @throws InvalidArgumentException When a portion cannot be routed
     * @throws RtCollectionNotFoundException When the cluster register is not mounted
     * @throws RtCollectionNotReadableException When this agent lacks reader interest
     * @throws RtActionsStateCollectionNullException When the register has no backing state
     */
    public function onTick(): void
    {
        $this->fanOutIfDue(microtime(true));
    }

    /**
     * A positive claim renews its 90-second lease and receives a whole snapshot every time.
     *
     * @throws InvalidArgumentException When a snapshot cannot be routed
     * @throws RtCollectionNotFoundException When the cluster register is not mounted
     * @throws RtCollectionNotReadableException When this agent lacks reader interest
     * @throws RtActionsStateCollectionNullException When the register has no backing state
     */
    private function applyWatch(string $sender, int $viewers, float $now): void
    {
        if ($viewers === 0) {
            unset($this->watchers[$sender]);

            return;
        }

        $views = $this->nodeViews();
        $this->noteOnlineChanges($views);
        $this->watchers[$sender] = [
            self::WATCH_RENEWED_AT => $now,
            self::WATCH_SENT_REVISION => $this->revision,
        ];
        $this->sendPortion($sender, $views, true, $now);
    }

    /**
     * @param list<ClusterDaemonNodeView> $views Current roster projection
     */
    private function noteOnlineChanges(array $views): void
    {
        $present = [];
        foreach ($views as $view) {
            $present[$view->nodeId] = true;
            if (!array_key_exists($view->nodeId, $this->lastOnline) || $this->lastOnline[$view->nodeId] !== $view->online) {
                $this->nodeRevisions[$view->nodeId] = ++$this->revision;
                $this->lastOnline[$view->nodeId] = $view->online;
            }
        }
        $this->lastOnline = array_intersect_key($this->lastOnline, $present);
    }

    /**
     * @param list<ClusterDaemonNodeView> $nodes Whole views to send
     * @throws InvalidArgumentException When the frame cannot be routed
     */
    private function sendPortion(string $sender, array $nodes, bool $snapshot, float $now): void
    {
        $this->sendToAgent(
            HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION,
            new DaemonClusterPicturePortionSignalData($snapshot, $nodes),
        );
        $this->watchers[$sender][self::WATCH_SENT_REVISION] = $this->revision;
        $this->lastFanoutAt = $now;
    }
}
