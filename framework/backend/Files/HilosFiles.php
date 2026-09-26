<?php

declare(strict_types=1);

namespace Hilos\Files;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Feature\Exception\FeatureNotDeclaredException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Files\DTO\FileBindSignalData;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\Library\AbstractFilesLibraryAgent;
use Hilos\Files\Storage\FilesStorageInterface;
use Hilos\Files\Upload\DTO\UploadPublishSignalData;
use Hilos\Hilos;

/**
 * HilosFiles - the project's door into the files registry (HIL-336).
 *
 * The facade global {@see Hilos::$files}. The registry is written in one process only, the one
 * of {@see AbstractFilesLibraryAgent}, so the door writes nothing: it sends a frame - straight
 * there to bind, or to the uploads agent to publish, which hands the files on.
 */
class HilosFiles
{
    /** @var string Address a registry file is served at, by the files library (HIL-138) */
    public const string DOWNLOAD_PATH = '/_hilos/file';

    /** @var string Query key carrying the id of the file served at {@see self::DOWNLOAD_PATH} */
    public const string DOWNLOAD_ID_KEY = 'id';

    /**
     * @param FilesStorageInterface $storage Where the registry's files are kept. It lives on the door so that
     *     every process reaching the registry sees the same storage; only {@see AbstractFilesLibraryAgent} writes it.
     */
    public function __construct(public readonly FilesStorageInterface $storage)
    {
    }

    /**
     * Builds the address a registry file is served at.
     *
     * Relative to the site, and the one place it is spelled: a project that hands a file's
     * address to the browser in its own frame builds it here rather than writing the path out.
     * Whether the browser gets the file is decided when it asks, by the row's visibility.
     *
     * @param int $fileId Id of the registry row
     * @return string Address of the file, with its id in the query
     */
    public static function downloadPath(int $fileId): string
    {
        return self::DOWNLOAD_PATH . HttpConstants::QUERY_STRING_SEPARATOR . http_build_query([self::DOWNLOAD_ID_KEY => $fileId]);
    }

    /**
     * Tells the files library that the project has linked these files to its own records.
     *
     * Call it after the project's own link is written. Fire-and-forget: nothing answers, and
     * with no signal router in the process (a CLI context) the frame reaches nobody. A frame
     * lost on the way costs no file - the janitor reads a foreign-key refusal on a project row
     * as the same fact - but a project without such a key has only this call to keep its files.
     *
     * @param list<int> $fileIds Ids of the registry rows the project linked
     * @throws FeatureNotDeclaredException When the project did not declare HilosFeature::FILES
     * @throws InvalidArgumentException When the bind signal cannot be named or queued
     */
    public function markBound(array $fileIds): void
    {
        if (!Hilos::hasFeature(HilosFeature::FILES)) {
            throw FeatureNotDeclaredException::forFeature(HilosFeature::FILES);
        }
        if ($fileIds === []) {
            return;
        }

        Hilos::$sr?->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            signalName: new SignalName(HilosSignalConstants::HILOS_FILE_BIND),
            signalData: new AgentSignalData(data: new FileBindSignalData($fileIds)),
        );
    }

    /**
     * Asks for complete uploads of one connection to be published into the registry.
     *
     * Fire-and-forget like {@see self::markBound()}, but answered: a
     * {@see FilesPublishedSignalData} comes back under `$replySignal`, carrying the ids of the
     * new rows in the order of `$clientUploadIds`, or the sentence of a refusal. All or nothing:
     * either every upload becomes an unbound row, or none does. The rows are born unbound - link
     * them to the project's records, then call {@see self::markBound()}.
     *
     * Both features are required: without HilosFeature::UPLOADS no agent would take the frame,
     * and the caller would wait for an answer that never comes.
     *
     * @param string $acceptKey Accept key of the connection the uploads belong to
     * @param string $target Upload target the uploads must have been declared for
     * @param list<string> $clientUploadIds Ids the client gave the uploads
     * @param FileVisibility $visibility Who may be given the published files
     * @param string $replySignal Name of the agent signal the answer comes under
     * @throws FeatureNotDeclaredException When the project did not declare HilosFeature::FILES or HilosFeature::UPLOADS
     * @throws InvalidFormatException When the request is malformed - no id, an id twice, an id the wire does not allow,
     *     an empty target or reply name
     * @throws InvalidArgumentException When the publish signal cannot be named or queued
     */
    public function publishUploads(
        string $acceptKey,
        string $target,
        array $clientUploadIds,
        FileVisibility $visibility,
        string $replySignal,
    ): void {
        if (!Hilos::hasFeature(HilosFeature::FILES)) {
            throw FeatureNotDeclaredException::forFeature(HilosFeature::FILES);
        }
        if (!Hilos::hasFeature(HilosFeature::UPLOADS)) {
            throw FeatureNotDeclaredException::forFeature(HilosFeature::UPLOADS);
        }

        $request = UploadPublishSignalData::fromArray([
            UploadPublishSignalData::acceptKey => $acceptKey,
            UploadPublishSignalData::target => $target,
            UploadPublishSignalData::clientUploadIds => $clientUploadIds,
            UploadPublishSignalData::visibility => $visibility->value,
            UploadPublishSignalData::replySignal => $replySignal,
        ]);

        Hilos::$sr?->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            signalName: new SignalName(HilosSignalConstants::HILOS_UPLOAD_PUBLISH),
            signalData: new AgentSignalData(data: $request),
        );
    }
}
