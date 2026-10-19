<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Closure;
use Hilos\Core\Router\DTO\SignalDTO;

/** Explicit fixture metadata; no browser declaration, raw bytes or independent ledger. */
final class UploadMemoryTestDelivery
{
    public ?string $requestId = null;

    public int $reservedBytes = 0;

    /**
     * @param string $id Process-generation delivery identity, also a valid upload signature id
     * @param string $targetNodeId Exact node hosting the already live test agent
     * @param string $agentType Resolved test agent type
     * @param ?string $agentIndex Resolved test agent instance
     * @param int $bytes Raw fixture length
     * @param Closure(self, SignalDTO): void $deliver Same production delivery entry used by the daemon
     */
    public function __construct(
        public readonly string $id,
        public readonly string $targetNodeId,
        public readonly string $agentType,
        public readonly ?string $agentIndex,
        public readonly int $bytes,
        public readonly Closure $deliver,
    ) {
    }
}
