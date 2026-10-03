<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\Files\FileVisibility;

/** One cropped profile photo produced by the browser. */
final class ProfilePhotoUploadTarget extends AbstractUploadTarget
{
    public const string NAME = 'hilos_profile_photo';

    public const string VARIANT = 'hilos_avatar';

    private const int MAX_BYTES = 524288;

    /** @return int The largest cropped JPEG accepted, in bytes */
    public function maxBytes(): int
    {
        return self::MAX_BYTES;
    }

    /** @return bool A person must be signed in to change their photo */
    public function requiresSignIn(): bool
    {
        return true;
    }

    /** @return list<string> The browser sends a cropped JPEG */
    public function acceptedMimeTypes(): array
    {
        return ['image/jpeg'];
    }

    /** @return bool The file content must match its declared JPEG type */
    public function sniffsContent(): bool
    {
        return true;
    }

    /** @return FileVisibility Everybody may read a published profile photo */
    public function visibility(): FileVisibility
    {
        return FileVisibility::PUBLIC;
    }
}
