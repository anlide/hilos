<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
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

    private static ?string $placementFingerprint = null;

    /**
     * Resends whole open matrix windows after a changed process portion.
     *
     * @throws HilosException When the picture cannot be encoded or a window cannot be served
     * @throws InvalidArgumentException When the table-window signal cannot be named
     * @throws TableRowKeyMissingException When a windowed row has no key
     * @throws EnvException When cluster mode cannot be read
     */
    public static function onPictureChanged(): void
    {
        $picture = ClusterDaemonPictureMirror::picture();
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
            $viewport = Hilos::$sr?->getTableViewport($acceptKey, HilosDaemonPlacementTable::TABLE);
            if ($viewport === null) {
                continue;
            }
            Hilos::$browser?->sendTableWindow(HilosPageConstants::HILOS_DAEMON, $acceptKey, $viewport);
        }
    }

    /** Forgets the comparison state when the page agent stops. */
    public static function onPictureForgotten(): void
    {
        self::$placementFingerprint = null;
    }
}
