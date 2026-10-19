<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use WeakReference;

/** Source debt survives acceptance until the original raw object's references disappear. */
final class UploadMemoryPeerTransfer
{
    public bool $accepted = false;

    /**
     * @param string $receiptId Source pool portion
     * @param WeakReference<WebSocketFrameBinarySignalDTO> $frame Original logical frame, never retained strongly
     * @param int $outboundPosition End of this portion in the original transport buffer
     */
    public function __construct(
        public readonly string $receiptId,
        public readonly WeakReference $frame,
        public readonly int $outboundPosition,
    )
    {
    }
}
