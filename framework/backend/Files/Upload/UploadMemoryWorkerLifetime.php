<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\Core\Process;
use Hilos\Socket\Client\WorkerClient;

/** The exact process and connection paired at delivery, independent of a reusable worker index. */
final class UploadMemoryWorkerLifetime
{
    public bool $queuesDiscarded = false;

    public bool $processExited = false;

    /**
     * @param WorkerClient $worker Connection which received raw bytes
     * @param Process $process Process registered under that connection at delivery
     */
    public function __construct(public readonly WorkerClient $worker, public readonly Process $process)
    {
    }
}
