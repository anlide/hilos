<?php

declare(strict_types=1);

namespace Hilos\Database;

use Closure;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\SqlRuntime\GoneAwayException;
use Hilos\Database\Exception\SqlRuntime\LostConnectionException;
use Throwable;

/**
 * Holds the first connection failure shared by related reads in one synchronous delivery.
 */
final class ConnectionReadBoundary
{
    /** @var ?DatabaseException The connection failure, if one reached a reader */
    private ?DatabaseException $failure = null;

    /** @return ?DatabaseException Why new reads cannot start */
    public function failure(): ?DatabaseException
    {
        return $this->failure;
    }

    /** @throws DatabaseException When an earlier read lost the connection */
    public function assertAvailable(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    /**
     * @template T
     * @param Closure(): T $read Synchronous work that may read from the connection
     * @return T Result of the work
     * @throws Throwable Whatever the work raises
     */
    public function observe(Closure $read): mixed
    {
        try {
            return $read();
        } catch (Throwable $failure) {
            $cause = $failure;
            while ($cause !== null && $this->failure === null) {
                if ($cause instanceof DatabaseConnectionException || $cause instanceof GoneAwayException
                    || $cause instanceof LostConnectionException
                ) {
                    $this->failure = $cause;
                    break;
                }
                $cause = $cause->getPrevious();
            }
            throw $failure;
        }
    }
}
