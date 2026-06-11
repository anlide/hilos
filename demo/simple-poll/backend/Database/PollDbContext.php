<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Database;

use Hilos\Database\Context\HilosDbContext;

/**
 * PollDbContext - Database context for the simple-poll demo.
 *
 * Inherits the Hilos-level settings collection from HilosDbContext; the demo
 * declares no own collections yet — the polls table arrives with the first
 * data-on-screen rewrite step.
 */
final class PollDbContext extends HilosDbContext
{
}
