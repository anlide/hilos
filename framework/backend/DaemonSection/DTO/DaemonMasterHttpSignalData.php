<?php

declare(strict_types=1);

namespace Hilos\DaemonSection\DTO;

use Hilos\API\Router\HttpRouteTally;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DaemonSection\DaemonHttpPicture;
use Hilos\DaemonSection\DaemonHttpRoutePicture;

/** HTTP listener and route counts sent by the master to its own node agent. */
final class DaemonMasterHttpSignalData extends BaseDTO implements SignalDataInterface
{
    public const string nodeId = 'nodeId';
    public const string host = 'host';
    public const string port = 'port';
    public const string countingSince = 'countingSince';
    public const string routes = 'routes';
    public const string method = 'method';
    public const string path = 'path';
    public const string agentType = 'agentType';
    public const string requests = 'requests';
    public const string serverErrors = 'serverErrors';
    public const string slow = 'slow';
    public const string slowestMs = 'slowestMs';
    public const string unrouted = 'unrouted';

    /**
     * @param string $nodeId Non-empty source node id
     * @param DaemonHttpPicture $http Complete HTTP part
     * @throws InvalidFormatException When the node id is empty
     */
    public function __construct(public readonly string $nodeId, public readonly DaemonHttpPicture $http)
    {
        if ($nodeId === '') {
            throw new InvalidFormatException('Daemon master HTTP frame carries an empty node id');
        }
    }

    /** @return array<string, mixed> Wire payload */
    public function toArray(): array
    {
        return [self::nodeId => $this->nodeId] + self::httpToArray($this->http);
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Parsed master HTTP frame
     * @throws InvalidFormatException When a required field is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, self::nodeId), self::httpFromArray($data));
    }

    /**
     * @param DaemonHttpPicture $http HTTP section of a whole node picture
     * @return array<string, mixed> Shared wire shape without node id
     */
    public static function httpToArray(DaemonHttpPicture $http): array
    {
        return [
            self::host => $http->host,
            self::port => $http->port,
            self::countingSince => $http->countingSince,
            self::routes => array_map(static fn (DaemonHttpRoutePicture $route): array => [
                self::method => $route->method,
                self::path => $route->path,
                self::agentType => $route->agentType,
            ] + self::tallyToArray($route->tally), $http->routes),
            self::unrouted => self::tallyToArray($http->unrouted),
        ];
    }

    /**
     * @param array<string, mixed> $data Shared wire shape without node id
     * @return DaemonHttpPicture Parsed HTTP section
     * @throws InvalidFormatException When a required field is absent or invalid
     */
    public static function httpFromArray(array $data): DaemonHttpPicture
    {
        $rows = self::requireArray($data, self::routes);
        if (!array_is_list($rows)) {
            throw new InvalidFormatException('Daemon HTTP routes must be a list');
        }
        $routes = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidFormatException('Daemon HTTP route must be an object');
            }
            $routes[] = new DaemonHttpRoutePicture(
                self::requireString($row, self::method),
                self::requireString($row, self::path),
                self::requiredNullableString($row, self::agentType),
                self::tallyFromArray($row),
            );
        }

        return new DaemonHttpPicture(
            self::requireString($data, self::host),
            self::requireInt($data, self::port),
            self::requireInt($data, self::countingSince),
            $routes,
            self::tallyFromArray(self::requireArray($data, self::unrouted)),
        );
    }

    /**
     * @param HttpRouteTally $tally One route's counts
     * @return array<string, mixed> Shared tally shape
     */
    private static function tallyToArray(HttpRouteTally $tally): array
    {
        return [
            self::requests => $tally->requests,
            self::serverErrors => $tally->serverErrors,
            self::slow => $tally->slow,
            self::slowestMs => $tally->slowestMs,
        ];
    }

    /**
     * @param array<string, mixed> $data One route's counts
     * @return HttpRouteTally Validated tally
     * @throws InvalidFormatException When a count or duration is absent or invalid
     */
    private static function tallyFromArray(array $data): HttpRouteTally
    {
        if (!array_key_exists(self::slowestMs, $data)) {
            throw new InvalidFormatException('Daemon HTTP tally has no slowest duration');
        }
        return new HttpRouteTally(
            self::requireInt($data, self::requests),
            self::requireInt($data, self::serverErrors),
            self::requireInt($data, self::slow),
            self::optionalInt($data, self::slowestMs),
        );
    }

    /**
     * @param array<string, mixed> $data One route's wire object
     * @param string $key Required nullable string key
     * @return ?string Agent type or explicit null
     * @throws InvalidFormatException When the key is absent or mistyped
     */
    private static function requiredNullableString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidFormatException('Daemon HTTP route has no agent type');
        }
        return self::optionalString($data, $key);
    }
}
