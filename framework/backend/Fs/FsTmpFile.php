<?php

declare(strict_types=1);

namespace Hilos\Fs;

use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\Exception\FileWriteException;

/**
 * Handle for a single file inside FsTmpDirectory.
 *
 * Lightweight value object — no existence check on construction.
 */
final readonly class FsTmpFile
{
    private string $path;

    /**
     * @param FsTmpDirectory $directory Owning tmp directory
     * @param string $index Filename (hex index) inside the tmp directory
     */
    public function __construct(
        private FsTmpDirectory $directory,
        private string $index,
    ) {
        $this->path = $this->directory->getPath() . DIRECTORY_SEPARATOR . basename($this->index);
    }

    /**
     * @param string $data Binary payload to append
     *
     * @throws FileWriteException If append fails
     */
    public function append(string $data): void
    {
        FsPath::append($this->path, $data);
    }

    /**
     * Delete the file (no-op when already absent).
     *
     * @throws FileDeleteException If the file exists but cannot be removed
     */
    public function unlink(): void
    {
        FsPath::delete($this->path);
    }

    /**
     * @return int File size in bytes (0 when absent)
     */
    public function size(): int
    {
        return is_file($this->path) ? (filesize($this->path) ?: 0) : 0;
    }

    /**
     * @return bool Whether the file exists on disk
     */
    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * @return string Absolute filesystem path
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return string Hex index in the owning tmp directory
     */
    public function getIndex(): string
    {
        return $this->index;
    }
}
