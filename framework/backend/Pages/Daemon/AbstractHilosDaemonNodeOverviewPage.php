<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\DTO\HilosDaemonNodeOverviewData;
use Hilos\Pages\Daemon\DTO\HilosDaemonNodeSubscribeParams;

/**
 * Complete diagnostics of one addressed node, with the shared mirror admission and viewer lease.
 */
abstract class AbstractHilosDaemonNodeOverviewPage extends AbstractHilosDaemonNodePage
{
    public const string PAGE = HilosPageConstants::HILOS_DAEMON_NODE;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_NODE,
    ];

    public const string OVERVIEW = 'overview';

    /**
     * @param ?ClusterDaemonPicture $before Immutable picture before this portion
     * @throws HilosException When node data cannot be projected or encoded
     * @throws EnvException When cluster mode cannot be read
     */
    public static function onPictureChanged(?ClusterDaemonPicture $before): void
    {
        $nodeIds = self::subscribedNodeIds();
        self::forgetNodePages($nodeIds);
        static::publishChangedNodePages($nodeIds);
    }

    /** Forgets the node comparisons when the page agent stops. */
    public static function onPictureForgotten(): void
    {
        self::forgetNodePages();
    }

    /**
     * @param string $acceptKey Connection leaving or losing access to this page
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        parent::onUnsubscribe($acceptKey);
        self::forgetNodePages(self::subscribedNodeIds($acceptKey));
    }

    /**
     * @param string $nodeId Node to describe
     * @return array<string, mixed> Shared node line and full-snapshot-only diagnostics
     * @throws HilosException When the node projection rejects its source picture
     * @throws EnvException When cluster mode cannot be read
     */
    protected static function nodeData(string $nodeId): array
    {
        return parent::nodeData($nodeId) + [self::OVERVIEW => HilosDaemonNodeOverviewData::fromPicture(
            ClusterDaemonPictureMirror::picture(),
            $nodeId,
            Hilos::$cluster?->isEnabled() === true,
            ClusterDaemonPictureMirror::hasFullSnapshot(),
        )?->toArray()];
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route params naming the node
     * @return PagePayload Shared node line and full diagnostics in one answer
     * @throws HilosException When node data cannot be projected or encoded
     * @throws MissingPageRouteParamException When the node id is absent
     * @throws EnvException When cluster mode cannot be read
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        return static::nodePagePayload(HilosDaemonNodeSubscribeParams::fromPageRouteParams($params)->nodeId);
    }

    /** @return array<string, WireField> The same non-personal fields on initial and live delivery */
    protected static function nodeDataFields(): array
    {
        return parent::nodeDataFields() + [self::OVERVIEW => WireField::notPersonal()];
    }

    /**
     * @param string $acceptKey Connection opening or changing its node address
     * @param HilosDaemonNodeSubscribeParams $address Validated node address
     */
    protected function onDaemonNodeSubscribeBeforeResponse(
        string $acceptKey,
        HilosDaemonNodeSubscribeParams $address,
    ): void {
        self::forgetNodePages([
            ...self::subscribedNodeIds($acceptKey),
            $address->nodeId,
        ]);
    }

    /**
     * Includes failed subscriptions so a picture fact can retry their complete answer.
     *
     * @param ?string $exceptAcceptKey Connection being released or moving to another address
     * @return list<string> Unique valid addresses in this worker's subscription mirror
     */
    private static function subscribedNodeIds(?string $exceptAcceptKey = null): array
    {
        $nodeIds = [];
        foreach (Hilos::$sr?->pageSubscriptionRecords() ?? [] as $acceptKey => $subscription) {
            if ($acceptKey === $exceptAcceptKey || $subscription->page !== static::PAGE) {
                continue;
            }
            $nodeId = $subscription->params[HilosPageRouteParams::HILOS_DAEMON_NODE_ID] ?? null;
            if (is_string($nodeId) && $nodeId !== '') {
                $nodeIds[] = $nodeId;
            }
        }

        return array_values(array_unique($nodeIds));
    }
}
