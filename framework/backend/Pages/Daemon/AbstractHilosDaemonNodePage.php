<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonNodeHeading;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\DTO\HilosDaemonNodeSubscribeParams;
use Hilos\Runtime\State\Item\HilosClusterNode;
use JsonException;

/** Shared admission of a Daemon page addressed to one known node. */
abstract class AbstractHilosDaemonNodePage extends AbstractHilosDaemonSectionPage
{
    public const string NODE = 'node';
    public const string PROCESSES_REPORTED = 'processesReported';

    /** @var list<string> Runtime roster read when a node child page is subscribed to */
    public const array READS_RT = [HilosClusterNode::RT_COLLECTION];

    /** @var array<string, array<string, string>> Last page-data fingerprint by page and node id */
    private static array $nodeDataFingerprints = [];

    /**
     * @param string $nodeId Node to describe
     * @return array<string, mixed> Own page data for the node
     * @throws EnvException When cluster mode cannot be read
     */
    protected static function nodeData(string $nodeId): array
    {
        return [self::NODE => self::headingOf($nodeId)->toArray()];
    }

    /**
     * @return array<string, true> Connections re-answered with a whole page
     * @throws HilosException When the page data cannot be encoded
     * @throws EnvException When cluster mode cannot be read
     */
    protected static function resendChangedNodePages(): array
    {
        $resent = [];
        // TODO: This payload uses the first portion of the cluster picture before the full snapshot;
        // a node missing from that portion reads as absent. Known exception to
        // docs/agents/signals/work-in-portions.md, "Part Or Whole".
        // The owner chose (17.10.2026, HIL-1529) to see it live before deciding between waiting
        // for the full snapshot, as the Daemon overview does in
        // framework/backend/Pages/Daemon/DTO/HilosDaemonOverviewData.php::fromPicture(),
        // and marking the portion as "still gathering".
        foreach (ClusterDaemonPictureMirror::picture()?->nodes() ?? [] as $node) {
            try {
                $fingerprint = json_encode(static::nodeData($node->nodeId), JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new HilosException('Cannot fingerprint the daemon node heading', previous: $exception);
            }
            if ($fingerprint === (self::$nodeDataFingerprints[static::PAGE][$node->nodeId] ?? null)) {
                continue;
            }
            self::$nodeDataFingerprints[static::PAGE][$node->nodeId] = $fingerprint;
            foreach (Hilos::$sr?->getAcceptKeysForPage(
                static::PAGE,
                HilosPageRouteParams::HILOS_DAEMON_NODE_ID,
                $node->nodeId,
            ) ?? [] as $acceptKey) {
                if (Hilos::$rt?->connectionsSource()?->get($acceptKey) !== null) {
                    Hilos::$browser?->resendPageWhole(static::PAGE, $acceptKey);
                    $resent[$acceptKey] = true;
                }
            }
        }

        return $resent;
    }

    /** Forgets only this page's node-line comparison state. */
    protected static function forgetNodePages(): void
    {
        unset(self::$nodeDataFingerprints[static::PAGE]);
    }

    /**
     * @param string $nodeId Node to describe
     * @return DaemonNodeHeading Current node line
     * @throws EnvException When cluster mode cannot be read
     */
    protected static function headingOf(string $nodeId): DaemonNodeHeading
    {
        return DaemonNodeHeading::of(
            ClusterDaemonPictureMirror::picture(),
            $nodeId,
            Hilos::$cluster?->isEnabled() === true,
        );
    }

    /**
     * @param string $nodeId Node whose worker report is read
     * @return bool Whether the node has reported its worker roster
     */
    protected static function processesReportedOf(string $nodeId): bool
    {
        return ClusterDaemonPictureMirror::picture()?->node($nodeId)?->slot?->picture->processes !== null;
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Raw route parameters
     * @throws MissingPageRouteParamException When nodeId is missing or empty
     * @throws PageResourceNotFoundException When the requested node is unknown
     * @throws HilosException When the runtime roster cannot be read
     */
    final protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $address = HilosDaemonNodeSubscribeParams::fromPageRouteParams($params);
        if (Hilos::$rt->hilosClusterNodes[$address->nodeId] === null) {
            throw new PageResourceNotFoundException('Unknown Daemon node: ' . $address->nodeId);
        }

        $this->onDaemonNodeSubscribeBeforeResponse($acceptKey, $address);
    }

    /**
     * Re-checks the node before refreshing the entire page answer.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Merged route parameters
     * @throws HilosException When the node is missing or the page answer fails
     */
    final public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    /**
     * Extension seam for the node page leaves that need work before the response.
     *
     * @param string $acceptKey Subscribing connection
     * @param HilosDaemonNodeSubscribeParams $address Validated node address
     * @throws HilosException When the subclass refuses or cannot build its answer
     */
    protected function onDaemonNodeSubscribeBeforeResponse(
        string $acceptKey,
        HilosDaemonNodeSubscribeParams $address,
    ): void {
    }
}
