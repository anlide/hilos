<?php

declare(strict_types=1);

namespace Hilos\Files\Image;

use Hilos\Core\Exception\ValidationException;

/** The uploaded image cannot be decoded or rendered into its declared variant. */
final class ImageRenderException extends ValidationException
{
}
