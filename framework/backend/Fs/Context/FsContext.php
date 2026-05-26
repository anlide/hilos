<?php

declare(strict_types=1);

namespace Hilos\Fs\Context;

use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\FsDirectory;
use Hilos\Fs\FsTmpDirectory;
use Hilos\Hilos;

/**
 * Base filesystem context — project subclasses register named directories.
 *
 * @property-read FsTmpDirectory $tmp
 */
abstract class FsContext
{
    /** Reserved logical name for the built-in temporary directory. */
    public const string TMP = 'tmp';

    protected ?FsTmpDirectory $_tmp = null;

    /** @var array<string, FsDirectory> */
    protected array $_directories = [];

    /**
     * Register named directories and configure tmp path.
     * Called automatically by {@see Hilos::init()}.
     */
    abstract public function configure(): void;

    /**
     * Set the path for the built-in tmp directory.
     */
    protected function setTmpPath(string $path): void
    {
        $this->_tmp = new FsTmpDirectory($path);
    }

    /**
     * Register a named directory (e.g. quarantine, published).
     */
    protected function registerDirectory(string $name, string $path): void
    {
        $this->_directories[$name] = new FsDirectory($this, $name, $path);
    }

    /**
     * @throws DirectoryNotFoundException If the directory is not registered
     */
    public function getDirectory(string $name): FsDirectory
    {
        if (!isset($this->_directories[$name])) {
            throw new DirectoryNotFoundException("FS directory [{$name}] is not registered");
        }

        return $this->_directories[$name];
    }

    /**
     * @throws DirectoryNotFoundException If tmp path has not been configured
     */
    public function getTmp(): FsTmpDirectory
    {
        if ($this->_tmp === null) {
            throw new DirectoryNotFoundException('FS tmp directory is not configured');
        }

        return $this->_tmp;
    }

    /**
     * Magic getter for `$fs->tmp`, `$fs->quarantine`, etc.
     *
     * @throws DirectoryNotFoundException If the name is unknown
     */
    public function __get(string $name): FsTmpDirectory|FsDirectory
    {
        if ($name === self::TMP) {
            return $this->getTmp();
        }

        return $this->getDirectory($name);
    }
}
