<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\AdminViewMode\WireField;
use Hilos\Cluster\Exception\ClusterException;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\ClusterEnvironmentKeyComparison;
use Hilos\DaemonSection\DaemonEnvMismatchSummary;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Tables\Daemon\HilosDaemonEnvDivergedTable;
use Hilos\Tables\Daemon\HilosDaemonEnvSourcesDifferTable;
use JsonException;

/** Cluster environment comparison page: own part names nodes and verdict counters; answers on standalone rather than refusing. */
abstract class AbstractHilosDaemonEnvMismatchPage extends AbstractHilosDaemonSectionPage
{
    public const string PAGE = HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH;
    public const string ENV_MISMATCH = 'envMismatch';

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_ENV_MISMATCH,
    ];

    private static ?string $summaryFingerprint = null;
    private static ?string $comparisonFingerprint = null;

    /**
     * Re-sends whole pages when the summary changes, or open windows only when the comparison's nodes, keys or cells change.
     *
     * @throws HilosException When the comparison cannot be encoded or a window cannot be served
     * @throws EnvException When cluster mode or node id cannot be read from the environment
     * @throws ClusterException When cluster configuration or node identity is invalid
     * @throws InvalidArgumentException When the table-window signal cannot be named or a cell is invalid
     * @throws TableRowKeyMissingException When a windowed row has no key
     */
    public static function onPictureChanged(): void
    {
        $resent = self::resendChangedPage();

        $comparison = ClusterDaemonPictureMirror::picture()?->environmentComparison();
        $sections = $comparison === null ? [null] : [
            $comparison->nodes,
            self::fingerprintKeys($comparison->diverged),
            self::fingerprintKeys($comparison->sourcesDiffer),
        ];

        try {
            $fingerprint = json_encode($sections, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HilosException('Cannot fingerprint the environment comparison', previous: $exception);
        }
        if ($fingerprint === self::$comparisonFingerprint) {
            return;
        }
        self::$comparisonFingerprint = $fingerprint;

        foreach (ClusterDaemonPictureMirror::viewerKeys() as $acceptKey) {
            if (isset($resent[$acceptKey])) {
                continue;
            }
            foreach ([HilosDaemonEnvDivergedTable::TABLE, HilosDaemonEnvSourcesDifferTable::TABLE] as $table) {
                $viewport = Hilos::$sr?->getTableViewport($acceptKey, $table);
                if ($viewport !== null) {
                    Hilos::$browser?->sendTableWindow(HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH, $acceptKey, $viewport);
                }
            }
        }
    }

    /** Forgets the comparison and summary when the page agent stops. */
    public static function onPictureForgotten(): void
    {
        self::$summaryFingerprint = null;
        self::$comparisonFingerprint = null;
    }

    /**
     * @param list<ClusterEnvironmentKeyComparison> $keys One comparison bucket
     * @return list<array{string, list<array{string, string, ?string, ?string, ?string}>}> Keys and value-free cells
     */
    private static function fingerprintKeys(array $keys): array
    {
        $rows = [];
        foreach ($keys as $key) {
            $cells = [];
            foreach ($key->cells as $cell) {
                $cells[] = [
                    $cell->nodeId,
                    $cell->state->value,
                    $cell->fingerprint?->type,
                    $cell->fingerprint?->source->value,
                    $cell->fingerprint?->digest,
                ];
            }
            $rows[] = [$key->key, $cells];
        }

        return $rows;
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Merged route parameters
     * @throws HilosException When the page answer fails
     */
    final public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    /**
     * @param string $acceptKey Subscribing connection, unused
     * @param PageRouteParams $params Route parameters, unused
     * @return PagePayload Own page data with cluster mode, serving node and verdict counters
     * @throws EnvException When cluster mode or node id cannot be read from the environment
     * @throws ClusterException When cluster configuration or node identity is invalid
     * @throws InvalidArgumentException When environment comparison generates an invalid cell
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        return new PagePayload(data: [self::ENV_MISMATCH => self::summary()->toArray()]);
    }

    /** @return array<string, WireField> Mismatch summary carries no personal data */
    protected function dataFields(): array
    {
        return [
            self::ENV_MISMATCH => WireField::notPersonal(),
        ];
    }

    /**
     * @return DaemonEnvMismatchSummary Summary of cluster mode, serving node and verdict counters
     * @throws EnvException When cluster mode or node id cannot be read from the environment
     * @throws ClusterException When cluster configuration or node identity is invalid
     * @throws InvalidArgumentException When environment comparison generates an invalid cell
     */
    private static function summary(): DaemonEnvMismatchSummary
    {
        return DaemonEnvMismatchSummary::of(
            Hilos::$cluster?->isEnabled() === true,
            Hilos::$cluster?->localNodeId() ?? HilosClusterNode::STANDALONE_NODE_ID,
            ClusterDaemonPictureMirror::picture(),
        );
    }

    /**
     * @return array<string, true> Connections re-answered with a whole page
     * @throws HilosException When the page summary cannot be encoded
     * @throws EnvException When cluster mode or node id cannot be read from the environment
     * @throws ClusterException When cluster configuration or node identity is invalid
     * @throws InvalidArgumentException When environment comparison generates an invalid cell
     */
    private static function resendChangedPage(): array
    {
        try {
            $fingerprint = json_encode(self::summary()->toArray(), JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HilosException('Cannot fingerprint the environment mismatch page', previous: $exception);
        }
        if ($fingerprint === self::$summaryFingerprint) {
            return [];
        }
        self::$summaryFingerprint = $fingerprint;

        $resent = [];
        foreach (Hilos::$sr?->getAcceptKeysForPage(self::PAGE) ?? [] as $acceptKey) {
            if (Hilos::$rt?->connectionsSource()?->get($acceptKey) !== null) {
                Hilos::$browser?->resendPageWhole(self::PAGE, $acceptKey);
                $resent[$acceptKey] = true;
            }
        }

        return $resent;
    }
}
