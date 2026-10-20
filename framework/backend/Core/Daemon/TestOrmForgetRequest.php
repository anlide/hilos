<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Socket\Client\WorkerClient;

/** One master's wait; the live socket reference never belongs in replicated runtime state. */
final readonly class TestOrmForgetRequest
{
    /**
     * @param string $correlationId Held console correlation
     * @param WorkerClient $worker Original worker connection
     * @param int $workerId Signed target id for the reply
     * @param float $deadline Last time an acknowledgement may complete this wait
     */
    public function __construct(
        public string $correlationId,
        public WorkerClient $worker,
        public int $workerId,
        public float $deadline,
    ) {
    }
}
