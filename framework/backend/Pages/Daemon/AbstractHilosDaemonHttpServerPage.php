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
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTable;

/**
 * Answers the node line and HTTP summary; routes arrive in the table window.
 * Changed node data and route mutations publish independently through the shared browser paths.
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

    public const string HTTP = 'http';

    private const string HOST = 'host';
    private const string PORT = 'port';
    private const string COUNTING_HOURS = 'countingHours';
    private const string REQUESTS = 'requests';
    private const string SERVER_ERRORS = 'serverErrors';
    private const string SLOW = 'slow';

    private const int SECONDS_PER_HOUR = 3600;
    private const int MAX_COUNTING_HOURS = 24;

    /**
     * @param ?ClusterDaemonPicture $before Immutable picture before this portion
     * @throws HilosException When node page data cannot be projected
     * @throws EnvException When cluster mode cannot be read
     */
    public static function onPictureChanged(?ClusterDaemonPicture $before): void
    {
        static::publishNodePicture($before, HilosDaemonHttpRoutesTable::TABLE);
    }

    /** Forgets the comparison state when the page agent stops. */
    public static function onPictureForgotten(): void
    {
        self::forgetNodePages();
    }

    /**
     * @param string $nodeId Node to describe
     * @return array<string, mixed> Node line and HTTP summary
     * @throws HilosException When the shared node projection rejects its source picture
     * @throws EnvException When cluster mode cannot be read for a node line
     */
    protected static function nodeData(string $nodeId): array
    {
        return parent::nodeData($nodeId) + [self::HTTP => self::httpSummaryOf($nodeId)];
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route params naming the node
     * @throws HilosException When node data cannot be encoded
     * @return PagePayload Node line and HTTP summary
     * @throws MissingPageRouteParamException When the node id is absent
     * @throws EnvException When cluster mode cannot be read for a node line
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $nodeId = HilosDaemonNodeSubscribeParams::fromPageRouteParams($params)->nodeId;

        return static::nodePagePayload($nodeId);
    }

    /** @return array<string, WireField> Node and HTTP summary carry no personal data */
    protected static function nodeDataFields(): array
    {
        return [
            self::NODE => WireField::notPersonal(),
            self::HTTP => WireField::notPersonal(),
        ];
    }

    /**
     * @param string $nodeId Node whose HTTP section is read
     * @return ?array{host: string, port: int, countingHours: int, requests: int, serverErrors: int, slow: int} Reported HTTP summary
     */
    private static function httpSummaryOf(string $nodeId): ?array
    {
        $slot = ClusterDaemonPictureMirror::picture()?->node($nodeId)?->slot;
        $http = $slot?->picture->http;
        if ($http === null) {
            return null;
        }

        $requests = $http->unrouted->requests;
        $serverErrors = $http->unrouted->serverErrors;
        $slow = $http->unrouted->slow;
        foreach ($http->routes as $route) {
            $requests += $route->tally->requests;
            $serverErrors += $route->tally->serverErrors;
            $slow += $route->tally->slow;
        }

        return [
            self::HOST => $http->host,
            self::PORT => $http->port,
            self::COUNTING_HOURS => min(self::MAX_COUNTING_HOURS,
                intdiv(max(0, $slot->picture->sampledAt - $http->countingSince), self::SECONDS_PER_HOUR)),
            self::REQUESTS => $requests,
            self::SERVER_ERRORS => $serverErrors,
            self::SLOW => $slow,
        ];
    }
}
