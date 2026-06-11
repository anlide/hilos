<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Database;

use Hilos\Database\Context\HilosDbContext;

/**
 * TodoDbContext - Database context for the simple-todo demo.
 *
 * Inherits the Hilos-level settings collection from HilosDbContext; the demo
 * declares no own collections yet — the todos table arrives with the first
 * data-on-screen rewrite step.
 */
final class TodoDbContext extends HilosDbContext
{
}
