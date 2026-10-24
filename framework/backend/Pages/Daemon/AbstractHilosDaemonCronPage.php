<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\DTO\HilosDaemonNodeSubscribeParams;
use Hilos\Tables\Daemon\HilosDaemonCronTable;

/**
 * Answers the node line and schedule-report status; rules arrive in the table window.
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

    public const string CRON_REPORTED = 'cronReported';

    /**
     * @param ?ClusterDaemonPicture $before Immutable picture before this portion
     * @throws HilosException When node page data cannot be projected
     * @throws EnvException When cluster mode cannot be read
     */
    public static function onPictureChanged(?ClusterDaemonPicture $before): void
    {
        static::publishNodePicture($before, HilosDaemonCronTable::TABLE);
    }

    /** Forgets the comparison state when the page agent stops. */
    public static function onPictureForgotten(): void
    {
        self::forgetNodePages();
    }

    /**
     * @param string $nodeId Node to describe
     * @return array<string, mixed> Node line and first schedule-report verdict
     * @throws HilosException When the shared node projection rejects its source picture
     * @throws EnvException When cluster mode cannot be read for a node line
     */
    protected static function nodeData(string $nodeId): array
    {
        return parent::nodeData($nodeId) + [self::CRON_REPORTED => self::cronReportedOf($nodeId)];
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route params naming the node
     * @throws HilosException When node data cannot be encoded
     * @return PagePayload Node line and first schedule-report verdict
     * @throws MissingPageRouteParamException When the node id is absent
     * @throws EnvException When cluster mode cannot be read for a node line
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $nodeId = HilosDaemonNodeSubscribeParams::fromPageRouteParams($params)->nodeId;

        return static::nodePagePayload($nodeId);
    }

    /** @return array<string, WireField> Node and report status carry no personal data */
    protected static function nodeDataFields(): array
    {
        return [
            self::NODE => WireField::notPersonal(),
            self::CRON_REPORTED => WireField::notPersonal(),
        ];
    }

    /**
     * @param string $nodeId Node whose schedule report is read
     * @return bool Whether the node has reported its schedule
     */
    private static function cronReportedOf(string $nodeId): bool
    {
        return ClusterDaemonPictureMirror::picture()?->node($nodeId)?->slot?->picture->cron !== null;
    }
}
