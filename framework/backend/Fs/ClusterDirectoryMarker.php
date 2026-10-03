<?php

declare(strict_types=1);

namespace Hilos\Fs;

use Closure;
use Hilos\Database\DatabaseMarker;
use Hilos\Fs\Exception\DirectoryCreateException;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Log\LogRootOwnerMarker;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;
use JsonException;

/**
 * The marker of a cluster directory: a file that names this directory, so that nodes can tell
 * whether they read the same one (HIL-1242).
 *
 * A directory declared {@see DirectoryScope::CLUSTER} promises that every node reads one directory
 * - one disk, or a volume every node mounts - and the marker is how a cluster checks it. The first
 * node to start writes a random name into a file at the root of the directory; every node reads it
 * once at the start of its daemon, right after the database marker ({@see DatabaseMarker}), and
 * names it to its peers on the handshake. A node that reads another name reads another directory.
 *
 * The marker is a random name of the directory, not its content. Random rather than derived - from
 * the database marker, say: a node with an empty directory of its own would derive the same name,
 * write it and pass, and the guard would be blind exactly where it is needed.
 *
 * The first write is decided by the filesystem: {@see FsPath::createExclusive()} opens the file
 * with O_EXCL, so of two nodes writing it the second is told it is already there. Not a temp file
 * and a rename, as {@see LogRootOwnerMarker} writes: the rename overwrites, and when several nodes
 * start at once on an empty directory, the first writer would hold in memory a marker that is no
 * longer on the disk. The loser may find the file empty or half-written for a moment, so it waits
 * for the whole file rather than for a deadline.
 *
 * Only the start of a cluster node's daemon writes and reads it; a single-node installation never
 * writes it - there is nobody to compare it with. The framework's own sweeps leave it alone: they
 * list a directory through {@see FsDirectory::entries()}, which does not name it. Removed by hand,
 * the marker splits the cluster on the next restarts: the node that starts next writes a new one,
 * and every node still running refuses it (docs/agents/architecture/filesystem.md, The Guard).
 *
 * A new marker is drawn from the tolerant random axis ({@see RandomHelper::hex()}): it is a name,
 * not a secret - it only has to differ from the name of every other directory, and a stranger who
 * guessed it would gain nothing without the directory itself. A node whose entropy source refuses
 * still starts, where the secure axis would have stopped it over a value nobody needs to guess.
 *
 * Static because a marker has no state of its own: it is addressed by the directory it lives in.
 */
final class ClusterDirectoryMarker
{
    /** @var string Basename of the marker file at the root of a cluster directory */
    public const string FILE_NAME = '.hilos-cluster-directory.json';

    /**
     * Shape of the file this build writes and accepts.
     *
     * Read back and refused when it differs: a build that does not understand what it finds must
     * say so rather than treat the directory as one that carries no marker yet.
     */
    private const int FORMAT_VERSION = 1;

    /** @var string File key carrying {@see FORMAT_VERSION} */
    private const string KEY_VERSION = 'version';

    /** @var string File key carrying the marker itself */
    private const string KEY_MARKER = 'marker';

    /** @var string File key carrying the CLUSTER_NODE_ID of the node that wrote the marker */
    private const string KEY_WRITTEN_BY = 'writtenBy';

    /** @var string File key carrying when the marker was written, by the writer's clock */
    private const string KEY_WRITTEN_AT = 'writtenAt';

    /** @var int Random bytes a new marker is drawn from; their hex spelling is the marker */
    private const int MARKER_BYTES = 16;

    /** @var string What a marker this build writes looks like: the lowercase hex spelling of MARKER_BYTES */
    private const string MARKER_PATTERN = '/^[0-9a-f]{32}$/';

    /** @var int Seconds a node that found the file incomplete sleeps between two reads of it */
    private const int POLL_INTERVAL_SECONDS = 1;

    /** @var int Polls between two waiting lines in the journal; the first poll always writes one */
    private const int POLLS_PER_WAITING_LINE = 30;

    /**
     * Names the marker file of one directory.
     *
     * @param string $directoryPath Absolute path of the directory
     * @return string Absolute path of the marker file
     */
    public static function pathIn(string $directoryPath): string
    {
        return $directoryPath . DIRECTORY_SEPARATOR . self::FILE_NAME;
    }

    /**
     * Names where this node reads a directory's marker from, for the lines that print it.
     *
     * @param string $name Logical name of the directory as registered in the context
     * @param string $directoryPath Absolute path of the directory
     * @return string The directory's name and path
     */
    public static function place(string $name, string $directoryPath): string
    {
        return "cluster directory {$name} at {$directoryPath}";
    }

    /**
     * Reads the marker of a directory, writing it first when the directory carries none yet.
     *
     * The directory is created when absent. The value returned is always the one read back from
     * the file, never the one this node tried to write: of nodes writing at once, one wins and all
     * of them read its marker. There is no deadline: a node that finds the file empty or not yet
     * JSON - another node is writing it - goes on only once the file is whole, and says so in the
     * journal on the first poll and on every 30th after it.
     *
     * @param string $name Logical name of the directory as registered in the context
     * @param string $directoryPath Absolute path of the directory
     * @param string $writtenBy CLUSTER_NODE_ID of the node asking; recorded only if it writes the marker
     * @param ?Closure(): void $pause Wait between two polls; one second when null
     * @return ClusterDirectoryMarkerRow The marker of this directory
     *
     * @throws DirectoryCreateException When the directory is absent and cannot be created
     * @throws FileReadException When the file cannot be read, or is JSON this build does not write
     * @throws FileWriteException When the file is absent and cannot be created, encoded or written
     */
    public static function ensure(string $name, string $directoryPath, string $writtenBy, ?Closure $pause = null): ClusterDirectoryMarkerRow
    {
        FsPath::ensureDirectory($directoryPath);
        $file = self::pathIn($directoryPath);
        $polls = 0;
        while (true) {
            try {
                $row = self::read($name, $file);
            } catch (FileNotFoundException) {
                // Created here or by another node first: either way the next read says which marker it is.
                FsPath::createExclusive($file, self::encode($name, $file, $writtenBy));

                continue;
            }
            if ($row !== null) {
                return $row;
            }

            if ($polls % self::POLLS_PER_WAITING_LINE === 0) {
                Logger::warning(
                    "Waiting for the marker of cluster directory {$name} another node is writing: {$file} is empty or incomplete;"
                    . ' if no node is starting, it was left broken - remove it',
                );
            }
            $polls++;

            if ($pause === null) {
                sleep(self::POLL_INTERVAL_SECONDS);
            } else {
                $pause();
            }
        }
    }

    /**
     * Spells a new marker the way the file carries it.
     *
     * An encoding that fails - a node id that is not UTF-8 - is a marker that cannot be written,
     * and is told as one: the start of a node knows the family of file errors, not JSON's.
     *
     * @param string $name Logical name of the directory, named in the failure
     * @param string $file Absolute path of the marker file, named in the failure
     * @param string $writtenBy CLUSTER_NODE_ID of the node writing the marker
     * @return string The whole file: version, a new random marker, the writer and the time
     *
     * @throws FileWriteException When the marker cannot be encoded
     */
    private static function encode(string $name, string $file, string $writtenBy): string
    {
        try {
            return json_encode(
                [
                    self::KEY_VERSION => self::FORMAT_VERSION,
                    self::KEY_MARKER => RandomHelper::hex(self::MARKER_BYTES),
                    self::KEY_WRITTEN_BY => $writtenBy,
                    self::KEY_WRITTEN_AT => TimeHelper::getSqlDateTime(),
                ],
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $unencodable) {
            throw new FileWriteException("Cannot encode the marker of cluster directory {$name} for {$file}", 0, $unencodable);
        }
    }

    /**
     * Reads the marker file as it stands.
     *
     * Empty or not JSON is a file another node is still writing, not a foreign one: O_EXCL makes it
     * visible before its payload is. JSON that is not the shape this build writes is foreign.
     *
     * @param string $name Logical name of the directory, named in a refusal
     * @param string $file Absolute path of the marker file
     * @return ?ClusterDirectoryMarkerRow The marker, or null while the file is empty or not yet JSON
     *
     * @throws FileNotFoundException When the directory carries no marker file
     * @throws FileReadException When the file cannot be read, or is JSON this build does not write
     */
    private static function read(string $name, string $file): ?ClusterDirectoryMarkerRow
    {
        try {
            $decoded = json_decode(FsPath::read($file), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded) || ($decoded[self::KEY_VERSION] ?? null) !== self::FORMAT_VERSION) {
            throw self::foreign($name, $file);
        }
        $marker = $decoded[self::KEY_MARKER] ?? null;
        $writtenBy = $decoded[self::KEY_WRITTEN_BY] ?? null;
        $writtenAt = $decoded[self::KEY_WRITTEN_AT] ?? null;
        if (
            !is_string($marker) || preg_match(self::MARKER_PATTERN, $marker) !== 1
            || !is_string($writtenBy) || $writtenBy === ''
            || !is_string($writtenAt) || $writtenAt === ''
        ) {
            throw self::foreign($name, $file);
        }

        return new ClusterDirectoryMarkerRow($marker, $writtenBy, $writtenAt);
    }

    /**
     * @param string $name Logical name of the directory
     * @param string $file Absolute path of the marker file
     * @return FileReadException The refusal of a file this build does not write, naming the file and the remedy
     */
    private static function foreign(string $name, string $file): FileReadException
    {
        return new FileReadException(
            "Cluster directory {$name} carries {$file}, which is not a marker this build writes:"
            . ' remove it while no node of the cluster runs',
        );
    }
}
