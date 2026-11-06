<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Hilos\Users\Agent\AbstractUserAgent;

/** Person agent bound to the chat project. */
final class UserAgent extends AbstractUserAgent
{
    /** Person writes and self-stop arrive in frames; the worker tracks the idle window. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;
}
