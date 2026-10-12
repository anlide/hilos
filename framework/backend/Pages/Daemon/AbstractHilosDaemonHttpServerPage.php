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
use Hilos\DaemonSection\DTO\DaemonMasterHttpSignalData;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\DTO\HilosDaemonNodeSubscribeParams;
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTable;
use JsonException;

/**
 * Answers the node line and HTTP summary; routes arrive in the table window.
 * Changed node data resends the whole page, while route-only changes resend the window.
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

    private static ?string $httpFingerprint = null;

    /**
     * Re-sends changed node pages whole or changed HTTP route windows.
     *
     * @throws HilosException When the picture cannot be encoded or a table source refuses a read
     * @throws EnvException When cluster mode cannot be read for a node line
     * @throws InvalidArgumentException When the table-window signal cannot be named
     * @throws TableRowKeyMissingException When a windowed row has no key
     */
    public static function onPictureChanged(): void
    {
        $resent = static::resendChangedNodePages();

        $sections = [];
        foreach (ClusterDaemonPictureMirror::picture()?->nodes() ?? [] as $node) {
            $http = $node->slot?->picture->http;
            $sections[] = [$node->nodeId, $http === null ? null : DaemonMasterHttpSignalData::httpToArray($http)];
        }

        try {
            $fingerprint = json_encode($sections, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HilosException('Cannot fingerprint the daemon HTTP picture', previous: $exception);
        }
        if ($fingerprint === self::$httpFingerprint) {
            return;
        }
        self::$httpFingerprint = $fingerprint;

        foreach (ClusterDaemonPictureMirror::viewerKeys() as $acceptKey) {
            if (isset($resent[$acceptKey])) {
                continue;
            }
            $viewport = Hilos::$sr?->getTableViewport($acceptKey, HilosDaemonHttpRoutesTable::TABLE);
            if ($viewport === null) {
                continue;
            }
            Hilos::$browser?->sendTableWindow(HilosPageConstants::HILOS_DAEMON_HTTP_SERVER, $acceptKey, $viewport);
        }
    }

    /** Forgets the comparison state when the page agent stops. */
    public static function onPictureForgotten(): void
    {
        self::forgetNodePages();
        self::$httpFingerprint = null;
    }

    /**
     * @param string $nodeId Node to describe
     * @return array<string, mixed> Node line and HTTP summary
     * @throws EnvException When cluster mode cannot be read for a node line
     */
    protected static function nodeData(string $nodeId): array
    {
        return parent::nodeData($nodeId) + [self::HTTP => self::httpSummaryOf($nodeId)];
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route params naming the node
     * @return PagePayload Node line and HTTP summary
     * @throws MissingPageRouteParamException When the node id is absent
     * @throws EnvException When cluster mode cannot be read for a node line
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $nodeId = HilosDaemonNodeSubscribeParams::fromPageRouteParams($params)->nodeId;

        return new PagePayload(data: static::nodeData($nodeId));
    }

    /** @return array<string, WireField> Node and HTTP summary carry no personal data */
    protected function dataFields(): array
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
