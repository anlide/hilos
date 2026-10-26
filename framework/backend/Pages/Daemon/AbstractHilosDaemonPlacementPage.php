<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\DaemonPictureViewportTable;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\DTO\HilosDaemonOverviewData;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;
use JsonException;

/**
 * Placement data and table publication from the worker-local Daemon mirror.
 */
abstract class AbstractHilosDaemonPlacementPage extends AbstractHilosDaemonSectionPage
{
    public const string PAGE = HilosPageConstants::HILOS_DAEMON_PLACEMENT;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_PLACEMENT,
    ];

    public const string DAEMON = 'daemon';

    /** @var list<string> Membership register named for the frozen-replica mark on the mirror */
    public const array READS_RT = [HilosClusterNode::RT_COLLECTION];

    /** Worker-local publication comparison, discarded with the page agent's mirror. */
    private static ?string $placementFingerprint = null;

    /**
     * @param ?ClusterDaemonPicture $before Immutable picture before this portion
     * @throws HilosException When placement data cannot be projected or encoded
     * @throws EnvException When cluster mode cannot be read
     */
    public static function onPictureChanged(?ClusterDaemonPicture $before): void
    {
        $fingerprint = null;
        $previousFingerprint = self::$placementFingerprint;
        foreach (Hilos::$sr?->getAcceptKeysForPage(self::PAGE) ?? [] as $acceptKey) {
            Hilos::$browser?->publishPageData(
                self::PAGE,
                $acceptKey,
                static function () use ($acceptKey, &$fingerprint, $previousFingerprint): PagePayload {
                    if (Hilos::$rt?->connectionsSource()?->get($acceptKey) === null) {
                        return new PagePayload();
                    }

                    $data = self::overview()?->toArray();
                    try {
                        $fingerprint = json_encode($data, JSON_THROW_ON_ERROR);
                    } catch (JsonException $exception) {
                        throw new HilosException('Cannot fingerprint the daemon placement picture', previous: $exception);
                    }

                    return new PagePayload(data: $fingerprint === $previousFingerprint ? [] : [self::DAEMON => $data]);
                },
                self::pageDataFields(),
            );
            Hilos::$browser?->publishTableMutations(
                self::PAGE,
                $acceptKey,
                HilosDaemonPlacementTable::TABLE,
                static fn (DaemonPictureViewportTable $table, TableQueryDTO $query): array => $table->pictureMutations($before, $query),
            );
        }
        if ($fingerprint !== null) {
            self::$placementFingerprint = $fingerprint;
        }
    }

    /** Forgets the comparison state when the page agent stops. */
    public static function onPictureForgotten(): void
    {
        self::$placementFingerprint = null;
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Placement route parameters
     * @return PagePayload Technical daemon overview with placement totals
     * @throws EnvException When cluster mode cannot be read
     * @throws HilosException When the placement fingerprint cannot be encoded
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $overview = self::overview()?->toArray();
        try {
            self::$placementFingerprint = json_encode($overview, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HilosException('Cannot fingerprint the daemon placement picture', previous: $exception);
        }

        return new PagePayload(data: [
            self::DAEMON => $overview,
        ]);
    }

    /** @return array<string, WireField> Technical daemon diagnostics carry no personal data */
    protected function dataFields(): array
    {
        return self::pageDataFields();
    }

    /** @return array<string, WireField> The same placement data declarations for initial and live answers */
    private static function pageDataFields(): array
    {
        return [
            self::DAEMON => WireField::notPersonal(),
        ];
    }

    /**
     * @return ?HilosDaemonOverviewData Projection only after the first full mirror snapshot
     * @throws EnvException When cluster mode cannot be read
     */
    private static function overview(): ?HilosDaemonOverviewData
    {
        return HilosDaemonOverviewData::fromPicture(
            ClusterDaemonPictureMirror::picture(),
            Hilos::$cluster?->isEnabled() === true,
            ClusterDaemonPictureMirror::hasFullSnapshot(),
        );
    }
}
