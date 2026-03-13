<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Config;

/**
 * GuardianConfig - Guardian agent configuration constants.
 *
 * Tick intervals and limits for guardian ops and chat situation agents.
 */
final class GuardianConfig
{
    public const int OPS_TICK_INTERVAL_MS = 7_000;
    public const int CHAT_SITUATION_TICK_INTERVAL_MS = 10_000;
    public const int MAX_EVENTS_SCAN = 50;
}
