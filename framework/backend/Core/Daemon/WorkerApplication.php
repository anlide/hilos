<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Bootstrap\EntrypointPrelude;
use Hilos\Core\Daemon\Exception\InvalidWorkerIdException;
use Hilos\Hilos;
use Hilos\Utils\Helpers\ArgumentHelper;
use Hilos\Utils\Logger;
use Throwable;

/**
 * The worker process spine: the invariant startup sequence every worker.php shares,
 * lifted out of the four near-identical bootstraps into one framework entrypoint.
 *
 * A worker.php collapses to a single {@see run()} call naming its Hilos facade, its
 * worker manager class, and its persistence init. The spine runs the env prelude, parses
 * the --worker-id argument, constructs the manager, and enters its main loop — all under
 * the same catch structure the duplicated bootstraps carried: an invalid worker id exits
 * INVALID_ARGUMENT, any other failure exits ERROR. On a clean return the process exits
 * SUCCESS.
 */
final class WorkerApplication
{
    /**
     * Runs a worker from its thin entrypoint. Terminates the process; never returns.
     *
     * @param string $projectRoot Project root that holds .env
     * @param class-string<Hilos> $hilosClass Project Hilos facade whose catalogs drive env/cluster init
     * @param class-string<WorkerManager> $workerClass Worker manager to construct and run
     * @param callable(): void $persistenceInit Persistence bootstrap (e.g. Database::initialize) run after env is ready
     * @param list<string> $argv Command-line arguments carrying --worker-id
     * @return never
     */
    public static function run(
        string $projectRoot,
        string $hilosClass,
        string $workerClass,
        callable $persistenceInit,
        array $argv,
    ): void {
        $workerIndex = null;

        try {
            EntrypointPrelude::run($hilosClass, $projectRoot, $persistenceInit);

            $workerIndex = ArgumentHelper::getWorkerIndex($argv);

            $workerManager = new $workerClass($workerIndex, $argv);
            $workerManager->run();
        } catch (InvalidWorkerIdException $e) {
            Logger::error('Worker bootstrap failed: ' . $e->getMessage(), [
                ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
                ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
            ]);
            exit(ExitCode::INVALID_ARGUMENT);
        } catch (Throwable $e) {
            $prefix = $workerIndex !== null ? "Worker #{$workerIndex} failed: " : 'Worker bootstrap failed: ';
            Logger::error($prefix . $e->getMessage(), [
                ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
                ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
                ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
            ]);
            exit(ExitCode::ERROR);
        }

        exit(ExitCode::SUCCESS);
    }
}
