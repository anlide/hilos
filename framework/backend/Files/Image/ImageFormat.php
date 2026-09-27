<?php

declare(strict_types=1);

namespace Hilos\Files\Image;

use Hilos\Files\Upload\UploadMime;

/** The formats a declared image variant may produce. */
enum ImageFormat: string
{
    case WEBP = 'image/webp';
    case JPEG = 'image/jpeg';
    case PNG = 'image/png';

    public const array SOURCE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * @param string $mimeType Original file's declared type
     * @return bool Whether the image pipeline may try to decode it
     */
    public static function readsSource(string $mimeType): bool
    {
        return in_array(UploadMime::normalize($mimeType), self::SOURCE_MIME_TYPES, true);
    }
}
