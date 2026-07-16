<?php

declare(strict_types=1);

namespace Hilos\Core\Http;

use Hilos\Constants\HttpConstants;
use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Socket\Server\WorkerServer;

/**
 * Invokable handler for the GET /status endpoint, shared by every daemon.
 *
 * Samples the live daemon status and worker counts on each request and returns the
 * DaemonStatusDTO as JSON. Registered as a route callable with the daemon's worker
 * server, which supplies the worker-count fields.
 */
final class StatusHandler
{
    /** @var DaemonStatus Daemon status sampled per request; its start time anchors uptime */
    private DaemonStatus $status;

    /**
     * @param WorkerServer $workerServer Worker server the status counts are read from
     */
    public function __construct(
        private readonly WorkerServer $workerServer,
    ) {
        $this->status = new DaemonStatus();
    }

    /**
     * Builds the JSON status response.
     *
     * @param array<string, mixed> $args Route handler arguments (request, params); unused
     * @return array{status: int, headers: array<string, string>, body: string} HTTP response payload
     */
    public function __invoke(array $args): array
    {
        $this->status->update();

        $dto = new DaemonStatusDTO(
            uptime: $this->status->getUptime(),
            memory: $this->status->memoryUsage,
            cpu: $this->status->cpuUsage,
            timestamp: time(),
            workersRegular: $this->workerServer->getRegularWorkersCount(),
            workersMonopolistic: $this->workerServer->getMonopolisticWorkersCount(),
            workersMaxRegular: $this->workerServer->getMaxRegularWorkers(),
        );

        return [
            HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_OK,
            HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON],
            HttpConstants::RESPONSE_KEY_BODY => $dto->toJson(),
        ];
    }
}
