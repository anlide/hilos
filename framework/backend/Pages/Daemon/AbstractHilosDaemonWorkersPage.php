<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonNodeHeading;
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

    public const string NODE = 'node';
    public const string PROCESSES_REPORTED = 'processesReported';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_WORKERS,
    ];

    /** @var array<string, string> Last node-line fingerprint by node id */
    private static array $headingFingerprints = [];

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
        $resent = [];
        foreach (ClusterDaemonPictureMirror::picture()?->nodes() ?? [] as $node) {
            try {
                $fingerprint = json_encode([
                    self::headingOf($node->nodeId)->toArray(),
                    self::processesReportedOf($node->nodeId),
                ], JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new HilosException('Cannot fingerprint the daemon node heading', previous: $exception);
            }
            if ($fingerprint === (self::$headingFingerprints[$node->nodeId] ?? null)) {
                continue;
            }
            self::$headingFingerprints[$node->nodeId] = $fingerprint;
            foreach (Hilos::$sr?->getAcceptKeysForPage(
                HilosPageConstants::HILOS_DAEMON_WORKERS,
                HilosPageRouteParams::HILOS_DAEMON_NODE_ID,
                $node->nodeId,
            ) ?? [] as $acceptKey) {
                if (Hilos::$rt?->connectionsSource()?->get($acceptKey) !== null) {
                    Hilos::$browser?->resendPageWhole(HilosPageConstants::HILOS_DAEMON_WORKERS, $acceptKey);
                    $resent[$acceptKey] = true;
                }
            }
        }

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
        self::$headingFingerprints = [];
        self::$workersFingerprint = null;
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

        return new PagePayload(data: [
            self::NODE => self::headingOf($nodeId)->toArray(),
            self::PROCESSES_REPORTED => self::processesReportedOf($nodeId),
        ]);
    }

    /** @return array<string, WireField> Node and report status carry no personal data */
    protected function dataFields(): array
    {
        return [
            self::NODE => WireField::notPersonal(),
            self::PROCESSES_REPORTED => WireField::notPersonal(),
        ];
    }

    /**
     * @param string $nodeId Node to describe
     * @return DaemonNodeHeading Current node line
     * @throws EnvException When cluster mode cannot be read
     */
    private static function headingOf(string $nodeId): DaemonNodeHeading
    {
        return DaemonNodeHeading::of(
            ClusterDaemonPictureMirror::picture(),
            $nodeId,
            Hilos::$cluster?->isEnabled() === true,
        );
    }

    /**
     * @param string $nodeId Node whose worker report is read
     * @return bool Whether the node has reported its worker roster
     */
    private static function processesReportedOf(string $nodeId): bool
    {
        return ClusterDaemonPictureMirror::picture()?->node($nodeId)?->slot?->picture->processes !== null;
    }
}
