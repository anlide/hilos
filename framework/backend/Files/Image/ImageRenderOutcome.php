<?php

declare(strict_types=1);

namespace Hilos\Files\Image;

/** What the images agent hands back to the files library. */
enum ImageRenderOutcome: string
{
    case RENDERED = 'rendered';
    case READY = 'ready';
    case FAILED = 'failed';
    case MISSING = 'missing';
}
