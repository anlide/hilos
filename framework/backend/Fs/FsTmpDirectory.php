<?php

declare(strict_types=1);

namespace Hilos\Fs;

use ArrayAccess;
use Hilos\Fs\Exception\DirectoryCreateException;
use Hilos\Fs\Exception\FileWriteException;
use LogicException;
use Random\RandomException;

/**
 * Temporary-file directory with auto-generated hex-based filenames.
 *
 * @implements ArrayAccess<string, FsTmpFile>
 */
final class FsTmpDirectory implements ArrayAccess
{
    private readonly string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Create an empty tmp file with a random hex name.
     *
     * @return string Generated index (hex filename) that can be used with ArrayAccess
     * @throws RandomException If random_bytes() fails
     * @throws FileWriteException If the file cannot be created
     * @throws DirectoryCreateException If the tmp directory cannot be created
     */
    public function create(): string
    {
        $this->ensureDirectory();
        $index = bin2hex(random_bytes(16));
        $path = $this->path . DIRECTORY_SEPARATOR . $index;
        if (file_put_contents($path, '') === false) {
            throw new FileWriteException("Cannot create tmp file: {$index}");
        }

        return $index;
    }

    /**
     * @throws DirectoryCreateException If the directory cannot be created
     */
    public function ensureDirectory(): void
    {
        if (!is_dir($this->path) && !@mkdir($this->path, 0775, true) && !is_dir($this->path)) {
            throw new DirectoryCreateException("Cannot create tmp directory: {$this->path}");
        }
    }

    public function getPath(): string
    {
        return $this->path;
    }

    // ── ArrayAccess ──────────────────────────────────────────────

    /**
     * @param string $offset Hex index returned by {@see create()}
     */
    public function offsetExists(mixed $offset): bool
    {
        return is_file($this->path . DIRECTORY_SEPARATOR . basename((string)$offset));
    }

    /**
     * @param string $offset Hex index returned by {@see create()}
     */
    public function offsetGet(mixed $offset): FsTmpFile
    {
        return new FsTmpFile($this, (string)$offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('FsTmpDirectory is read-only via ArrayAccess; use create()');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('FsTmpDirectory is read-only via ArrayAccess; use $file->unlink()');
    }
}
