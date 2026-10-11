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
use Hilos\DaemonSection\DTO\DaemonMasterHttpSignalData;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTable;
use JsonException;

/**
 * AbstractHilosDaemonHttpServerPage - Abstract base for Hilos daemon HTTP server detail page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Daemon\DaemonHttpServerPage).
 */
abstract class AbstractHilosDaemonHttpServerPage extends AbstractHilosDaemonNodePage
{
    public const string PAGE = HilosPageConstants::HILOS_DAEMON_HTTP_SERVER;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_HTTP_SERVER,
    ];

    private static ?string $httpFingerprint = null;

    /**
     * Re-sends open HTTP route windows when a new picture portion changes a node's HTTP section.
     *
     * @throws HilosException When the picture cannot be encoded or a table source refuses a read
     * @throws InvalidArgumentException When the table-window signal cannot be named
     * @throws TableRowKeyMissingException When a windowed row has no key
     */
    public static function onPictureChanged(): void
    {
        $sections = [];
        foreach (ClusterDaemonPictureMirror::picture()?->nodes() ?? [] as $node) {
            $http = $node->slot?->picture->http;
            $sections[] = [$node->nodeId, $http === null ? null : DaemonMasterHttpSignalData::httpToArray($http)];
        }

        try {
            $fingerprint = json_encode($sections, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HilosException('Cannot fingerprint the daemon HTTP picture', previous: $exception);
        }
        if ($fingerprint === self::$httpFingerprint) {
            return;
        }
        self::$httpFingerprint = $fingerprint;

        foreach (ClusterDaemonPictureMirror::viewerKeys() as $acceptKey) {
            $viewport = Hilos::$sr?->getTableViewport($acceptKey, HilosDaemonHttpRoutesTable::TABLE);
            if ($viewport === null) {
                continue;
            }
            Hilos::$browser?->sendTableWindow(HilosPageConstants::HILOS_DAEMON_HTTP_SERVER, $acceptKey, $viewport);
        }
    }

    /** Forgets the comparison state when the page agent stops. */
    public static function onPictureForgotten(): void
    {
        self::$httpFingerprint = null;
    }
}
