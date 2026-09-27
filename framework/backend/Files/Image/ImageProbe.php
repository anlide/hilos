<?php

declare(strict_types=1);

namespace Hilos\Files\Image;

/** Encoded source dimensions, read before allocating a decoded image. */
final readonly class ImageProbe
{
    /**
     * @param string $mimeType Detected source MIME type
     * @param int $width Encoded width in pixels
     * @param int $height Encoded height in pixels
     */
    public function __construct(public string $mimeType, public int $width, public int $height)
    {
    }
}
