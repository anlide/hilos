<?php

declare(strict_types=1);

namespace Hilos\Fs\Context;

use Hilos\Core\Feature\HilosFeature;
use Hilos\Environment\Exception\EnvException;
use Hilos\Fs\ClusterDirectoryMarker;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\FsDirectory;
use Hilos\Fs\FsTmpDirectory;
use Hilos\Hilos;

/**
 * Base filesystem context — project subclasses register named directories.
 *
 * The registration names the owner of every directory, tmp included: its node or the whole
 * cluster (DirectoryScope, docs/agents/architecture/filesystem.md).
 *
 * TODO(HIL-1203): a cluster directory is kept on a disk only — one machine or a volume every node
 * mounts. Not built: S3-compatible storages (Amazon S3, Cloudflare R2, Backblaze, Hetzner,
 * self-hosted Garage, SeaweedFS, MinIO) and Azure Blob; serving such a file — nginx proxies a
 * short-lived signed link under our own address; moving the files already kept when the storage
 * changes.
 *
 * @property-read FsTmpDirectory $tmp Built-in temporary directory, the cluster's: files are handed to the files library through it
 * @property-read FsDirectory $files Published files of the files registry, where the project registers it
 * @property-read FsDirectory $analytics_journal The node's analytics journal, where the project registers it
 */
abstract class FsContext
{
    /**
     * Reserved logical name for the built-in temporary directory.
     *
     * The uploads agent assembles an upload there, the images agent draws a copy there, a project's
     * check reads a complete upload there, and the files library takes either by its index from
     * its own node. A cluster directory whatever features the project declares: set it as
     * DirectoryScope::CLUSTER, and the start refuses it declared NODE. A project registers a
     * node-local draft under its own name with DirectoryScope::NODE.
     */
    public const string TMP = 'tmp';

    /**
     * Reserved logical name for the published files of the files registry (HIL-336).
     *
     * A project declaring {@see HilosFeature::FILES} registers it in configure(); startup refuses
     * the project that declares the feature and registers no such directory. A cluster directory:
     * registered as DirectoryScope::CLUSTER, the start refuses it declared NODE.
     */
    public const string FILES = 'files';

    /**
     * Ready data-export archives; only the export agent writes this shared directory (HIL-303).
     *
     * A cluster directory: registered as DirectoryScope::CLUSTER, the start refuses it declared NODE.
     */
    public const string DATA_EXPORT = 'data_export';

    /**
     * Files of acceptance records ordered by administrators; only the legal section's agent writes it (HIL-1234).
     *
     * A project registering the legal agent registers it in configure(); startup refuses the project
     * that registers the agent and no such directory. A cluster directory: registered as
     * DirectoryScope::CLUSTER, the start refuses it declared NODE.
     */
    public const string LEGAL_EXPORT = 'legal_export';

    /**
     * The node's analytics journal; its one owner is the journal agent of that node (HIL-1154).
     *
     * A project declaring {@see HilosFeature::ANALYTICS} registers it in configure(); startup refuses
     * the project that declares the feature and registers no such directory. A node directory:
     * registered as DirectoryScope::NODE, the start refuses it declared CLUSTER - every node keeps
     * its own journal, and a shared volume would hand one node's files to another node's owner.
     */
    public const string ANALYTICS_JOURNAL = 'analytics_journal';

    /** Trailing characters a path is compared without: "/x" and "/x/" name one directory. */
    private const string PATH_SEPARATORS = '/\\';

    /** @var FsTmpDirectory|null */
    protected ?FsTmpDirectory $_tmp = null;

    /** @var array<string, FsDirectory> */
    protected array $_directories = [];

    /**
     * Register named directories and configure tmp path.
     * Called automatically by {@see Hilos::init()}.
     *
     * @throws EnvException When a project directory setting cannot be read
     */
    abstract public function configure(): void;

    /**
     * @param string $path Absolute filesystem path for tmp storage
     * @param DirectoryScope $scope Whose the tmp directory is: the cluster's, see self::TMP
     */
    protected function setTmpPath(string $path, DirectoryScope $scope): void
    {
        $this->_tmp = new FsTmpDirectory($path, $scope);
    }

    /**
     * @param string $name Logical directory name
     * @param string $path Absolute filesystem path
     * @param DirectoryScope $scope Whose the directory is: its node's or the cluster's
     */
    protected function registerDirectory(string $name, string $path, DirectoryScope $scope): void
    {
        $this->_directories[$name] = new FsDirectory($this, $name, $path, $scope);
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
     * @return array<string, FsDirectory> Registered directories keyed by name, in registration order
     */
    public function getDirectories(): array
    {
        return $this->_directories;
    }

    /**
     * Every directory the registration declares the cluster's, tmp included.
     *
     * The reader is the start of a cluster node: it reads the marker of each and names it to its
     * peers ({@see ClusterDirectoryMarker}, HIL-1242). Two names registered on one path are both
     * listed - each is a kind of marker of its own, and the second read finds the file the first
     * wrote.
     *
     * @return array<string, string> Directory paths as registered, keyed by name: tmp under
     *     {@see self::TMP} first when it is the cluster's, then the directories in registration order
     */
    public function clusterDirectories(): array
    {
        $paths = [];
        if ($this->_tmp !== null && $this->_tmp->getScope() === DirectoryScope::CLUSTER) {
            $paths[self::TMP] = $this->_tmp->getPath();
        }
        foreach ($this->_directories as $name => $directory) {
            if ($directory->getScope() === DirectoryScope::CLUSTER) {
                $paths[$name] = $directory->getPath();
            }
        }

        return $paths;
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
     * What the registrations declare wrong; every fault is collected, none stops the walk.
     *
     * Three rules. Tmp is the cluster's whatever features the project declares. The reserved
     * files, data_export and legal_export are the cluster's, so any declared NODE is a fault; the
     * reserved analytics_journal is the node's, so it declared CLUSTER is one. One path is one
     * directory with one owner, so names registered on one path with different owners are a fault,
     * tmp counted under its reserved name. Paths are compared as written, less trailing
     * separators: a directory is created on first use and may not exist at start, so there is
     * nothing to resolve yet.
     *
     * @return list<string> Fault messages, tmp first, then reserved directories, then paths; empty when the declaration holds
     */
    public function declarationErrors(): array
    {
        $errors = [];
        if ($this->_tmp !== null && $this->_tmp->getScope() === DirectoryScope::NODE) {
            $errors[] = "FS tmp directory is the cluster's: set it with DirectoryScope::CLUSTER";
        }
        foreach ([self::FILES, self::DATA_EXPORT, self::LEGAL_EXPORT] as $reserved) {
            if ($this->hasDirectory($reserved) && $this->_directories[$reserved]->getScope() === DirectoryScope::NODE) {
                $errors[] = "FS directory [{$reserved}] is the cluster's: register it with DirectoryScope::CLUSTER";
            }
        }
        if (
            $this->hasDirectory(self::ANALYTICS_JOURNAL)
            && $this->_directories[self::ANALYTICS_JOURNAL]->getScope() === DirectoryScope::CLUSTER
        ) {
            $errors[] = 'FS directory [' . self::ANALYTICS_JOURNAL . "] is the node's: register it with DirectoryScope::NODE";
        }

        /** @var array<string, array<string, DirectoryScope>> $scopesByPath */
        $scopesByPath = [];
        if ($this->_tmp !== null) {
            $scopesByPath[rtrim($this->_tmp->getPath(), self::PATH_SEPARATORS)][self::TMP] = $this->_tmp->getScope();
        }
        foreach ($this->_directories as $name => $directory) {
            $scopesByPath[rtrim($directory->getPath(), self::PATH_SEPARATORS)][$name] = $directory->getScope();
        }

        foreach ($scopesByPath as $path => $scopes) {
            $owners = array_map(static fn(DirectoryScope $scope): string => $scope->name, $scopes);
            if (count(array_unique($owners)) === 1) {
                continue;
            }
            $declared = [];
            foreach ($owners as $name => $owner) {
                $declared[] = "{$name} {$owner}";
            }
            $errors[] = 'FS directories [' . implode(', ', array_keys($owners)) . "] share the path {$path}"
                . ' but declare different owners: ' . implode(', ', $declared);
        }

        return $errors;
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
