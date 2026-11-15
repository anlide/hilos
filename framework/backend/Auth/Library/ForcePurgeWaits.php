<?php

declare(strict_types=1);

namespace Hilos\Auth\Library;

use Hilos\Constants\CommandChannelWindows;

/**
 * Process-local waits with the lifetime of the holder's parked force-purge commands.
 *
 * A wait lives exactly as long as the socket parked in this process; the operation it watches
 * is already in the database, so nothing here is shared runtime state (HIL-1953).
 */
final class ForcePurgeWaits
{
    /** @var array<string, int> Watched operation ids indexed by command correlation id */
    private array $operations = [];

    /** @var array<string, float> Deadlines indexed by command correlation id */
    private array $deadlines = [];

    /**
     * @param string $correlationId Command waiting for the operation's end
     * @param int $operationId Erasure the command watches
     * @param float $now Current time in seconds
     */
    public function park(string $correlationId, int $operationId, float $now): void
    {
        $this->operations[$correlationId] = $operationId;
        $this->deadlines[$correlationId] = $now + CommandChannelWindows::AGENT_WAIT_SECONDS;
    }

    /**
     * @param int $operationId Erasure to check
     * @return bool Whether any parked command watches this erasure, without reading the database
     */
    public function isWaitingOn(int $operationId): bool
    {
        return in_array($operationId, $this->operations, true);
    }

    /**
     * @param int $operationId Erasure whose watchers are answered now
     * @return list<string> Correlation ids of the commands that watched it, removed from the waits
     */
    public function settle(int $operationId): array
    {
        $settled = [];
        foreach ($this->operations as $correlationId => $watched) {
            if ($watched === $operationId) {
                $settled[] = (string)$correlationId;
                unset($this->operations[$correlationId], $this->deadlines[$correlationId]);
            }
        }

        return $settled;
    }

    /**
     * @param float $now Current time in seconds
     * @return list<array{string, int}> Correlation id and watched operation id of each expired command, removed from the waits
     */
    public function expired(float $now): array
    {
        $expired = [];
        foreach ($this->deadlines as $correlationId => $deadline) {
            if ($deadline <= $now) {
                $expired[] = [(string)$correlationId, $this->operations[$correlationId]];
                unset($this->operations[$correlationId], $this->deadlines[$correlationId]);
            }
        }

        return $expired;
    }
}
