<?php

declare(strict_types=1);

namespace Demo\Polls\Agents\Hilos;

use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;

/**
 * DemoHilosAgent - Concrete Hilos index agent for the polls demo.
 *
 * Owns the framework Hilos admin pages (dashboard, settings, users).
 *
 * The admin grant seam is NOT here any more (HIL-729): the command moved to the
 * sessions library, because what it writes ends in a person's open tabs being told.
 */
final class DemoHilosAgent extends AbstractHilosIndexAgent
{
}
