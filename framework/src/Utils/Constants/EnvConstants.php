<?php

declare(strict_types=1);

namespace Hilos\Utils\Constants;

/**
 * EnvConstants - Environment variable name constants
 *
 * Defines all environment variable names used by the framework.
 * Centralized environment variable name management prevents typos and ensures consistency.
 */
class EnvConstants
{
    /** @var string Daemon host for CLI connection */
    public const string HILOS_DAEMON_HOST = 'HILOS_DAEMON_HOST';

    /** @var string HTTP status server host */
    public const string HTTP_STATUS_HOST = 'HTTP_STATUS_HOST';

    /** @var string HTTP status server port */
    public const string HTTP_STATUS_PORT = 'HTTP_STATUS_PORT';

    /** @var string Worker communication host */
    public const string WORKER_COMM_HOST = 'WORKER_COMM_HOST';

    /** @var string Worker communication port */
    public const string WORKER_COMM_PORT = 'WORKER_COMM_PORT';

    /** @var string Database host */
    public const string DB_HOST = 'DB_HOST';

    /** @var string Database port */
    public const string DB_PORT = 'DB_PORT';

    /** @var string Database name */
    public const string DB_NAME = 'DB_NAME';

    /** @var string Database user */
    public const string DB_USER = 'DB_USER';

    /** @var string Database password */
    public const string DB_PASSWORD = 'DB_PASSWORD';

    /** @var string Database root password */
    public const string DB_ROOT_PASSWORD = 'DB_ROOT_PASSWORD';

    /** @var string Daemon log file path */
    public const string DAEMON_LOG_FILE = 'DAEMON_LOG_FILE';

    /** @var string Daemon error log file path */
    public const string DAEMON_ERROR_LOG_FILE = 'DAEMON_ERROR_LOG_FILE';

    /** @var string Docker network subnet */
    public const string DOCKER_NETWORK_SUBNET = 'DOCKER_NETWORK_SUBNET';

    /** @var string Docker daemon IP address */
    public const string DOCKER_DAEMON_IP = 'DOCKER_DAEMON_IP';
}

