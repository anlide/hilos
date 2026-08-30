<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Hilos;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\DTO\LogsIndexWatchSignalData;
use Hilos\Log\LogAggregatorAgent;
use Hilos\Log\LogStoreAgent;
use Hilos\Pages\Logs\AbstractHilosLogsPage;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;

/**
 * Abstract Hilos agent for the logs section, holder of the cluster picture the pages draw from.
 *
 * Projects must extend this class with a concrete agent and register it in worker/daemon factories
 * and signal routing for {@see HilosPageConstants::HILOS_LOGS}, or omit the logs pages.
 *
 * It is the only subscriber of {@see LogAggregatorAgent} (HIL-756), and the subscription is a claim
 * of interest repeated on its own tick rather than a pair of subscribe and unsubscribe frames: the
 * viewer count both renews the lease and cancels it by being zero. That is what makes an aggregator
 * restarted or moved by the placement policy repair itself - the new instance starts with an empty
 * register, and the next ordinary claim puts this agent back in it, with no protocol for asking
 * anybody to resubscribe.
 *
 * What arrives is filed in {@see ClusterLogIndexMirror}, which the pages of the section then read.
 * The mirror rather than the agent, because the picture belongs to the worker process and outlives
 * any one page dispatch - and because the pages read it without going through their agent at all.
 */
abstract class AbstractHilosLogsAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_LOGS;

    /**
     * The one frame it takes: the cluster picture, whole or in part.
     *
     * No index field and no node field, because there is nothing to address by - this agent is one
     * instance for the whole cluster and so is the aggregator that sends to it.
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::LOGS_CLUSTER_INDEX_PORTION => ClusterLogIndexPortionSignalData::class,
    ];

    /**
     * Cluster membership, which the overview reads while building its payload.
     *
     * Declared on the class rather than registered from a start hook so the worker raises the
     * interest and waits for the copy BEFORE this instance exists: the first frame can arrive on
     * the tick after the start, and the answer it produces must not depend on that race. The
     * declaration mirrors {@see LogAggregatorAgent::READS_RT} for the same reason on the other end.
     *
     * @var list<string>
     */
    public const array READS_RT = [HilosClusterNode::RT_COLLECTION];

    /**
     * @var float Seconds after which the claim is repeated though nothing about it changed
     *
     * The lease renewal, and the reason a subscription survives a lost frame. Three of these fit
     * inside {@see LogAggregatorAgent}'s lease, so one missed claim costs nothing.
     */
    private const float WATCH_KEEPALIVE_INTERVAL_SECONDS = 30.0;

    /**
     * @var float Seconds viewers may wait for a first picture before the wait is written down
     *
     * Long enough that an aggregator merely being slow to answer is not reported as a fault, and
     * measured from the moment people started waiting rather than from the last claim: a section
     * whose viewer count keeps changing sends a claim on every change, and a wait counted from
     * those would restart with every new tab and never be reported at all.
     */
    private const float PICTURE_WAIT_COMPLAINT_SECONDS = 30.0;

    /** @var float Wall clock of the last claim sent, the keepalive is measured from it */
    private float $lastWatchAt = 0.0;

    /** @var ?int Viewer count the aggregator was last told, null before anything was claimed */
    private ?int $lastReportedViewers = null;

    /** @var ?float Wall clock at which people started waiting on a picture, null when nobody is */
    private ?float $picturelessSince = null;

    /** @var bool Whether the wait for a first picture has already been written down */
    private bool $pictureComplained = false;

    /**
     * Claims the section's interest when it is due, then lets the overview page have its tick.
     *
     * In that order, because the claim is what keeps the frames coming that the page draws from: a
     * tick spent refreshing a picture whose subscription has quietly lapsed would show stale
     * figures for as long as it took anybody to notice.
     *
     * @throws InvalidArgumentException When the claim or the overview signal cannot be named
     */
    public function onTick(): void
    {
        $this->watchIfDue(microtime(true));
        AbstractHilosLogsPage::onAgentTick($this);
    }

    /**
     * Forgets the cluster picture on the way out.
     *
     * The mirror is static and so outlives this instance: an agent restarted in the same worker
     * would otherwise serve the picture of its previous life until the first frame of the new one
     * arrived, and a page drawn from it would show figures nobody is subscribed for. The picture
     * surviving a VIEWER leaving is the opposite case and deliberate - there the subscription and
     * this agent are both still alive.
     */
    public function onStop(): void
    {
        ClusterLogIndexMirror::forgetPicture();
    }

    /**
     * Files one frame of the cluster picture.
     *
     * The door is thin on purpose: the payload is already parsed and checked against the class this
     * agent declared, so all that is left is to hand it to the mirror. Nothing is answered back -
     * the frame travels one way, and the next claim of interest is due on this agent's own tick
     * whatever happened to this one.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $source Signal source (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the agent is reached by a signal it does not own
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::LOGS_CLUSTER_INDEX_PORTION:
                if ($data->data instanceof ClusterLogIndexPortionSignalData) {
                    ClusterLogIndexMirror::applyPortion($data->data);
                }

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Drops the closed connection from the logs overview subscriber set.
     *
     * @param WebSocketCloseSignalDTO $data Close signal payload (carries the acceptKey)
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        AbstractHilosLogsPage::removeSubscriber($data->acceptKey);
    }

    /**
     * Tells the aggregator how many people are watching, when there is a reason to say it.
     *
     * Two of them, and the rule is {@see LogStoreAgent::pushIndexIfDue()}'s one level up. A count
     * that CHANGED goes out at once, zero included and zero especially: a claim of zero is what
     * cancels the subscription, and holding it back would let frames outlive the last viewer by a
     * whole lease. A count that has not changed goes out once per keepalive, which is what renews
     * the lease - unless it is zero, because there is then no subscription to renew and a claim
     * every thirty seconds would be an idle cluster talking to itself.
     *
     * The clock is a parameter rather than a reading, the way {@see LogStoreAgent::pushIndexIfDue()}
     * takes its own: the tick is only this method's throttle, and when a claim is due should not
     * depend on when the loop got round to asking. Protected for the same reason that one is
     * public - a keepalive of thirty seconds cannot be reached by a test that has to wait for it.
     *
     * @param float $now Wall clock of this tick
     * @throws InvalidArgumentException When the claim cannot be named
     */
    protected function watchIfDue(float $now): void
    {
        $this->forgetDisconnectedViewers();

        $viewers = ClusterLogIndexMirror::viewerCount();
        $this->noteThePictureWait($viewers, $now);

        if ($viewers !== ($this->lastReportedViewers ?? 0)) {
            $this->claimInterest($viewers, $now);

            return;
        }
        if ($viewers === 0 || $now - $this->lastWatchAt < self::WATCH_KEEPALIVE_INTERVAL_SECONDS) {
            return;
        }

        $this->claimInterest($viewers, $now);
    }

    /**
     * Sends one claim of interest and remembers what the aggregator has now been told.
     *
     * @param int $viewers Viewers to claim, zero to cancel the subscription
     * @param float $now Wall clock the keepalive is measured from after this
     * @throws InvalidArgumentException When the claim cannot be named
     */
    private function claimInterest(int $viewers, float $now): void
    {
        $this->sendToAgent(HilosSignalConstants::LOGS_INDEX_WATCH, new LogsIndexWatchSignalData($viewers));
        $this->lastReportedViewers = $viewers;
        $this->lastWatchAt = $now;
    }

    /**
     * Keeps the clock on a wait for a first picture, and writes that wait down once it is long.
     *
     * Once and not per tick: the aggregator being unplaced or moving is a state that lasts, and a
     * line a tick would bury the journal of the very node an administrator came to read. Both the
     * clock and the flag are cleared as soon as a picture arrives or the last viewer leaves, so a
     * later blackout is timed afresh and heard about again.
     *
     * @param int $viewers Viewers watching the section right now
     * @param float $now Wall clock of this tick
     */
    private function noteThePictureWait(int $viewers, float $now): void
    {
        if ($viewers === 0 || ClusterLogIndexMirror::known()) {
            $this->picturelessSince = null;
            $this->pictureComplained = false;

            return;
        }

        $this->picturelessSince ??= $now;
        if ($this->pictureComplained || $now - $this->picturelessSince < self::PICTURE_WAIT_COMPLAINT_SECONDS) {
            return;
        }

        $this->pictureComplained = true;
        $this->logAgentWarning(
            "Logs section: {$viewers} viewer(s) waiting, and the cluster log aggregator has sent no picture yet",
        );
    }

    /**
     * Stops counting viewers whose connection is no longer on the node's roster.
     *
     * A tab that closed cleanly is released by the page's own unsubscribe; this is what catches the
     * one that went without a word, the way {@see LogStoreAgent} catches an abandoned follow. Left
     * counted, such a viewer would hold the subscription open forever and keep an unwatched cluster
     * sending frames.
     *
     * A project with no connections collection at all is left alone rather than emptied: there the
     * unanswerable question is "is this viewer still here", and answering it with "no" would strike
     * out every viewer on the tick after they subscribed, taking the whole section's live picture
     * with it. Its pages keep the set the way they did before any of this existed - by the
     * unsubscribe and the close they are told about.
     */
    private function forgetDisconnectedViewers(): void
    {
        $connections = Hilos::$rt?->connectionsSource();
        if ($connections === null) {
            return;
        }

        foreach (ClusterLogIndexMirror::viewerKeys() as $acceptKey) {
            if ($connections->get($acceptKey) === null) {
                ClusterLogIndexMirror::removeViewer($acceptKey);
            }
        }
    }
}
