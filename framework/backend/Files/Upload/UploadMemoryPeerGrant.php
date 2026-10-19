<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

/** Sender metadata for one request on one boot/link/recipient incarnation. */
final class UploadMemoryPeerGrant
{
    public ?int $bytes = null;

    public int $remainingBytes = 0;

    public bool $canceled = false;

    /**
     * @param string $id Link-generation window identity
     * @param int $requestedBytes Original demand, retained to validate duplicate answers
     */
    public function __construct(public readonly string $id, public readonly int $requestedBytes)
    {
    }
}
