<?php

declare(strict_types=1);

namespace Hilos\Status;

use DirectoryIterator;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Status\Exception\StatusException;
use Hilos\Utils\Helpers\RandomHelper;
use JsonException;
use Random\RandomException;
use UnexpectedValueException;

/** Private transit on the existing cluster Status volume; only the Status worker opens it. */
final class StatusMaintenanceObservationStore
{
    public const int MAX_FILES_PER_PASS = 32;
    public const string DIRECTORY = 'maintenance-observations';
    private const int TEMP_NAME_BYTES = 16;
    private const int DIRECTORY_MODE = 0700;
    private const int FILE_MODE = 0600;
    private const string FILENAME_PATTERN = '/^([a-f0-9]{32})\.json$/D';

    /** @var ?DirectoryIterator Private restartable discovery cursor; never shared runtime state */
    private ?DirectoryIterator $cursor = null;

    /**
     * Writes of the start and completion bypass the caller's heartbeat cadence.
     * A temporary file is on the same volume and is never a public nginx address.
     *
     * @param StatusMaintenanceObservation $observation Complete sanitized observation
     * @throws StatusException When transit cannot be atomically saved
     */
    public function save(StatusMaintenanceObservation $observation): void
    {
        $temporary = null;
        try {
            $path = $this->path();
            FsPath::ensureDirectory($path, self::DIRECTORY_MODE);
            $temporary = $path . '/.' . RandomHelper::secureHex(self::TEMP_NAME_BYTES) . '.tmp';
            $json = json_encode($observation->toArray(), JSON_THROW_ON_ERROR);
            FsPath::write($temporary, $json);
            FsPath::chmod($temporary, self::FILE_MODE);
            FsPath::publish($temporary, $path . '/' . $observation->period->id . '.json');
        } catch (FsException | JsonException | RandomException $failure) {
            throw new StatusException('Cannot save maintenance observation transit.', previous: $failure);
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                try {
                    FsPath::delete($temporary);
                } catch (FsException) {
                    // A failed cleanup must not replace the persistence failure; discovery ignores temporary files.
                }
            }
        }
    }

    /**
     * Visits at most 32 directory entries, including foreign or malformed names.
     * The caller loads each returned name separately so one damaged file cannot stop the pass.
     *
     * @return list<string> Candidate basenames, never paths
     * @throws StatusException When the private directory cannot be opened
     */
    public function discover(): array
    {
        if (!is_dir($this->path())) {
            $this->cursor = null;
            return [];
        }
        try {
            $this->cursor ??= new DirectoryIterator($this->path());
            $names = [];
            for ($step = 0; $step < self::MAX_FILES_PER_PASS && $this->cursor->valid(); ++$step) {
                $name = $this->cursor->getFilename();
                if (!$this->cursor->isLink() && $this->cursor->isFile() && preg_match(self::FILENAME_PATTERN, $name) === 1) {
                    $names[] = $name;
                }
                $this->cursor->next();
            }
            if (!$this->cursor->valid()) {
                $this->cursor = null;
            }
            return $names;
        } catch (UnexpectedValueException $failure) {
            $this->cursor = null;
            throw new StatusException('Cannot discover maintenance observation transit.', previous: $failure);
        }
    }

    /** @return bool Whether a bounded discovery sweep still has entries to visit */
    public function isDiscovering(): bool
    {
        return $this->cursor !== null;
    }

    /**
     * @param string $name Candidate basename obtained from discovery
     * @return StatusMaintenanceObservation Validated own observation
     * @throws StatusException When the name, identity or payload is invalid or unreadable
     */
    public function load(string $name): StatusMaintenanceObservation
    {
        if (preg_match(self::FILENAME_PATTERN, $name, $match) !== 1) {
            throw new StatusException('Invalid maintenance observation filename.');
        }
        $path = $this->path() . '/' . $name;
        try {
            if (is_link($path)) {
                throw new StatusException('Invalid maintenance observation file.');
            }
            $observation = StatusMaintenanceObservation::fromJson(FsPath::read($path));
            if ($observation->period->id !== $match[1]) {
                throw new StatusException('Maintenance observation file identity does not match its name.');
            }
            return $observation;
        } catch (FsException $failure) {
            throw new StatusException('Cannot read maintenance observation transit.', previous: $failure);
        }
    }

    /**
     * The caller invokes this only after a successful DB commit.
     *
     * @param StatusMaintenanceObservation $observation Committed observation
     * @throws StatusException When committed transit cannot be removed
     */
    public function remove(StatusMaintenanceObservation $observation): void
    {
        try {
            $path = $this->path() . '/' . $observation->period->id . '.json';
            if (is_file($path)) {
                FsPath::delete($path);
            }
        } catch (FsException $failure) {
            throw new StatusException('Cannot remove committed maintenance observation transit.', previous: $failure);
        }
    }

    /**
     * @return string Local address under the existing cluster directory
     * @throws StatusException When the filesystem context is absent
     */
    private function path(): string
    {
        if (Hilos::$fs === null) {
            throw new StatusException('Maintenance observations require the Status directory.');
        }
        return Hilos::$fs->status->getPath() . '/' . self::DIRECTORY;
    }
}
