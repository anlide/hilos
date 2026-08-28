<?php

declare(strict_types=1);

namespace Demo\Cluster\Database;

use Demo\Cluster\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Database\Database as BaseDatabase;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Database\Schema\Schema;
use Hilos\Environment\Exception\EnvException;
use Hilos\HilosException;

/**
 * Database - Database connection configuration for the cluster demo.
 *
 * Reads the primary connection from DB_* env. Every node of the stand names the
 * same schema, because the cluster model is one database per cluster and a node
 * that cannot see its neighbour's row cannot be asked about it (HIL-712). Nobody
 * races on the settings-table migration at first boot: a one-shot step of the
 * stand (the `cluster-migrate` service) applies it before any node starts.
 */
final class Database extends BaseDatabase
{
    /**
     * Initialize database connections from environment variables.
     *
     * @param bool $initHilos If true, initialize Hilos with storage. Set false when
     *                        migrations or DB bootstrap commands must run before Hilos is ready.
     * @param bool $retryConnection If true, retry connection on temporary errors (Docker startup).
     * @throws EnvException When required env variables are missing or invalid
     * @throws DatabaseConnectionException When connect or SET NAMES fails
     * @throws CantConnectToMysqlServerException When connect retries are exhausted
     * @throws DatabaseRuntimeException When SET NAMES query fails
     * @throws DatabaseException When schema initialization fails
     * @throws InvalidTopologyException When Hilos topology constants are inconsistent
     * @throws HilosException When Hilos facade initialization fails
     */
    public static function initialize(bool $initHilos = true, bool $retryConnection = false): void
    {
        self::configure(
            index: DatabaseConnectionDefaults::PRIMARY_INDEX,
            host: Hilos::$env[EnvConstants::DB_HOST],
            user: Hilos::$env[EnvConstants::DB_USERNAME],
            password: Hilos::$env[EnvConstants::DB_PASSWORD],
            database: Hilos::$env[EnvConstants::DB_DATABASE],
            port: Hilos::$env->int(EnvConstants::DB_PORT),
            charset: DatabaseConnectionDefaults::CHARSET,
        );

        self::connect(DatabaseConnectionDefaults::PRIMARY_INDEX, retryOnConnectionError: $retryConnection);

        self::sql(DatabaseConnectionDefaults::setNamesSql());

        Schema::initialize(DatabaseConnectionDefaults::PRIMARY_INDEX);

        if ($initHilos) {
            Hilos::init();
        }
    }
}
