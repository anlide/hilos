<?php

declare(strict_types=1);

namespace Hilos\Core\Http;

use Hilos\Constants\HttpConstants;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\DaemonStatusSource;

/**
 * Invokable handler for the GET /status endpoint, registered on every daemon.
 *
 * Asks the master for its last completed measurement and current counters as JSON.
 * Registered by {@see DaemonManager::boot()} with the manager itself as the source, so the
 * endpoint and the `daemon:status` command read the one status object the master holds - two
 * samplers would disagree about the same daemon.
 */
final class StatusHandler
{
    /**
     * @param DaemonStatusSource $source Master seam the last measurement is read from
     */
    public function __construct(
        private readonly DaemonStatusSource $source,
    ) {
    }

    /**
     * Builds the JSON status response.
     *
     * @param array<string, mixed> $args Route handler arguments (request, params); unused
     * @return array{status: int, headers: array<string, string>, body: string} HTTP response payload
     */
    public function __invoke(array $args): array
    {
        return [
            HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_OK,
            HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON],
            HttpConstants::RESPONSE_KEY_BODY => $this->source->daemonStatusSnapshot()->toJson(),
        ];
    }
}
