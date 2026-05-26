<?php

declare(strict_types=1);

namespace Hilos\Fs;

use finfo;
use Hilos\Constants\HttpConstants;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\Exception\FileWriteException;

/**
 * Handle for a single file inside a named {@see FsDirectory}.
 *
 * Lightweight value object — no existence check on construction.
 */
final class FsFile
{
    private readonly string $path;

    /**
     * @param FsDirectory $directory Owning named directory
     * @param string $filename Basename inside the directory
     */
    public function __construct(
        private readonly FsDirectory $directory,
        private readonly string $filename,
    ) {
        $this->path = $this->directory->getPath() . DIRECTORY_SEPARATOR . basename($this->filename);
    }

    /**
     * Append binary payload to the file.
     *
     * @throws FileWriteException If append fails
     */
    public function append(string $data): void
    {
        if (file_put_contents($this->path, $data, FILE_APPEND) === false) {
            throw new FileWriteException("Cannot append to file: {$this->filename}");
        }
    }

    /**
     * Delete the file (no-op when already absent).
     *
     * @throws FileDeleteException If the file exists but cannot be removed
     */
    public function unlink(): void
    {
        if (is_file($this->path) && !@unlink($this->path)) {
            throw new FileDeleteException("Cannot delete file: {$this->filename}");
        }
    }

    /**
     * Detect MIME type via finfo.
     *
     * @throws FileNotFoundException If the file does not exist
     */
    public function getMimeType(): string
    {
        if (!is_file($this->path)) {
            throw new FileNotFoundException("File not found: {$this->filename}");
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);

        return $finfo->file($this->path) ?: HttpConstants::CONTENT_TYPE_OCTET_STREAM;
    }

    /**
     * @return int File size in bytes
     * @throws FileNotFoundException If the file does not exist
     */
    public function size(): int
    {
        if (!is_file($this->path)) {
            throw new FileNotFoundException("File not found: {$this->filename}");
        }

        return filesize($this->path) ?: 0;
    }

    /**
     * Move this file to another named directory (same basename).
     *
     * @param string $targetDirectoryName Registered directory name (e.g. 'published')
     * @throws FileNotFoundException If the source file does not exist
     * @throws FileMoveException If the rename operation fails
     */
    public function move(string $targetDirectoryName): void
    {
        if (!is_file($this->path)) {
            throw new FileNotFoundException("File not found: {$this->filename}");
        }
        $targetDir = $this->directory->getContext()->getDirectory($targetDirectoryName);
        $targetDir->ensureDirectory();
        $target = $targetDir->getPath() . DIRECTORY_SEPARATOR . basename($this->filename);
        if (!@rename($this->path, $target)) {
            throw new FileMoveException("Cannot move {$this->filename} to {$targetDirectoryName}");
        }
    }

    /**
     * Read entire file contents.
     *
     * @throws FileNotFoundException If the file does not exist
     * @throws FileReadException If the read operation fails
     */
    public function read(): string
    {
        if (!is_file($this->path)) {
            throw new FileNotFoundException("File not found: {$this->filename}");
        }
        $content = file_get_contents($this->path);
        if ($content === false) {
            throw new FileReadException("Cannot read file: {$this->filename}");
        }

        return $content;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    /**
     * Map MIME type to a conventional file extension (with leading dot).
     */
    public static function extensionForMime(string $mimeType): string
    {
        return match (strtolower(trim($mimeType))) {
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/gif' => '.gif',
            'image/webp' => '.webp',
            'application/pdf' => '.pdf',
            'text/plain' => '.txt',
            default => '.bin',
        };
    }
}
