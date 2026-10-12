<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon\DTO;

/** Primary database address and the last successful migration, without credentials. */
final readonly class HilosDaemonDatabaseData
{
    public const string host = 'host';
    public const string port = 'port';
    public const string name = 'name';
    public const string socket = 'socket';
    public const string migrationIndex = 'migrationIndex';

    /**
     * @param string $host Primary database host
     * @param int $port Primary database port
     * @param string $name Primary database name
     * @param ?string $socket Optional Unix socket
     * @param ?int $migrationIndex Last successful migration, zero for an empty history, null on read failure
     */
    public function __construct(
        public string $host,
        public int $port,
        public string $name,
        public ?string $socket,
        public ?int $migrationIndex,
    ) {
    }

    /** @return array{host: string, port: int, name: string, socket: ?string, migrationIndex: ?int} Safe browser data */
    public function toArray(): array
    {
        return [
            self::host => $this->host,
            self::port => $this->port,
            self::name => $this->name,
            self::socket => $this->socket,
            self::migrationIndex => $this->migrationIndex,
        ];
    }
}
