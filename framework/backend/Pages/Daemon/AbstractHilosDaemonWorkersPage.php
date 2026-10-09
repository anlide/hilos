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
use Hilos\DaemonSection\DTO\DaemonMasterProcessRosterSignalData;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use JsonException;

/**
 * AbstractHilosDaemonWorkersPage - Abstract base for Hilos daemon workers list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Daemon\DaemonWorkersPage).
 */
abstract class AbstractHilosDaemonWorkersPage extends AbstractHilosDaemonNodePage
{
    public const string PAGE = HilosPageConstants::HILOS_DAEMON_WORKERS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_WORKERS,
    ];

    private static ?string $workersFingerprint = null;

    /**
     * Re-sends open worker windows when a new picture portion changes a node's worker roster.
     *
     * @throws HilosException When the picture cannot be encoded or a table source refuses a read
     * @throws InvalidArgumentException When the table-window signal cannot be named
     * @throws TableRowKeyMissingException When a windowed row has no key
     */
    public static function onPictureChanged(): void
    {
        $sections = [];
        foreach (ClusterDaemonPictureMirror::picture()?->nodes() ?? [] as $node) {
            $processes = $node->slot?->picture->processes;
            $sections[] = [
                $node->nodeId,
                $processes === null
                    ? null
                    : DaemonMasterProcessRosterSignalData::rosterToArray($processes)[DaemonMasterProcessRosterSignalData::workers],
            ];
        }

        try {
            $fingerprint = json_encode($sections, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HilosException('Cannot fingerprint the daemon workers picture', previous: $exception);
        }
        if ($fingerprint === self::$workersFingerprint) {
            return;
        }
        self::$workersFingerprint = $fingerprint;

        foreach (ClusterDaemonPictureMirror::viewerKeys() as $acceptKey) {
            $viewport = Hilos::$sr?->getTableViewport($acceptKey, HilosDaemonWorkersTable::TABLE);
            if ($viewport === null) {
                continue;
            }
            Hilos::$browser?->sendTableWindow(HilosPageConstants::HILOS_DAEMON_WORKERS, $acceptKey, $viewport);
        }
    }

    /** Forgets the comparison state when the page agent stops. */
    public static function onPictureForgotten(): void
    {
        self::$workersFingerprint = null;
    }
}
