<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\DTO\DaemonPictureWatchSignalData;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\AbstractHilosDaemonCronPage;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;

/** Project-bound page agent holding the worker-local Daemon mirror and its interest lease. */
abstract class AbstractHilosDaemonAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_DAEMON;

    public const array AGENT_SIGNALS = [
        HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION => DaemonClusterPicturePortionSignalData::class,
    ];

    private const float WATCH_KEEPALIVE_INTERVAL_SECONDS = 30.0;
    private const float FIRST_PICTURE_RETRY_INTERVAL_SECONDS = 1.0;
    private const float PICTURE_WAIT_COMPLAINT_SECONDS = 30.0;

    private float $lastWatchAt = 0.0;
    private ?int $lastReportedViewers = null;
    private ?float $fullSnapshotWaitSince = null;
    private bool $pictureComplained = false;

    /** @var array<string, true> Viewers observed in this worker's connection roster */
    private array $rosteredViewers = [];

    /** @throws InvalidArgumentException When a claim cannot be routed */
    public function onTick(): void
    {
        $this->watchIfDue(microtime(true));
    }

    /** The mirror is worker static and must forget a stopped agent's old picture. */
    public function onStop(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonCronPage::onPictureForgotten();
        $this->rosteredViewers = [];
    }

    /**
     * @param AgentSignalData $data Parsed frame
     * @param string $sender Full sender address, unused
     * @param string $name Signal name
     * @throws AgentUnknownSignalException When the signal is not this agent's
     * @throws InvalidArgumentException When a table-window signal cannot be named
     * @throws TableRowKeyMissingException When a windowed cron row has no key
     * @throws HilosException When a cron picture or its window cannot be served
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION:
                if ($data->data instanceof DaemonClusterPicturePortionSignalData) {
                    ClusterDaemonPictureMirror::applyPortion($data->data);
                    AbstractHilosDaemonCronPage::onPictureChanged();
                }

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /** Releases any page of this section when its connection closes. */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        ClusterDaemonPictureMirror::removeViewer($data->acceptKey);
    }

    /**
     * Changed counts go at once; a missing full snapshot is retried each second.
     *
     * @param float $now Wall clock of this tick
     * @throws InvalidArgumentException When the claim cannot be routed
     */
    protected function watchIfDue(float $now): void
    {
        $this->forgetDisconnectedViewers();
        $viewers = ClusterDaemonPictureMirror::viewerCount();
        $this->notePictureWait($viewers, $now);

        if ($viewers !== ($this->lastReportedViewers ?? 0)) {
            $this->claimInterest($viewers, $now);

            return;
        }
        if ($viewers === 0) {
            return;
        }
        $interval = ClusterDaemonPictureMirror::hasFullSnapshot()
            ? self::WATCH_KEEPALIVE_INTERVAL_SECONDS
            : self::FIRST_PICTURE_RETRY_INTERVAL_SECONDS;
        if ($now - $this->lastWatchAt >= $interval) {
            $this->claimInterest($viewers, $now);
        }
    }

    /** @throws InvalidArgumentException When the claim cannot be routed */
    private function claimInterest(int $viewers, float $now): void
    {
        $this->sendToAgent(HilosSignalConstants::DAEMON_PICTURE_WATCH, new DaemonPictureWatchSignalData($viewers));
        $this->lastReportedViewers = $viewers;
        $this->lastWatchAt = $now;
    }

    /** Warns only once after thirty seconds without a full snapshot. */
    private function notePictureWait(int $viewers, float $now): void
    {
        if ($viewers === 0 || ClusterDaemonPictureMirror::hasFullSnapshot()) {
            $this->fullSnapshotWaitSince = null;
            $this->pictureComplained = false;

            return;
        }
        $this->fullSnapshotWaitSince ??= $now;
        if (!$this->pictureComplained && $now - $this->fullSnapshotWaitSince >= self::PICTURE_WAIT_COMPLAINT_SECONDS) {
            $this->pictureComplained = true;
            $this->logAgentWarning('Daemon section: first full cluster picture has not arrived for its viewers');
        }
    }

    /** Removes only connections this worker's roster once knew and then lost. */
    private function forgetDisconnectedViewers(): void
    {
        $connections = Hilos::$rt?->connectionsSource();
        if ($connections === null) {
            return;
        }

        $viewers = array_fill_keys(ClusterDaemonPictureMirror::viewerKeys(), true);
        $this->rosteredViewers = array_intersect_key($this->rosteredViewers, $viewers);
        foreach ($viewers as $acceptKey => $_) {
            if ($connections->get($acceptKey) !== null) {
                $this->rosteredViewers[$acceptKey] = true;
            } elseif (isset($this->rosteredViewers[$acceptKey])) {
                ClusterDaemonPictureMirror::removeViewer($acceptKey);
                unset($this->rosteredViewers[$acceptKey]);
            }
        }
    }
}
