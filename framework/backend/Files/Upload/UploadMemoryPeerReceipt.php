<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\Files\Upload\DTO\UploadMemoryReservationData;

/** Duplicate validation retained only while the receiver still owes these raw bytes. */
final readonly class UploadMemoryPeerReceipt
{
    /**
     * @param UploadMemoryReservationData $envelope Original sender identity and declared raw size
     * @param string $receiptId Receiver's independent pool receipt, surviving peer EOF
     */
    public function __construct(public UploadMemoryReservationData $envelope, public string $receiptId)
    {
    }
}
