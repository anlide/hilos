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
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
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

    private static ?string $placementFingerprint = null;

    /**
     * Re-answers changed root data whole, or changed placement windows on their own.
     *
     * @throws HilosException When the picture cannot be encoded or a window cannot be served
     * @throws InvalidArgumentException When the table-window signal cannot be named
     * @throws TableRowKeyMissingException When a windowed row has no key
     * @throws EnvException When cluster mode cannot be read
     */
    public static function onPictureChanged(): void
    {
        $picture = ClusterDaemonPictureMirror::picture();
        try {
            $overviewFingerprint = json_encode(self::overview()?->toArray(), JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HilosException('Cannot fingerprint the daemon overview picture', previous: $exception);
        }
        $wholeAttempts = [];
        if ($overviewFingerprint !== self::$overviewFingerprint) {
            self::$overviewFingerprint = $overviewFingerprint;
            foreach (Hilos::$sr?->getAcceptKeysForPage(self::PAGE) ?? [] as $acceptKey) {
                if (Hilos::$browser === null || Hilos::$rt?->connectionsSource()?->get($acceptKey) === null) {
                    continue;
                }
                Hilos::$sr->clearPageDeliveryFailure($acceptKey);
                Hilos::$browser->resendPageWhole(self::PAGE, $acceptKey);
                // The void delivery API contains refusals and failures. Suppress a separate window
                // after every whole attempt: an answer included it, and an error wiped its page scope.
                $wholeAttempts[$acceptKey] = true;
            }
        }

        $sections = [];
        foreach ($picture?->nodes() ?? [] as $node) {
            $processes = $node->slot?->picture->processes;
            $agentIds = null;
            if ($processes !== null) {
                $agentIds = [];
                foreach ($processes->workers as $worker) {
                    foreach ($worker->agents as $agent) {
                        $agentIds[] = $agent->id;
                    }
                }
            }
            $sections[] = [$node->nodeId, $node->online, $agentIds];
        }
        try {
            $fingerprint = json_encode([$sections, HilosDaemonPlacementTable::unplacedOf($picture)], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HilosException('Cannot fingerprint the daemon placement picture', previous: $exception);
        }
        if ($fingerprint === self::$placementFingerprint) {
            return;
        }
        self::$placementFingerprint = $fingerprint;

        foreach (ClusterDaemonPictureMirror::viewerKeys() as $acceptKey) {
            if (isset($wholeAttempts[$acceptKey])) {
                continue;
            }
            $viewport = Hilos::$sr?->getTableViewport($acceptKey, HilosDaemonPlacementTable::TABLE);
            if ($viewport === null) {
                continue;
            }
            // A placement fact can pay a page debt too. The generic window road would send
            // the whole page and then another window; keep recovery to the one page answer.
            if (Hilos::$browser !== null && Hilos::$sr->clearPageDeliveryFailure($acceptKey)) {
                Hilos::$browser->resendPageWhole(self::PAGE, $acceptKey);
                continue;
            }
            Hilos::$browser?->sendTableWindow(HilosPageConstants::HILOS_DAEMON, $acceptKey, $viewport);
        }
    }

    /** Forgets the comparison state when the page agent stops. */
    public static function onPictureForgotten(): void
    {
        self::$overviewFingerprint = null;
        self::$placementFingerprint = null;
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Root route parameters
     * @return PagePayload Technical overview and primary database metadata
     * @throws InvalidArgumentException When a generated environment cell contradicts its state
     * @throws EnvException When cluster mode cannot be read
     * @throws DatabaseException When the previously active database connection cannot be restored
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        return new PagePayload(data: [
            self::DAEMON => self::overview()?->toArray(),
            self::DATABASE => (new HilosDaemonDatabaseReader())->read()?->toArray(),
        ]);
    }

    /** @return array<string, WireField> Both root diagnostics carry no personal data */
    protected function dataFields(): array
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
