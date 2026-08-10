<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Database\Database;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Schema\TableInfo;
use Hilos\Core\CLI\Exception\CommandException;

/**
 * DbSchemaStatusCommand - Display database schema structure status.
 *
 * Shows information about database tables, columns, indexes, and foreign keys
 * that were loaded into static variables during schema initialization.
 */
class DbSchemaStatusCommand implements CommandInterface
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (e.g. db:schema:status)
     */
    public function getName(): string
    {
        return CliCommands::DB_SCHEMA_STATUS;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Display database schema structure status';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: db:schema:status

Description:
  Display database schema structure information that was loaded into static variables.
  Shows tables, columns, indexes, and foreign keys for the specified connection.

Usage:
  php cli.php db:schema:status [options]

Options:
  --db-index=<N>     Database connection index (default: 0)
  --table=<name>     Show details for specific table only
  --verbose          Show detailed column and index information

Examples:
  php cli.php db:schema:status
  php cli.php db:schema:status --db-index=0
  php cli.php db:schema:status --table=user
  php cli.php db:schema:status --verbose
HELP;
    }

    /**
     * Executes schema status command and outputs structure to console.
     *
     * @param array<string, mixed> $options Parsed options (db-index, table, verbose)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws CommandException If database connection is not established
     */
    public function execute(array $options, array $args): int
    {
        echo "\n=== Database Schema Status ===\n\n";

        // Parse arguments from options
        $dbIndex = isset($options['db-index']) ? (int)$options['db-index'] : 0;
        $tableName = $options['table'] ?? null;
        $verbose = isset($options['verbose']);

        // Set database connection index if specified
        Database::useConnection($dbIndex);

        // Check if connected
        if (!Database::isConnected($dbIndex)) {
            throw new CommandException("Database connection {$dbIndex} is not established."
                . ' Please ensure database connection is initialized before running this command.');
        }

        // Initialize schema if not already initialized
        if (!Schema::isInitialized($dbIndex)) {
            echo "Initializing schema for connection {$dbIndex}...\n";
            Schema::initialize($dbIndex);
            echo "Schema initialized successfully.\n\n";
        }

        // Get statistics
        $stats = Schema::getStatistics($dbIndex);

        // Display general statistics
        echo "Connection Index: {$stats['connection_index']}\n";
        echo "Initialized: " . ($stats['initialized'] ? 'Yes' : 'No') . "\n";
        echo "Tables: {$stats['tables_count']}\n";
        echo "Total Columns: {$stats['total_columns']}\n";
        echo "Total Indexes: {$stats['total_indexes']}\n";
        echo "Total Foreign Keys: {$stats['total_foreign_keys']}\n\n";

        if ($stats['tables_count'] === 0) {
            echo "No tables found in database.\n\n";
            return ExitCode::SUCCESS;
        }

        // Display table information
        if ($tableName !== null) {
            // Show specific table
            $table = Schema::getTable($tableName, $dbIndex);
            if ($table === null) {
                echo "Error: Table '{$tableName}' not found.\n\n";
                return ExitCode::ERROR;
            }
            $this->displayTableDetails($table, $verbose);
        } else {
            // Show all tables
            echo "Tables:\n";
            foreach ($stats['table_names'] as $name) {
                $table = Schema::getTable($name, $dbIndex);
                if ($table !== null) {
                    $this->displayTableSummary($table);
                    if ($verbose) {
                        $this->displayTableDetails($table, true);
                        echo "\n";
                    }
                }
            }
        }

        echo "\n";
        return ExitCode::SUCCESS;
    }

    /**
     * Displays table summary (one line) to console.
     *
     * @param TableInfo $table Table info to display
     */
    private function displayTableSummary(TableInfo $table): void
    {
        $columnsCount = count($table->columns);
        $indexesCount = count($table->indexes);
        $foreignKeysCount = count($table->foreignKeys);
        $primaryKeys = implode(', ', $table->primaryKeys);

        printf(
            "  %-30s %3d columns, %2d indexes, %2d foreign keys, PK: [%s]\n",
            $table->name,
            $columnsCount,
            $indexesCount,
            $foreignKeysCount,
            $primaryKeys ?: 'none',
        );
    }

    /**
     * Displays detailed table information (columns, indexes, foreign keys).
     *
     * @param TableInfo $table Table info to display
     * @param bool $verbose Whether to show verbose column details
     */
    private function displayTableDetails(TableInfo $table, bool $verbose): void
    {
        echo "\nTable: {$table->name}\n";
        echo str_repeat('-', 80) . "\n";

        // Primary keys
        if (!empty($table->primaryKeys)) {
            echo "Primary Keys: " . implode(', ', $table->primaryKeys) . "\n";
        }

        // Columns
        echo "\nColumns:\n";
        foreach ($table->columns as $column) {
            $nullable = $column->nullable ? 'NULL' : 'NOT NULL';
            // external-boundary: the neutral element of the printf row below — no default prints nothing
            $default = $column->default !== null ? " DEFAULT " . (is_string($column->default) ? "'{$column->default}'" : $column->default) : '';
            // external-boundary: the neutral element of the printf row below
            $extra = $column->extra ? " {$column->extra}" : '';
            // external-boundary: the neutral element of the printf row below
            $primary = $column->isPrimary ? ' [PK]' : '';
            // external-boundary: the neutral element of the printf row below
            $unique = $column->isUnique ? ' [UNIQUE]' : '';

            printf(
                "  %-25s %-15s %-10s %-10s%s%s%s\n",
                $column->name,
                $column->mysqlType,
                $column->phpType,
                $nullable,
                $default,
                $primary,
                $unique,
            );
        }

        // Indexes
        if (!empty($table->indexes)) {
            echo "\nIndexes:\n";
            foreach ($table->indexes as $index) {
                // external-boundary: the neutral element of the line below — a plain index has no qualifier
                $unique = $index->unique ? 'UNIQUE ' : '';
                $columns = implode(', ', $index->columns);
                echo "  {$unique}{$index->name}: ({$columns})\n";
            }
        }

        // Foreign keys
        if (!empty($table->foreignKeys)) {
            echo "\nForeign Keys:\n";
            foreach ($table->foreignKeys as $column => $foreignTable) {
                echo "  {$column} -> {$foreignTable}\n";
            }
        }
    }
}

