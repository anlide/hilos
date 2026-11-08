<?php

declare(strict_types=1);

namespace Hilos\Auth\Library;

use Hilos\Constants\CommandChannelWindows;
use Hilos\Users\DTO\UserAdminCreateSignalData;

/** Process-local waits with the lifetime of the holder's parked commands. */
final class AdminCreateWaits
{
    /** @var array<string, UserAdminCreateSignalData> Requests indexed by session token */
    private array $requests = [];

    /** @var array<string, float> Deadlines indexed by session token */
    private array $deadlines = [];

    /**
     * @param string $sessionToken Session to check
     * @return bool Whether this session has a parked creation command
     */
    public function isWaiting(string $sessionToken): bool
    {
        return isset($this->requests[$sessionToken]);
    }

    /**
     * @param UserAdminCreateSignalData $ask Request to park
     * @param float $now Current time in seconds
     */
    public function park(UserAdminCreateSignalData $ask, float $now): void
    {
        $this->requests[$ask->sessionToken] = $ask;
        $this->deadlines[$ask->sessionToken] = $now + CommandChannelWindows::AGENT_WAIT_SECONDS;
    }

    /**
     * Consumes only the wait for this exact command; a late answer cannot consume a newer wait.
     *
     * @param UserAdminCreateSignalData $ask Answered request
     * @return ?UserAdminCreateSignalData Consumed request, or null when this command no longer waits
     */
    public function settle(UserAdminCreateSignalData $ask): ?UserAdminCreateSignalData
    {
        $waiting = $this->requests[$ask->sessionToken] ?? null;
        if ($waiting === null || $waiting->correlationId !== $ask->correlationId) {
            return null;
        }
        unset($this->requests[$ask->sessionToken], $this->deadlines[$ask->sessionToken]);

        return $waiting;
    }

    /**
     * @param float $now Current time in seconds
     * @return list<UserAdminCreateSignalData> Expired requests, removed from the waits
     */
    public function expired(float $now): array
    {
        $expired = [];
        foreach ($this->deadlines as $token => $deadline) {
            if ($deadline <= $now) {
                $expired[] = $this->requests[$token];
                unset($this->requests[$token], $this->deadlines[$token]);
            }
        }

        return $expired;
    }
}
