<?php

declare(strict_types=1);

namespace Demo\Chat\CLI;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\DbTestResetCommand;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\ChangeLogPartitions;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use mysqli_sql_exception;

/**
 * Keeps chat's paired test database in step with the base test reset.
 */
final class ChatChangeLogDbTestResetCommand extends DbTestResetCommand
{
    /**
     * Drops only chat's derived journal database before the base resets the primary one.
     *
     * @param array<string, mixed> $options Parsed options
     * @param list<string> $args Positional arguments
     * @return int Exit code from the base reset
     * @throws EnvException When database environment values or the claim node name cannot be read
     * @throws DatabaseException When the journal reset, base reset, or partition preparation fails
     */
    protected function run(array $options, array $args): int
    {
        $host = Hilos::$env[EnvConstants::DB_HOST]->string();
        $port = Hilos::$env[EnvConstants::DB_PORT]->int();
        $user = Hilos::$env[EnvConstants::DB_USERNAME]->string();
        $password = Hilos::$env[EnvConstants::DB_PASSWORD]->string();
        $database = ChangeLogDatabase::name(Hilos::$env[EnvConstants::DB_DATABASE]->string());
        $identifier = ChangeLogDatabase::identifier($database);

        try {
            $connection = mysqli_connect($host, $user, $password, '', $port);
            try {
                mysqli_query($connection, 'DROP DATABASE IF EXISTS ' . $identifier);
                mysqli_query(
                    $connection,
                    'CREATE DATABASE ' . $identifier . ' ' . DatabaseConnectionDefaults::createDatabaseCharsetClause(),
                );
            } finally {
                mysqli_close($connection);
            }
        } catch (mysqli_sql_exception $failure) {
            throw new DatabaseException("Cannot reset change log database {$database}: " . $failure->getMessage(), 0, $failure);
        }

        $result = parent::run($options, $args);
        if ($result !== ExitCode::SUCCESS) {
            return $result;
        }

        Database::configure(
            index: ChangeLogDatabase::CONNECTION_INDEX,
            host: $host,
            user: $user,
            password: $password,
            database: $database,
            port: $port,
            charset: DatabaseConnectionDefaults::CHARSET,
        );
        Database::connect(ChangeLogDatabase::CONNECTION_INDEX);
        ChangeLogPartitions::ensure();

        return ExitCode::SUCCESS;
    }
}
