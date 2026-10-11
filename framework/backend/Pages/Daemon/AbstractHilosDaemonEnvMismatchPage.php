<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\ClusterEnvironmentKeyComparison;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Tables\Daemon\HilosDaemonEnvDivergedTable;
use Hilos\Tables\Daemon\HilosDaemonEnvSourcesDifferTable;
use JsonException;

/** Cluster environment comparison page; its two windows come from the page agent's picture mirror. */
abstract class AbstractHilosDaemonEnvMismatchPage extends AbstractHilosDaemonSectionPage
{
    public const string PAGE = HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_ENV_MISMATCH,
    ];

    private static ?string $comparisonFingerprint = null;

    /**
     * Re-sends open windows only when the comparison's nodes, keys or cells change.
     *
     * @throws HilosException When the comparison cannot be encoded or a window cannot be served
     * @throws InvalidArgumentException When the table-window signal cannot be named
     * @throws TableRowKeyMissingException When a windowed row has no key
     */
    public static function onPictureChanged(): void
    {
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
            foreach ([HilosDaemonEnvDivergedTable::TABLE, HilosDaemonEnvSourcesDifferTable::TABLE] as $table) {
                $viewport = Hilos::$sr?->getTableViewport($acceptKey, $table);
                if ($viewport !== null) {
                    Hilos::$browser?->sendTableWindow(HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH, $acceptKey, $viewport);
                }
            }
        }
    }

    /** Forgets the comparison when the page agent stops. */
    public static function onPictureForgotten(): void
    {
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
     * @param string $acceptKey Subscribing connection, unused
     * @param PageRouteParams $params Route parameters, unused
     * @throws PageResourceNotFoundException When cluster mode is disabled
     * @throws EnvException When the cluster mode flag cannot be read
     */
    final protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        if (Hilos::$cluster?->isEnabled() !== true) {
            throw new PageResourceNotFoundException('Environment mismatch is available only in a cluster');
        }
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
}
