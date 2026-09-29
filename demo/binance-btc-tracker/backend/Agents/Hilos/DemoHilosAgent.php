<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Agents\Hilos;

use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;

/**
 * DemoHilosAgent - Concrete Hilos index agent for the binance-btc-tracker demo.
 *
 * Owns the framework Hilos pages this demo registers: the empty admin dashboard, the maintenance
 * and backup sections, and the four public footer pages.
 *
 * The admin grant is not answered here: the command belongs to the sessions library
 * (HIL-729), because what it writes ends in a person's open tabs being told.
 */
final class DemoHilosAgent extends AbstractHilosIndexAgent
{
}
