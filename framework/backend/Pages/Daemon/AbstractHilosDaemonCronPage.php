<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DTO\DaemonNodePictureSignalData;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use JsonException;

/**
 * AbstractHilosDaemonCronPage - Abstract base for Hilos daemon cron list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Daemon\DaemonCronPage).
 */
abstract class AbstractHilosDaemonCronPage extends AbstractHilosDaemonNodePage
{
    public const string PAGE = HilosPageConstants::HILOS_DAEMON_CRON;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_CRON,
    ];

    private static ?string $cronFingerprint = null;

    /**
     * Re-sends open cron windows when a new picture portion changes a node's cron section.
     *
     * @throws HilosException When the picture cannot be encoded or a table source refuses a read
     * @throws InvalidArgumentException When the table-window signal cannot be named
     * @throws TableRowKeyMissingException When a windowed row has no key
     */
    public static function onPictureChanged(): void
    {
        $sections = [];
        foreach (ClusterDaemonPictureMirror::picture()?->nodes() ?? [] as $node) {
            $cron = $node->slot?->picture->cron;
            $sections[] = [$node->nodeId, $cron === null ? null : DaemonNodePictureSignalData::cronToArray($cron)];
        }

        try {
            $fingerprint = json_encode($sections, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HilosException('Cannot fingerprint the daemon cron picture', previous: $exception);
        }
        if ($fingerprint === self::$cronFingerprint) {
            return;
        }
        self::$cronFingerprint = $fingerprint;

        foreach (ClusterDaemonPictureMirror::viewerKeys() as $acceptKey) {
            $viewport = Hilos::$sr?->getTableViewport($acceptKey, HilosDaemonCronTable::TABLE);
            if ($viewport === null) {
                continue;
            }
            Hilos::$browser?->sendTableWindow(HilosPageConstants::HILOS_DAEMON_CRON, $acceptKey, $viewport);
        }
    }

    /** Forgets the comparison state when the page agent stops. */
    public static function onPictureForgotten(): void
    {
        self::$cronFingerprint = null;
    }
}
