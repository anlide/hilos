<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\Files\Upload\DTO\UploadCreditRequestSignalData;

/** One deduplicated metadata wait, before or after its upload declaration arrives. */
final class UploadMemoryCreditDemand
{
    public bool $admitted = false;

    /** Original placement kind, captured before holding either node's capacity. */
    public ?bool $localPlacement = null;

    /** @param UploadCreditRequestSignalData $request Socket-stamped full-size demand */
    public function __construct(public readonly UploadCreditRequestSignalData $request)
    {
    }
}
