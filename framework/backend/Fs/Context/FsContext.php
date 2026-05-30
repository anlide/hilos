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
 * @property-read FsTmpDirectory $tmp Built-in temporary directory
 */
abstract class FsContext
{
    /** Reserved logical name for the built-in temporary directory. */
    public const string TMP = 'tmp';

    /** @var FsTmpDirectory|null */
    protected ?FsTmpDirectory $_tmp = null;

    /** @var array<string, FsDirectory> */
    protected array $_directories = [];

    /**
     * Register named directories and configure tmp path.
     * Called automatically by {@see Hilos::init()}.
     */
    abstract public function configure(): void;

    /**
     * @param string $path Absolute filesystem path for tmp storage
     */
    protected function setTmpPath(string $path): void
    {
        $this->_tmp = new FsTmpDirectory($path);
    }

    /**
     * @param string $name Logical directory name
     * @param string $path Absolute filesystem path
     */
    protected function registerDirectory(string $name, string $path): void
    {
        $this->_directories[$name] = new FsDirectory($this, $name, $path);
    }

    /**
     * @param string $name Registered logical directory name
     * @return FsDirectory Named directory handle
     *
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
     * @return FsTmpDirectory Configured tmp directory
     *
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
     * @param string $name Logical directory name or TMP constant
     * @return FsTmpDirectory|FsDirectory Resolved directory handle
     *
     * @throws DirectoryNotFoundException If the name is unknown or tmp is not configured
     */
    public function __get(string $name): FsTmpDirectory|FsDirectory
    {
        if ($name === self::TMP) {
            return $this->getTmp();
        }

        return $this->getDirectory($name);
    }
}
