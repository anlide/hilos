<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

/** Process-private next offset of one socket's granted upload incarnation. @internal */
final class UploadMemoryInputPermission
{
    /**
     * @param string $requestId Pool upload incarnation
     * @param int $offsetBytes Next contiguous raw offset
     */
    public function __construct(public readonly string $requestId, public int $offsetBytes)
    {
    }
}
