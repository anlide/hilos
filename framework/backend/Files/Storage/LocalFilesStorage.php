<?php

declare(strict_types=1);

namespace Hilos\Files\Storage;

use Hilos\Fs\Context\FsContext;
use Hilos\Fs\Exception\DirectoryCreateException;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Fs\FsDirectory;
use Hilos\Fs\FsPath;
use Hilos\Hilos;

/**
 * Files kept in the project's files directory on the local disk (HIL-136).
 *
 * The one storage the framework ships: a kept file is the file of its stored name in the
 * directory registered as {@see FsContext::FILES}. The directory is asked for on every call
 * rather than once: the facade creates the door, and this storage with it, before the FS
 * context is configured.
 */
final class LocalFilesStorage implements FilesStorageInterface
{
    /**
     * Renames the temporary file into the files directory, or copies it there when the rename
     * cannot cross to the volume the files directory sits on.
     *
     * @param string $storedName Name to keep the file under, without a path
     * @param string $tmpIndex Index of the temporary file in the tmp directory
     * @throws DirectoryNotFoundException When the FS context, its tmp or its files directory is not configured
     * @throws DirectoryCreateException When the files directory cannot be created
     * @throws FileNotFoundException When the temporary file is not there
     * @throws FileWriteException When the rename failed and the copy failed too
     * @throws FileDeleteException When the temporary file stays after its copy
     */
    public function storeFromTmp(string $storedName, string $tmpIndex): void
    {
        $directory = $this->directory();
        try {
            $directory->createFromTmp($storedName, $tmpIndex);
        } catch (FileMoveException) {
            $tmpFile = $directory->getContext()->getTmp()[$tmpIndex];
            FsPath::copy($tmpFile->getPath(), $directory[$storedName]->getPath());
            $tmpFile->unlink();
        }
    }

    /**
     * Deletes the file of the stored name from the files directory.
     *
     * @param string $storedName Name the file is kept under
     * @throws DirectoryNotFoundException When the FS context or its files directory is not configured
     * @throws FileDeleteException When the file is there and stays
     */
    public function delete(string $storedName): void
    {
        $this->directory()[$storedName]->unlink();
    }

    /**
     * @return FsDirectory The files directory
     * @throws DirectoryNotFoundException When the FS context or its files directory is not configured
     */
    private function directory(): FsDirectory
    {
        return (Hilos::$fs ?? throw new DirectoryNotFoundException('FS context is not configured'))->getDirectory(FsContext::FILES);
    }
}
