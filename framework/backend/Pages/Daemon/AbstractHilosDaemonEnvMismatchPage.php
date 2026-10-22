<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\AdminViewMode\WireField;
use Hilos\Cluster\Exception\ClusterException;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\DaemonPictureViewportTable;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonEnvMismatchSummary;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Tables\Daemon\HilosDaemonEnvDivergedTable;
use Hilos\Tables\Daemon\HilosDaemonEnvSourcesDifferTable;
use JsonException;

/** Cluster environment comparison page: own part names nodes and verdict counters; answers on standalone rather than refusing. */
abstract class AbstractHilosDaemonEnvMismatchPage extends AbstractHilosDaemonSectionPage
{
    public const string PAGE = HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH;
    public const string ENV_MISMATCH = 'envMismatch';

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_ENV_MISMATCH,
    ];

    private static ?string $summaryFingerprint = null;

    /**
     * @param ?ClusterDaemonPicture $before Immutable picture before this portion
     * @throws HilosException When comparison metadata cannot be projected or encoded
     * @throws EnvException When cluster mode or node id cannot be read
     * @throws ClusterException When cluster configuration or node identity is invalid
     * @throws InvalidArgumentException When an environment cell is invalid
     */
    public static function onPictureChanged(?ClusterDaemonPicture $before): void
    {
        self::publishChangedPage();
        foreach (Hilos::$sr?->getAcceptKeysForPage(self::PAGE) ?? [] as $acceptKey) {
            foreach ([HilosDaemonEnvDivergedTable::TABLE, HilosDaemonEnvSourcesDifferTable::TABLE] as $tableKey) {
                Hilos::$browser?->publishTableMutations(
                    self::PAGE,
                    $acceptKey,
                    $tableKey,
                    static fn (DaemonPictureViewportTable $table, TableQueryDTO $query): array => $table->pictureMutations($before, $query),
                );
            }
        }
    }

    /** Forgets the comparison and summary when the page agent stops. */
    public static function onPictureForgotten(): void
    {
        self::$summaryFingerprint = null;
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Merged route parameters
     * @throws HilosException When the page answer fails
     */
    final public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    /**
     * @param string $acceptKey Subscribing connection, unused
     * @param PageRouteParams $params Route parameters, unused
     * @return PagePayload Own page data with cluster mode, serving node and verdict counters
     * @throws HilosException When summary data cannot be encoded
     * @throws EnvException When cluster mode or node id cannot be read from the environment
     * @throws ClusterException When cluster configuration or node identity is invalid
     * @throws InvalidArgumentException When environment comparison generates an invalid cell
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $summary = self::summary()->toArray();
        try {
            self::$summaryFingerprint = json_encode($summary, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HilosException('Cannot fingerprint the environment mismatch page', previous: $exception);
        }

        return new PagePayload(data: [self::ENV_MISMATCH => $summary]);
    }

    /** @return array<string, WireField> Mismatch summary carries no personal data */
    protected function dataFields(): array
    {
        return self::pageDataFields();
    }

    /** @return array<string, WireField> The same summary declarations for initial and live answers */
    private static function pageDataFields(): array
    {
        return [
            self::ENV_MISMATCH => WireField::notPersonal(),
        ];
    }

    /**
     * @return DaemonEnvMismatchSummary Summary of cluster mode, serving node and verdict counters
     * @throws EnvException When cluster mode or node id cannot be read from the environment
     * @throws ClusterException When cluster configuration or node identity is invalid
     * @throws InvalidArgumentException When environment comparison generates an invalid cell
     */
    private static function summary(): DaemonEnvMismatchSummary
    {
        return DaemonEnvMismatchSummary::of(
            Hilos::$cluster?->isEnabled() === true,
            Hilos::$cluster?->localNodeId() ?? HilosClusterNode::STANDALONE_NODE_ID,
            ClusterDaemonPictureMirror::picture(),
        );
    }

    /**
     * Publishes the changed comparison summary without replacing its tables.
     *
     * @throws HilosException When the page summary cannot be encoded
     * @throws EnvException When cluster mode or node id cannot be read from the environment
     * @throws ClusterException When cluster configuration or node identity is invalid
     * @throws InvalidArgumentException When environment comparison generates an invalid cell
     */
    private static function publishChangedPage(): void
    {
        $fingerprint = null;
        $previousFingerprint = self::$summaryFingerprint;
        foreach (Hilos::$sr?->getAcceptKeysForPage(self::PAGE) ?? [] as $acceptKey) {
            Hilos::$browser?->publishPageData(
                self::PAGE,
                $acceptKey,
                static function () use ($acceptKey, &$fingerprint, $previousFingerprint): PagePayload {
                    if (Hilos::$rt?->connectionsSource()?->get($acceptKey) === null) {
                        return new PagePayload();
                    }

                    $data = self::summary()->toArray();
                    try {
                        $fingerprint = json_encode($data, JSON_THROW_ON_ERROR);
                    } catch (JsonException $exception) {
                        throw new HilosException('Cannot fingerprint the environment mismatch page', previous: $exception);
                    }

                    return new PagePayload(data: $fingerprint === $previousFingerprint ? [] : [self::ENV_MISMATCH => $data]);
                },
                self::pageDataFields(),
            );
        }
        if ($fingerprint !== null) {
            self::$summaryFingerprint = $fingerprint;
        }
    }
}
