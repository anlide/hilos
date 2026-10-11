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
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\DTO\HilosDaemonNodeSubscribeParams;
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
     * Re-sends changed node pages whole or changed agent windows.
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
            if (isset($resent[$acceptKey])) {
                continue;
            }
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
        self::forgetNodePages();
        self::$agentsFingerprint = null;
    }

    /**
     * @param string $nodeId Node to describe
     * @return array<string, mixed> Node line and first agent-report verdict
     * @throws EnvException When cluster mode cannot be read
     */
    protected static function nodeData(string $nodeId): array
    {
        return parent::nodeData($nodeId) + [self::PROCESSES_REPORTED => self::processesReportedOf($nodeId)];
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route params naming the node
     * @return PagePayload Node line and first agent-report verdict
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
