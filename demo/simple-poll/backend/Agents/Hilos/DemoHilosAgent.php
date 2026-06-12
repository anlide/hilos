<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Agents\Hilos;

use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;

/**
 * DemoHilosAgent - Concrete Hilos index agent for the simple-poll demo.
 *
 * Owns the framework Hilos admin pages — here just the dashboard the application
 * shell's gear links to. It adds no demo truth sources: the poll demo exposes no
 * admin data, so the framework settings source the parent registers is all the
 * dashboard needs.
 */
final class DemoHilosAgent extends AbstractHilosIndexAgent
{
}
