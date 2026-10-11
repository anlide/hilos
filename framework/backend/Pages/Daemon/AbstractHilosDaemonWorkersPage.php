<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DTO\DaemonMasterProcessRosterSignalData;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\DTO\HilosDaemonNodeSubscribeParams;
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
     * Re-sends whole node pages for changed lines, or just windows for changed worker rosters.
     *
     * @throws HilosException When the picture cannot be encoded or a table source refuses a read
     * @throws EnvException When cluster mode cannot be read
     * @throws InvalidArgumentException When the table-window signal cannot be named
     * @throws TableRowKeyMissingException When a windowed row has no key
     */
    public static function onPictureChanged(): void
    {
        $resent = static::resendChangedNodePages();

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
            if (isset($resent[$acceptKey])) {
                continue;
            }
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
        self::forgetNodePages();
        self::$workersFingerprint = null;
    }

    /**
     * @param string $nodeId Node to describe
     * @return array<string, mixed> Node line and first worker-report verdict
     * @throws EnvException When cluster mode cannot be read
     */
    protected static function nodeData(string $nodeId): array
    {
        return parent::nodeData($nodeId) + [self::PROCESSES_REPORTED => self::processesReportedOf($nodeId)];
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route params naming the node
     * @return PagePayload Node line and first worker-report verdict
     * @throws MissingPageRouteParamException When the node id is absent
     * @throws EnvException When cluster mode cannot be read
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $nodeId = HilosDaemonNodeSubscribeParams::fromPageRouteParams($params)->nodeId;

        return new PagePayload(data: static::nodeData($nodeId));
    }

    /** @return array<string, WireField> Node and report status carry no personal data */
    protected function dataFields(): array
    {
        return [
            self::NODE => WireField::notPersonal(),
            self::PROCESSES_REPORTED => WireField::notPersonal(),
        ];
    }
}
