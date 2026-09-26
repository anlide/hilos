<?php

declare(strict_types=1);

namespace Hilos\Files\Storage;

use Hilos\Files\HilosFiles;
use Hilos\Files\Library\AbstractFilesLibraryAgent;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\FsException;

/**
 * Where the files of the files registry are kept (HIL-136).
 *
 * The seam between the registry and the place its files lie. It lives on the door,
 * {@see HilosFiles::$storage}, and has one writer: {@see AbstractFilesLibraryAgent}, which puts
 * a handed-over temporary file in when it publishes and takes an unbound one out when its
 * janitor sweeps. Its one reader is the same agent serving a file by id (HIL-138): the size to
 * judge whether the daemon may send the file itself, and the bytes when it does. The path
 * nginx is pointed at by X-Accel is not asked of the storage - it is the stored name under the
 * internal location the installation configures.
 *
 * A kept file is known by its stored name alone: the registry row carries the name, and where
 * the bytes lie under it is the storage's own affair.
 */
interface FilesStorageInterface
{
    /**
     * Keeps a temporary file under a stored name.
     *
     * Once the call returns, the temporary file is gone and the storage holds the file under
     * the name. When it throws, either may still be there.
     *
     * @param string $storedName Name to keep the file under, without a path
     * @param string $tmpIndex Index of the temporary file in the tmp directory
     * @throws FsException When the file cannot be kept
     */
    public function storeFromTmp(string $storedName, string $tmpIndex): void;

    /**
     * Removes the file kept under a stored name; a name nothing is kept under is not an error.
     *
     * @param string $storedName Name the file is kept under
     * @throws FileDeleteException When a file is kept under the name and stays
     * @throws FsException When the storage itself cannot be reached
     */
    public function delete(string $storedName): void;

    /**
     * Tells the size of the file kept under a stored name.
     *
     * @param string $storedName Name the file is kept under
     * @return ?int Size in bytes, or null when nothing is kept under the name
     * @throws FsException When the storage itself cannot be reached
     */
    public function size(string $storedName): ?int;

    /**
     * Reads the whole file kept under a stored name.
     *
     * @param string $storedName Name the file is kept under
     * @return string The file's bytes
     * @throws FsException When nothing is kept under the name, or it cannot be read
     */
    public function read(string $storedName): string;
}
