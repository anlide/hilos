<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\AdminViewMode\WireField;
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
use Hilos\Database\DatabaseException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\DTO\HilosDaemonOverviewData;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;
use JsonException;

/**
 * AbstractHilosDaemonPage - Abstract base for Hilos daemon dashboard page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Daemon\DaemonPage).
 */
abstract class AbstractHilosDaemonPage extends AbstractHilosDaemonSectionPage
{
    public const string PAGE = HilosPageConstants::HILOS_DAEMON;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON,
    ];

    public const string DAEMON = 'daemon';
    public const string DATABASE = 'database';

    /** @var list<string> Membership register named for the frozen-replica mark on the mirror */
    public const array READS_RT = [HilosClusterNode::RT_COLLECTION];

    private static ?string $overviewFingerprint = null;

    /**
     * @param ?ClusterDaemonPicture $before Immutable picture before this portion
     * @throws HilosException When overview data cannot be projected or encoded
     * @throws EnvException When cluster mode cannot be read
     */
    public static function onPictureChanged(?ClusterDaemonPicture $before): void
    {
        $fingerprint = null;
        $previousFingerprint = self::$overviewFingerprint;
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
                        throw new HilosException('Cannot fingerprint the daemon overview picture', previous: $exception);
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
            self::$overviewFingerprint = $fingerprint;
        }
    }

    /** Forgets the comparison state when the page agent stops. */
    public static function onPictureForgotten(): void
    {
        self::$overviewFingerprint = null;
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Root route parameters
     * @return PagePayload Technical overview with full placement totals and primary database metadata
     * @throws InvalidArgumentException When a generated environment cell contradicts its state
     * @throws EnvException When cluster mode cannot be read
     * @throws HilosException When the overview fingerprint cannot be encoded
     * @throws DatabaseException When the previously active database connection cannot be restored
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $overview = self::overview()?->toArray();
        try {
            self::$overviewFingerprint = json_encode($overview, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HilosException('Cannot fingerprint the daemon overview picture', previous: $exception);
        }

        return new PagePayload(data: [
            self::DAEMON => $overview,
            self::DATABASE => (new HilosDaemonDatabaseReader())->read()?->toArray(),
        ]);
    }

    /** @return array<string, WireField> Both root diagnostics carry no personal data */
    protected function dataFields(): array
    {
        return self::pageDataFields();
    }

    /** @return array<string, WireField> The same root data declarations for initial and live answers */
    private static function pageDataFields(): array
    {
        return [
            self::DAEMON => WireField::notPersonal(),
            self::DATABASE => WireField::notPersonal(),
        ];
    }

    /**
     * @return ?HilosDaemonOverviewData Projection only after the first full mirror snapshot
     * @throws InvalidArgumentException When a generated environment cell contradicts its state
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
