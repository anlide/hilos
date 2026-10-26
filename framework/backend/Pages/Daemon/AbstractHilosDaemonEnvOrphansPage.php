<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\DTO\HilosDaemonNodeSubscribeParams;
use Hilos\Tables\Daemon\HilosDaemonEnvOrphansTable;

/** Node environment orphans page: heading and silence, without the key catalog. */
abstract class AbstractHilosDaemonEnvOrphansPage extends AbstractHilosDaemonEnvPage
{
    public const string PAGE = HilosPageConstants::HILOS_DAEMON_ENV_ORPHANS;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_ENV_ORPHANS,
    ];

    /**
     * Answers with the node heading and whether the node owner is silent.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route params naming the node
     * @return PagePayload Node heading and silence, without the environment catalog
     * @throws MissingPageRouteParamException When the node id is absent
     * @throws EnvException When cluster mode cannot be read
     * @throws HilosException When the declared node identity cannot be read
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        return $this->buildLocalEnvironmentPayload($acceptKey, $params, false);
    }

    /** @return array<string, WireField> Heading and silence; the catalog stays on the environment page */
    protected function dataFields(): array
    {
        return [
            self::NODE => WireField::notPersonal(),
            self::NODE_SILENT => WireField::notPersonal(),
        ];
    }

    /**
     * Pins the orphan window to this node before the subscription snapshot is built.
     *
     * A cold window arrives with an empty filter, and this table answers with no
     * rows until the filter names the node. A held window for this same node keeps
     * its place and size. A tab that reported its window is left to the snapshot.
     *
     * @param string $acceptKey Subscribing connection
     * @param HilosDaemonNodeSubscribeParams $address Validated node address
     */
    protected function onDaemonNodeSubscribeBeforeResponse(
        string $acceptKey,
        HilosDaemonNodeSubscribeParams $address,
    ): void {
        if (!isset(Hilos::$table->hilosDaemonEnvOrphans)) {
            return;
        }
        $table = Hilos::$table->hilosDaemonEnvOrphans;
        if (!$table instanceof HilosDaemonEnvOrphansTable) {
            return;
        }

        $held = Hilos::$sr?->getTableViewport($acceptKey, HilosDaemonEnvOrphansTable::TABLE);
        if ($held !== null && ($held->filter[HilosDaemonEnvOrphansTable::FILTER_NODE] ?? null) === $address->nodeId) {
            return;
        }

        Hilos::$sr?->setTableViewport($acceptKey, new TableViewportSubscription(
            tableKey: HilosDaemonEnvOrphansTable::TABLE,
            filter: [HilosDaemonEnvOrphansTable::FILTER_NODE => $address->nodeId],
            sort: $table->defaultSort(),
            limit: $table->windowSize(),
        ));
    }
}
