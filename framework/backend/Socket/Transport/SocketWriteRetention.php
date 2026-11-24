<?php

declare(strict_types=1);

namespace Hilos\Socket\Transport;

/** Optional transport capability for a write accepted before its byte count can be acknowledged. */
interface SocketWriteRetention
{
    /** @return int Prefix that the client still owes unchanged on the next write call */
    public function pendingWriteBytes(): int;
}
