<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Agents\Hilos;

use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;

/**
 * DemoHilosAgent - Concrete Hilos index agent for the hilos-website demo.
 *
 * Owns the framework Hilos pages of this demo that neither the site's own agent nor Analytics
 * serves: the empty admin dashboard, Settings, the delivery journal, the four public footer
 * pages, the revision histories of Terms and Privacy, and the dependency list of License.
 *
 * The admin grant is not answered here: the command belongs to the sessions library
 * (HIL-729), because what it writes ends in a person's open tabs being told.
 */
final class DemoHilosAgent extends AbstractHilosIndexAgent
{
}
