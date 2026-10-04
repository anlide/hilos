<?php

declare(strict_types=1);

namespace Hilos\Files\Metadata;

use Hilos\Core\Exception\ValidationException;

/** A picture container cannot be parsed far enough to strip its metadata safely. */
final class ImageMetadataException extends ValidationException
{
}
