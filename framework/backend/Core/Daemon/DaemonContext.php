<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * Immutable path context handed to a daemon's declarative composition hooks.
 *
 * Carries the two directories the entrypoint knows — the Bootstrap directory the
 * daemon.php lives in and the project root — and derives the paths the servers and
 * modules need from them, so a manager builds its servers without recomputing
 * `__DIR__`/`dirname()` arithmetic in every subclass.
 */
final readonly class DaemonContext
{
    /**
     * @param string $bootstrapDir Directory containing daemon.php / worker.php (the process bootstrap dir)
     * @param string $projectRoot Project root that holds .env and the frontend/ tree
     */
    public function __construct(
        public string $bootstrapDir,
        public string $projectRoot,
    ) {
    }

    /**
     * @return string Absolute path to the worker entry script the WorkerServer spawns
     */
    public function workerScript(): string
    {
        return $this->bootstrapDir . '/worker.php';
    }

    /**
     * Resolves the frontend dist directory: the FRONTEND_DIST_PATH override when set,
     * otherwise the project's default frontend/dist tree. Consumed by the frontend-html
     * and build-timestamp modules to locate the built assets.
     *
     * @return string Absolute path to the frontend dist directory
     * @throws EnvException When the dist-path override value cannot be read
     */
    public function frontendDistPath(): string
    {
        $override = Hilos::$env[EnvConstants::FRONTEND_DIST_PATH];

        return $override !== '' ? $override : $this->projectRoot . '/frontend/dist';
    }
}
