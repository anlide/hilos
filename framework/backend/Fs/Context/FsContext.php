<?php

declare(strict_types=1);

namespace Hilos\Fs\Context;

use Hilos\Core\Feature\HilosFeature;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\FsDirectory;
use Hilos\Fs\FsTmpDirectory;
use Hilos\Hilos;

/**
 * Base filesystem context — project subclasses register named directories.
 *
 * @property-read FsTmpDirectory $tmp Built-in temporary directory
 * @property-read FsDirectory $files Published files of the files registry, where the project registers it
 */
abstract class FsContext
{
    /** Reserved logical name for the built-in temporary directory. */
    public const string TMP = 'tmp';

    /**
     * Reserved logical name for the published files of the files registry (HIL-336).
     *
     * A project declaring {@see HilosFeature::FILES} registers it in configure(); startup refuses
     * the project that declares the feature and registers no such directory.
     */
    public const string FILES = 'files';

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
     * @param string $name Logical directory name
     * @return bool Whether a directory is registered under the name
     */
    public function hasDirectory(string $name): bool
    {
        return isset($this->_directories[$name]);
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
     * @return bool Whether a tmp directory is configured
     */
    public function hasTmp(): bool
    {
        return $this->_tmp !== null;
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
