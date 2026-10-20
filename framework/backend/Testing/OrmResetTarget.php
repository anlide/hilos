<?php

declare(strict_types=1);

namespace Hilos\Testing;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\OrmTestConstants;

/** One process whose ORM memory a test deliberately invalidates; remote addresses name a node's command channel. */
final class OrmResetTarget
{
    private const int MAX_PORT = 65535;

    /**
     * @param ?string $host Command host, null for the current process
     * @param ?int $port Command port, null for the current process
     * @param ?string $process Existing command target, null for the current process
     * @param ?int $workerId Signed worker id, null for daemon/current process
     */
    private function __construct(
        public readonly ?string $host,
        public readonly ?int $port,
        public readonly ?string $process,
        public readonly ?int $workerId,
    ) {
    }

    /** @return self The mounted context in the calling PHP process */
    public static function forCurrentProcess(): self
    {
        return new self(null, null, null, null);
    }

    /**
     * @param string $host Node's explicit command host
     * @param int $port Node's command port
     * @return self That node's daemon process
     * @throws InvalidArgumentException When the address is empty or the port is outside TCP's range
     */
    public static function forDaemon(string $host, int $port): self
    {
        self::validateAddress($host, $port);

        return new self($host, $port, OrmTestConstants::PROCESS_DAEMON, null);
    }

    /**
     * @param string $host Node's explicit command host
     * @param int $port Node's command port
     * @param int $workerId Nonzero signed worker id on that node
     * @return self That node's named worker
     * @throws InvalidArgumentException When the address, port or worker id is invalid
     */
    public static function forWorker(string $host, int $port, int $workerId): self
    {
        self::validateAddress($host, $port);
        if ($workerId === 0) {
            throw new InvalidArgumentException('An ORM reset worker id must be nonzero');
        }

        return new self($host, $port, OrmTestConstants::PROCESS_WORKER, $workerId);
    }

    /**
     * @param string $host Explicit command host
     * @param int $port TCP port
     * @throws InvalidArgumentException When the address is unusable
     */
    private static function validateAddress(string $host, int $port): void
    {
        if (trim($host) === '' || trim($host) !== $host || $port < 1 || $port > self::MAX_PORT) {
            throw new InvalidArgumentException('An ORM reset target requires a host and a valid TCP port');
        }
    }
}
