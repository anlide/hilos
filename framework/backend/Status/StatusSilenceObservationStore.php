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

/**
 * Private transit on the existing cluster Status volume, independent of database availability.
 */
final class StatusSilenceObservationStore
{
    public const int MAX_FILES_PER_PASS = 32;
    public const int MAX_FILE_BYTES = 1024;
    public const string DIRECTORY = 'silence-observations';
    private const int TEMP_NAME_BYTES = 16;
    private const int DIRECTORY_MODE = 0700;
    private const int FILE_MODE = 0600;
    private const string FILENAME_PATTERN = '/^([a-f0-9]{32})\.json$/D';

    /** @var ?DirectoryIterator Private restartable cursor; no other process reads this traversal state */
    private ?DirectoryIterator $cursor = null;

    /**
     * A failed save retains the previous observation. Only an open record may acquire an end.
     * The caller qualifies the start and obtains a publication receipt before saving a closed record.
     *
     * @param StatusSilenceObservation $observation Proven open or closed facts
     * @throws StatusException When transit is inconsistent or cannot be atomically saved
     * @throws RandomException When secure entropy for the temporary name is unavailable
     */
    public function save(StatusSilenceObservation $observation): void
    {
        $temporary = null;
        try {
            $path = $this->path();
            FsPath::ensureDirectory($path, self::DIRECTORY_MODE);
            FsPath::chmod($path, self::DIRECTORY_MODE);
            $name = $observation->id . '.json';
            $target = $path . '/' . $name;
            clearstatcache(true, $target);
            if (file_exists($target) || is_link($target)) {
                $previous = $this->load($name);
                if ($previous->startedAt !== $observation->startedAt
                    || ($previous->endedAt !== null && $previous->endedAt !== $observation->endedAt)) {
                    throw new StatusException('Silence transit cannot replace different or already closed facts.');
                }
            }
            $candidate = $path . '/.' . RandomHelper::secureHex(self::TEMP_NAME_BYTES) . '.tmp';
            if (!FsPath::createExclusive($candidate, '')) {
                throw new StatusException('Silence transit temporary name is already occupied.');
            }
            $temporary = $candidate;
            FsPath::chmod($temporary, self::FILE_MODE);
            $json = json_encode($observation->toArray(), JSON_THROW_ON_ERROR);
            FsPath::write($temporary, $json);
            if (FsPath::size($temporary) !== strlen($json)) {
                throw new StatusException('Cannot save a complete silence observation.');
            }
            FsPath::publish($temporary, $target);
        } catch (FsException | JsonException $failure) {
            throw new StatusException('Cannot save silence observation transit.', previous: $failure);
        } finally {
            if ($temporary !== null) {
                try {
                    FsPath::delete($temporary);
                } catch (FsException) {
                    // A cleanup failure must not replace the save failure; discovery ignores temporary files.
                }
            }
        }
    }

    /**
     * Visits at most 32 entries, counting temporary, foreign and malformed names too.
     * Load each returned name separately so one damaged record cannot stop the sweep.
     *
     * @return list<string> Candidate basenames, never paths
     * @throws StatusException When the private directory is invalid or cannot be opened
     */
    public function discover(): array
    {
        try {
            $path = $this->path();
            if (!is_dir($path)) {
                $this->cursor = null;
                return [];
            }
            $this->cursor ??= new DirectoryIterator($path);
            $names = [];
            for ($step = 0; $step < self::MAX_FILES_PER_PASS && $this->cursor->valid(); ++$step) {
                $name = $this->cursor->getFilename();
                if (preg_match(self::FILENAME_PATTERN, $name) === 1 && !$this->cursor->isLink() && $this->cursor->isFile()) {
                    $names[] = $name;
                }
                $this->cursor->next();
            }
            if (!$this->cursor->valid()) {
                $this->cursor = null;
            }
            return $names;
        } catch (StatusException | UnexpectedValueException $failure) {
            $this->cursor = null;
            throw new StatusException('Cannot discover silence observation transit.', previous: $failure);
        }
    }

    /** @return bool Whether a bounded discovery sweep has entries left to visit */
    public function isDiscovering(): bool
    {
        return $this->cursor !== null;
    }

    /**
     * @param string $name Candidate basename obtained from discovery
     * @return StatusSilenceObservation Validated facts belonging to the filename's identity
     * @throws StatusException When the name, file, size, identity or payload is invalid or unreadable
     */
    public function load(string $name): StatusSilenceObservation
    {
        if (preg_match(self::FILENAME_PATTERN, $name, $match) !== 1) {
            throw new StatusException('Invalid silence observation filename.');
        }
        $path = $this->path() . '/' . $name;
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) {
            throw new StatusException('Invalid silence observation file.');
        }
        try {
            $json = FsPath::readWith($path, static function ($handle): string {
                // warning-suppressed: a failed bounded read becomes StatusException below
                $contents = @stream_get_contents($handle, self::MAX_FILE_BYTES + 1);
                if ($contents === false || strlen($contents) > self::MAX_FILE_BYTES) {
                    throw new StatusException('Silence observation file is unreadable or too large.');
                }
                return $contents;
            });
            $observation = StatusSilenceObservation::fromJson($json);
            if ($observation->id !== $match[1]) {
                throw new StatusException('Silence observation file identity does not match its name.');
            }
            return $observation;
        } catch (FsException $failure) {
            throw new StatusException('Cannot read silence observation transit.', previous: $failure);
        }
    }

    /**
     * Only the exact closed facts committed by the caller may be removed; changed or damaged files remain for retry.
     *
     * @param StatusSilenceObservation $observation Closed observation after a successful DB commit
     * @throws StatusException When facts are open, no longer match, or cannot be read or removed
     */
    public function remove(StatusSilenceObservation $observation): void
    {
        if ($observation->endedAt === null) {
            throw new StatusException('An open silence observation cannot be removed as committed.');
        }
        $name = $observation->id . '.json';
        $path = $this->path() . '/' . $name;
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if ($this->load($name)->toArray() !== $observation->toArray()) {
            throw new StatusException('Silence transit no longer matches the committed observation.');
        }
        try {
            FsPath::delete($path);
        } catch (FsException $failure) {
            throw new StatusException('Cannot remove committed silence observation transit.', previous: $failure);
        }
    }

    /**
     * @return string Local path under the existing cluster directory
     * @throws StatusException When the filesystem context is absent or transit is not a real accessible directory
     */
    private function path(): string
    {
        if (Hilos::$fs === null) {
            throw new StatusException('Silence observations require the Status directory.');
        }
        $path = Hilos::$fs->status->getPath() . '/' . self::DIRECTORY;
        clearstatcache(true, $path);
        if (is_link($path) || (file_exists($path) && (!is_dir($path) || !is_readable($path) || !is_executable($path)))) {
            throw new StatusException('Silence transit requires a real accessible private directory.');
        }
        return $path;
    }
}
