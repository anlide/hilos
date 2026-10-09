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
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;
use JsonException;

/**
 * AbstractHilosDaemonAgentsPage - Abstract base for Hilos daemon agents list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Daemon\DaemonAgentsPage).
 */
abstract class AbstractHilosDaemonAgentsPage extends AbstractHilosDaemonNodePage
{
    public const string PAGE = HilosPageConstants::HILOS_DAEMON_AGENTS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_AGENTS,
    ];

    private static ?string $agentsFingerprint = null;

    /**
     * Re-sends open agent windows when a picture portion changes any node's started agents.
     *
     * @throws HilosException When the picture cannot be encoded or a table source refuses a read
     * @throws InvalidArgumentException When the table-window signal cannot be named
     * @throws TableRowKeyMissingException When a windowed row has no key
     */
    public static function onPictureChanged(): void
    {
        $sections = [];
        foreach (ClusterDaemonPictureMirror::picture()?->nodes() ?? [] as $node) {
            $roster = $node->slot?->picture->processes;
            $agents = null;
            if ($roster !== null) {
                $agents = [];
                foreach ($roster->workers as $worker) {
                    foreach ($worker->agents as $agent) {
                        $agents[] = [$agent->id, $worker->index, $worker->kind, $agent->placement];
                    }
                }
            }
            $sections[] = [$node->nodeId, $agents];
        }

        try {
            $fingerprint = json_encode($sections, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HilosException('Cannot fingerprint the daemon agents picture', previous: $exception);
        }
        if ($fingerprint === self::$agentsFingerprint) {
            return;
        }
        self::$agentsFingerprint = $fingerprint;

        foreach (ClusterDaemonPictureMirror::viewerKeys() as $acceptKey) {
            $viewport = Hilos::$sr?->getTableViewport($acceptKey, HilosDaemonAgentsTable::TABLE);
            if ($viewport === null) {
                continue;
            }
            Hilos::$browser?->sendTableWindow(HilosPageConstants::HILOS_DAEMON_AGENTS, $acceptKey, $viewport);
        }
    }

    /** Forgets the comparison state when the page agent stops. */
    public static function onPictureForgotten(): void
    {
        self::$agentsFingerprint = null;
    }
}
