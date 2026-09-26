<?php

declare(strict_types=1);

namespace Hilos\Files\Library;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\SqlRuntime\ForeignKeyConstraintException;
use Hilos\Database\View\Item\File;
use Hilos\Database\View\Item\Session;
use Hilos\Files\DTO\FileBindSignalData;
use Hilos\Files\DTO\FilePublishItemData;
use Hilos\Files\DTO\FilePublishSignalData;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\Download\FileAccess;
use Hilos\Files\Download\FileDownloadOutcome;
use Hilos\Files\Download\FileDownloadResponse;
use Hilos\Files\FilesSettingsCatalog;
use Hilos\Files\FileVisibility;
use Hilos\Files\HilosFiles;
use Hilos\Files\Storage\FilesStorageInterface;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsFile;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Helpers\TimeHelper;
use Random\RandomException;

/**
 * The files library: the one owner of the files registry (HIL-336).
 *
 * An entity library in the sense of docs/agents/architecture/entity-libraries.md. What it owns is
 * the hilos_file table: every row is written here - created, marked bound, removed - and nowhere
 * else. A row is born by publication: the project asks {@see HilosFiles::publishUploads()}, the
 * uploads agent hands the files over with {@see HilosSignalConstants::HILOS_FILE_PUBLISH}, and
 * the library keeps each one in the storage and registers it, all or nothing (HIL-136). The
 * project then reaches it through {@see HilosFiles::markBound()}, which sends
 * {@see HilosSignalConstants::HILOS_FILE_BIND} here.
 *
 * It also keeps the registry clean. A row is born unbound, and a row nobody bound within the
 * `files.unbound_ttl_hours` setting is taken by the janitor: the row first, then the file of the
 * same name in the storage ({@see HilosFiles::$storage}; the local one is the files directory,
 * {@see FsContext::FILES}). Row first, because a file left behind costs disk space while a row
 * left behind points at nothing. A row whose removal the database refuses with a foreign key is
 * one the project linked without saying so - its bind frame was lost on the way - so the janitor
 * marks it bound instead and keeps the file.
 *
 * The janitor never walks the storage: files without a row are not its own, and in the chat
 * demo the files directory also holds the attachments published before the registry existed.
 *
 * And it serves the files: GET {@see HilosFiles::DOWNLOAD_PATH}?id=N is an address this agent
 * declares ({@see self::AGENT_HTTP_ROUTES}), so it is mounted on every project that declares
 * HilosFeature::FILES and answered here rather than in the master, which may not read the row or
 * the session (HIL-138). The row's visibility decides ({@see FileAccess}), the project's
 * {@see self::grantsRead()} may widen it, and {@see FileDownloadResponse} builds the answer.
 *
 * Abstract by convention, as the notifications library is. A project subclass adds its name, and
 * may override {@see self::grantsRead()} to let more viewers see a file than its visibility does.
 */
abstract class AbstractFilesLibraryAgent extends AbstractAgent
{
    /**
     * The files registry, claimed outright: no other agent writes it.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [
        HilosDbContext::files => TruthSourceOperation::BY_KIND,
    ];

    public const string AGENT_TYPE = HilosAgentType::HILOS_FILES_LIBRARY;

    /**
     * The two frames the library is addressed by: the project linked these files, and the
     * uploads agent handed these files over to be kept.
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_FILE_BIND => FileBindSignalData::class,
        HilosSignalConstants::HILOS_FILE_PUBLISH => FilePublishSignalData::class,
    ];

    /** The address a file is served at, answered here and not in the master (HIL-138). */
    public const array AGENT_HTTP_ROUTES = [
        HttpConstants::METHOD_GET => [HilosFiles::DOWNLOAD_PATH],
    ];

    /** Name of the cron rule that removes files nobody linked. */
    public const string FILES_SWEEP_RULE = 'hilos_files_sweep_unbound';

    /** Every 15 minutes, as the session sweep. */
    public const string FILES_SWEEP_CRON = '*/15 * * * *';

    /** Maximum unbound rows considered by one tick. */
    public const int FILES_SWEEP_BATCH = 100;

    /**
     * Random bytes of a stored name: 32 hex characters, before the extension of the type. Drawn
     * from the secure axis (docs/agents/code-style/random-source.md): the name is the handle of a
     * file whose visibility may be its owner alone, and nobody should be able to guess it.
     */
    private const int STORED_NAME_BYTES = 16;

    private const string MESSAGE_CANNOT_KEEP = 'Cannot keep the file';

    /** @var ?CronRule Schedule of the unbound-file sweep */
    private ?CronRule $filesSweepRule = null;

    /** Whether a full sweep batch that did something asks the next tick to continue immediately */
    private bool $filesSweepBacklog = false;

    /**
     * Arms the unbound-file sweep.
     */
    public function onStart(): void
    {
        $this->filesSweepRule = new CronRule(self::FILES_SWEEP_RULE, self::FILES_SWEEP_CRON);
    }

    /**
     * Runs the sweep when it is due, or at once while a full batch left more behind.
     *
     * @throws HilosException On database or settings failure
     */
    public function onTick(): void
    {
        if (!$this->filesSweepBacklog && $this->filesSweepRule?->shouldRun() !== true) {
            return;
        }

        $this->sweepUnboundFiles();
    }

    /**
     * The library holds nothing across a stop: its state is the registry table, which outlives
     * the process that owns it.
     */
    public function onStop(): void
    {
    }

    /**
     * Marks bound the files the project says it linked, or keeps and registers the files the
     * uploads agent handed over.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one this library declares
     * @throws InvalidAgentSignalPayloadException When the payload is not the one its name promises
     * @throws HilosException When a row cannot be read or written
     * @throws InvalidArgumentException When the answer to a publication cannot be named
     * @throws RandomException When the stored names of a publication cannot be drawn
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_FILE_BIND:
                if (!$data->data instanceof FileBindSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, FileBindSignalData::class, $data->data);
                }
                $this->markBound($data->data->fileIds);

                return;

            case HilosSignalConstants::HILOS_FILE_PUBLISH:
                if (!$data->data instanceof FilePublishSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, FilePublishSignalData::class, $data->data);
                }
                $this->publish($data->data);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Serves a registry file by id to the browser that asked, or refuses it.
     *
     * @param HttpRequestDTO $data Request the master parked for this address
     * @param string $source Signal source (unused)
     * @param string $name Signal name, the method and the path (unused)
     * @throws HilosException When the row, the session or the environment cannot be read
     * @throws InvalidArgumentException When the reply cannot be named
     */
    public function onSignalHttpRequest(HttpRequestDTO $data, string $source, string $name): void
    {
        $this->replyToHttpRequest($this->serveFile($data));
    }

    /**
     * Widens who may see a file beyond what its visibility allows; the project's extension point.
     *
     * Asked only when the visibility refused, so it can only let a viewer in, never shut one out.
     * The default lets nobody in: an extension a project forgot leaves a file to its owner rather
     * than opening it to everyone signed in. Override it in the project's subclass of this
     * library to let in a group, a role, the members of a room - or a guest.
     *
     * @param File $file Registry row of the file asked for
     * @param ?Session $session Session the request presented - a guest's, one signed in or one
     *     expired - or null when it presented none
     * @return bool True to serve the file although its visibility refused it
     * @throws HilosException Whatever the project's check raises while reading its own records
     */
    protected function grantsRead(File $file, ?Session $session): bool
    {
        return false;
    }

    /**
     * Decides what a request for a file is answered, and says in the journal what went wrong.
     *
     * No broad catch stands over the reads of the database (docs/agents/code-style/wiring-refusals.md):
     * a registry this agent cannot read is a wiring defect, not a 404.
     *
     * @param HttpRequestDTO $request Request for a file
     * @return HttpReplyDTO The file, or the refusal
     * @throws HilosException When the row, the session or the environment cannot be read
     */
    private function serveFile(HttpRequestDTO $request): HttpReplyDTO
    {
        $rawId = $request->query[HilosFiles::DOWNLOAD_ID_KEY] ?? null;
        if ($rawId === null || !ctype_digit($rawId) || (int)$rawId <= 0) {
            return HttpReplyDTO::refusal($request, HttpConstants::HTTP_NOT_FOUND);
        }

        $fileId = (int)$rawId;
        $file = Hilos::$db->files[$fileId] ?? null;
        if ($file === null) {
            return HttpReplyDTO::refusal($request, HttpConstants::HTTP_NOT_FOUND);
        }

        $session = $request->sessionToken !== null ? Hilos::$db->sessions->findByToken($request->sessionToken) : null;
        $access = FileAccess::judge(
            $file->visibility,
            $file->ownerUserId,
            FileAccess::signedInUserId($session, TimeHelper::getSqlDateTime()),
        );
        if ($access !== FileAccess::ALLOW && $this->grantsRead($file, $session)) {
            $access = FileAccess::ALLOW;
        }
        if ($access === FileAccess::SIGN_IN) {
            return HttpReplyDTO::refusal($request, HttpConstants::HTTP_UNAUTHORIZED);
        }
        if ($access === FileAccess::FORBIDDEN) {
            return HttpReplyDTO::refusal($request, HttpConstants::HTTP_FORBIDDEN);
        }

        try {
            $response = FileDownloadResponse::forFile(
                $request,
                $file,
                $this->storage(),
                Hilos::$env[EnvConstants::HILOS_FILES_XACCEL_LOCATION]->string(),
            );
        } catch (FsException $e) {
            $this->logAgentError("File {$fileId} cannot be served: the storage is not reachable - {$e->getMessage()}");

            return HttpReplyDTO::refusal($request, HttpConstants::HTTP_INTERNAL_ERROR);
        }

        match ($response->outcome) {
            FileDownloadOutcome::SERVED => null,
            FileDownloadOutcome::MISSING_ON_DISK => $this->logAgentWarning("File {$fileId} has a row but no file on disk"),
            FileDownloadOutcome::TOO_LARGE_TO_SEND_DIRECTLY => $this->logAgentError(
                "File {$fileId} is {$response->size} bytes, above the " . FileDownloadResponse::DIRECT_MAX_BYTES
                . ' the daemon serves itself; set ' . EnvConstants::HILOS_FILES_XACCEL_LOCATION->name,
            ),
            FileDownloadOutcome::UNREADABLE => $this->logAgentError("File {$fileId} is on disk and could not be read"),
        };

        return $response->reply;
    }

    /**
     * Marks each named row bound; a row the registry does not hold is reported and skipped.
     *
     * @param list<int> $fileIds Ids of the registry rows the project linked
     * @throws HilosException When a row cannot be read or written
     */
    private function markBound(array $fileIds): void
    {
        foreach ($fileIds as $fileId) {
            $file = Hilos::$db->files[$fileId];
            if ($file === null) {
                $this->logAgentWarning("File {$fileId} is not in the registry");
                continue;
            }

            $file->actions->markBound();
        }
    }

    /**
     * Keeps each handed-over file under a random name and registers it unbound, or undoes the
     * whole request.
     *
     * The stored names are drawn first, so nothing is moved before the request is known to be
     * doable. A failure on one file - the storage did not keep it, the row was not written, a
     * field was refused - undoes the request at once: the rows it wrote go with their files, and
     * every temporary file still waiting is deleted. Not left to the janitor: the asker is told
     * no and will link nothing, and an unbound row would hold its place in the storage limit for
     * a day. Only those three failures are caught; anything else - a collection the process may
     * not read, an ownership refusal - leaves the handler (docs/agents/code-style/wiring-refusals.md).
     *
     * @param FilePublishSignalData $request Files handed over by the uploads agent
     * @throws HilosException When a row of the registry cannot be read or written past the three failures caught
     * @throws InvalidArgumentException When the answer cannot be named
     * @throws RandomException When the stored names cannot be drawn
     */
    private function publish(FilePublishSignalData $request): void
    {
        $storedNames = [];
        foreach ($request->files as $item) {
            $storedNames[] = RandomHelper::secureHex(self::STORED_NAME_BYTES) . FsFile::extensionForMime($item->mimeType);
        }

        $storage = $this->storage();
        $visibility = FileVisibility::from($request->visibility);
        $published = [];
        foreach ($request->files as $index => $item) {
            try {
                $storage->storeFromTmp($storedNames[$index], $item->tmpIndex);
                $published[] = Hilos::$db->files->actions->create(
                    $storedNames[$index],
                    $item->filename,
                    $item->mimeType,
                    $item->size,
                    $item->contentHash,
                    $item->ownerUserId,
                    $visibility,
                );
            } catch (FsException | DatabaseException | ValidationException $failure) {
                $this->logAgentError(
                    "Cannot publish upload {$request->clientUploadIds[$index]} of {$request->acceptKey}: {$failure->getMessage()}",
                );
                $this->undoPublish($published, $storedNames[$index], array_slice($request->files, $index));
                $this->sendToAgent(
                    $request->replySignal,
                    FilesPublishedSignalData::refused($request->acceptKey, $request->clientUploadIds, self::MESSAGE_CANNOT_KEEP),
                );

                return;
            }
        }

        $fileIds = [];
        foreach ($published as $file) {
            $fileIds[] = $file->id ?? throw new LogicException('A registered file has no id');
        }
        $this->sendToAgent($request->replySignal, FilesPublishedSignalData::published($request, $fileIds));
    }

    /**
     * Undoes a publication that failed on one file; a step that fails is reported and the undo
     * goes on.
     *
     * @param list<File> $published Rows the request wrote before the failure, each with its kept file
     * @param string $failedStoredName Stored name of the file that failed, kept or not
     * @param list<FilePublishItemData> $waiting The file that failed and every file after it
     */
    private function undoPublish(array $published, string $failedStoredName, array $waiting): void
    {
        foreach ($published as $file) {
            $storedName = $file->storedName;
            try {
                $file->actions->delete();
            } catch (DatabaseException $e) {
                $this->logAgentError("Cannot undo the row of {$storedName}: {$e->getMessage()}");
            }
            $this->deleteKept($storedName);
        }

        $this->deleteKept($failedStoredName);
        foreach ($waiting as $item) {
            try {
                (Hilos::$fs ?? throw new DirectoryNotFoundException('FS context is not configured'))->getTmp()[$item->tmpIndex]->unlink();
            } catch (FsException $e) {
                $this->logAgentError("Cannot delete the temporary file {$item->tmpIndex}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Deletes a kept file while undoing a publication; a file that stays is reported.
     *
     * @param string $storedName Stored name of the file
     */
    private function deleteKept(string $storedName): void
    {
        try {
            $this->storage()->delete($storedName);
        } catch (FsException $e) {
            $this->logAgentError("Cannot undo the file {$storedName}: {$e->getMessage()}");
        }
    }

    /**
     * Removes one bounded batch of files nobody linked within the configured lifetime.
     *
     * Two refusals are expected and caught, each narrowly: a foreign key on the row, which is the
     * database saying the project did link the file, and a file that stays on disk, which is
     * reported and not undone. Anything else - the database, the setting - leaves the tick, as the
     * session sweep's failures do (docs/agents/code-style/wiring-refusals.md).
     *
     * @throws HilosException On database or settings failure
     */
    private function sweepUnboundFiles(): void
    {
        $this->filesSweepBacklog = false;

        $ttlHours = Hilos::$setting[FilesSettingsCatalog::UNBOUND_TTL_HOURS_KEY]->int();
        if ($ttlHours <= 0) {
            return;
        }

        $cutoff = date('Y-m-d H:i:s', time() - $ttlHours * TimeConstants::SECONDS_PER_HOUR);
        $files = Hilos::$db->files->findUnboundBefore($cutoff, self::FILES_SWEEP_BATCH);
        $removed = 0;
        $marked = 0;

        foreach ($files as $file) {
            $fileId = $file->id;
            $storedName = $file->storedName;

            try {
                $file->actions->delete();
            } catch (ForeignKeyConstraintException) {
                $file->actions->markBound();
                $this->logAgentWarning("File {$fileId} is referenced by a project row; marked bound");
                $marked++;
                continue;
            }
            $removed++;

            try {
                $this->storage()->delete($storedName);
            } catch (FileDeleteException $e) {
                $this->logAgentError("Orphan file {$storedName} left on disk: " . $e->getMessage());
            }
        }

        $done = $removed + $marked;
        $this->filesSweepBacklog = count($files) === self::FILES_SWEEP_BATCH && $done > 0;

        if ($done > 0) {
            $this->logAgentInfo("Files sweep: removed {$removed} unbound, marked {$marked} referenced");
        }
    }

    /**
     * @return FilesStorageInterface Storage the registry keeps its files in
     * @throws LogicException When the facade holds no files door, which a started daemon always has
     */
    private function storage(): FilesStorageInterface
    {
        return (Hilos::$files ?? throw new LogicException('The files door is not created'))->storage;
    }
}
