<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

/** The consumer's final settlement of an upload hold. */
enum UploadHoldOutcome: string
{
    case APPROVED = 'approved';
    case RELEASED = 'released';
}
