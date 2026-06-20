<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Tables;

use Demo\SimpleTodo\Hilos;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * TodoTableContext - App-specific table context ($table layer) for simple-todo.
 *
 * Registers the framework settings table; accessed via Hilos::$table->settings.
 * The table is framework-owned and registered as-is — the demo never subclasses
 * or re-implements it.
 *
 * @property-read HilosSettingsTable $settings
 */
final class TodoTableContext extends TableContext
{
    public const string settings = HilosSettingsTable::TABLE;

    /**
     * Registers simple-todo table definitions from the project topology registry.
     */
    public function configure(): void
    {
        foreach (Hilos::TABLES as $tableName => $tableClass) {
            $this->register($tableName, new $tableClass());
        }
    }
}
