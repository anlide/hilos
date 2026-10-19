<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\Core\Exception\LogicException;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;

/** WorkerServer's actual delivery and final process-departure facts for the master ledger. */
interface UploadMemoryDeliverySink extends UploadMemoryPoolSink
{
    /**
     * @param WebSocketFrameBinarySignalDTO $frame The same admitted raw object, through any agent-start hold
     * @param WorkerClient $worker Actual destination connection
     * @return ?string Receipt to carry in the existing optional IPC field, or null for unsigned input
     * @throws LogicException When a queued receipt cannot be assigned to this incarnation
     */
    public function assignUploadFrame(WebSocketFrameBinarySignalDTO $frame, WorkerClient $worker): ?string;

    /**
     * @param WorkerClient $worker Channel whose buffers are deleted and whose exact process has exited
     */
    public function discardUploadWorker(WorkerClient $worker): void;
}
