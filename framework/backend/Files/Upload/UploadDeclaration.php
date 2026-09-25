<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

/**
 * What a browser declared about one file before sending it, as the checks read it (HIL-135).
 *
 * Built by the uploads agent after the form of the action was accepted, so every field here is
 * already in its checked shape: the file name has no path, the type is normalized.
 */
final readonly class UploadDeclaration
{
    /**
     * @param string $acceptKey Accept key of the connection that declared the file
     * @param string $clientUploadId Id the client gave the upload, unique on its connection
     * @param string $target Name of the upload target in the project's UPLOAD_TARGETS
     * @param ?int $userId User signed in on the connection, or null for a guest
     * @param string $filename File name without any path
     * @param string $mimeType Declared type, normalized by {@see UploadMime::normalize()}
     * @param int $size Declared size in bytes
     */
    public function __construct(
        public string $acceptKey,
        public string $clientUploadId,
        public string $target,
        public ?int $userId,
        public string $filename,
        public string $mimeType,
        public int $size,
    ) {
    }
}
