<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Tables;

use Demo\SimplePoll\Hilos;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * PollTableContext - App-specific table context ($table layer) for simple-poll.
 *
 * Registers the framework settings table; accessed via Hilos::$table->settings.
 * The table is framework-owned and registered as-is — the demo never subclasses
 * or re-implements it.
 *
 * @property-read HilosSettingsTable $settings
 */
final class PollTableContext extends TableContext
{
    public const string settings = HilosSettingsTable::TABLE;

    /**
     * Registers simple-poll table definitions from the project topology registry.
     */
    public function configure(): void
    {
        foreach (Hilos::TABLES as $tableName => $tableClass) {
            $this->register($tableName, new $tableClass());
        }
    }
}
