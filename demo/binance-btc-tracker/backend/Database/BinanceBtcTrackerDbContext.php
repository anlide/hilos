<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Database;

use Hilos\Database\Context\HilosDbContext;

/**
 * BinanceBtcTrackerDbContext - Database context for the binance-btc-tracker demo.
 *
 * Adds nothing to the framework collections: people, their sessions and every table of
 * signing in are the framework's, and the demo keeps no rows of its own yet. A visitor
 * without an account stays nameless, so there is no guest table either.
 */
final class BinanceBtcTrackerDbContext extends HilosDbContext
{
}
