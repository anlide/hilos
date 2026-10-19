<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

/** Receiver metadata; unused grant and received worker debt have separate lifetimes. */
final class UploadMemoryPeerWindow
{
    public ?UploadMemoryReservation $reservation = null;

    public int $receivedBytes = 0;

    public bool $canceled = false;

    /** @param int $requestedBytes Original request, unchanged by duplicate messages */
    public function __construct(public readonly int $requestedBytes)
    {
    }
}
