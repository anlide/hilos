<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Agents\Hilos;

use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;

/**
 * DemoHilosAgent - Concrete Hilos index agent for the file-gallery-manager demo.
 *
 * Owns the framework Hilos pages this demo registers: the empty admin dashboard, the settings,
 * users and communications sections, and the public footer pages.
 *
 * The admin grant is not answered here: the command belongs to the sessions library
 * (HIL-729), because what it writes ends in a person's open tabs being told.
 */
final class DemoHilosAgent extends AbstractHilosIndexAgent
{
}
