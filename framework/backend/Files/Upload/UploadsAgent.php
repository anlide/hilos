<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use finfo;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\Exception\ActionUnauthorizedException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Files\Upload\Check\AllowedContentCheck;
use Hilos\Files\Upload\Check\DeclaredMimeCheck;
use Hilos\Files\Upload\Check\SizeLimitCheck;
use Hilos\Files\Upload\DTO\UploadCancelActionDTO;
use Hilos\Files\Upload\DTO\UploadInitActionDTO;
use Hilos\Files\Upload\DTO\UploadStateSignalData;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Fs\FsTmpDirectory;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosUpload as StateHilosUpload;
use Hilos\Runtime\View\Item\HilosUpload;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;

/**
 * The one writer of the uploads: accepts declarations, receives signed chunks, checks, cleans up
 * (HIL-135).
 *
 * An upload lives on the connection, so this agent - not a page - answers the two upload actions
 * and receives every frame_binary chunk of a project that declares HilosFeature::UPLOADS,
 * whatever page is open. It is the only truth source of the uploads collection; every other
 * process reads its replica, and a consumer takes a complete upload's file from there.
 *
 * The row is written on a change of phase and at most once per
 * {@see self::PROGRESS_MIN_INTERVAL_SECONDS} while chunks arrive: the collection is held in
 * every process, and a write per chunk would be a sync per chunk to all of them. The exact byte
 * count between two writes is kept here, in memory, and dies with the rows when the agent
 * starts or stops.
 */
final class UploadsAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_UPLOADS;

    /**
     * @var array<string, list<TruthSourceOperation>> The uploads, which this agent alone writes:
     *     every other process holds a replica for the consumers that take received files.
     */
    public const array OWNS_RT = [StateHilosUpload::RT_COLLECTION => TruthSourceOperation::BY_KIND];

    /**
     * The two upload actions, owned by the agent rather than by a page: an upload outlives the
     * navigation that started it. Neither is in AUTH_ACTIONS - whether sign-in is required is
     * the target's answer, not the action's.
     */
    public const array AGENT_ACTIONS = [
        HilosSignalConstants::HILOS_UPLOAD_INIT => UploadInitActionDTO::class,
        HilosSignalConstants::HILOS_UPLOAD_CANCEL => UploadCancelActionDTO::class,
    ];

    /** Uploads one connection may hold a file for at once, while nothing else limits the disk. */
    public const int MAX_OPEN_UPLOADS_PER_CONNECTION = 16;

    /** Shortest time between two progress writes of one upload; a change of phase is never held back. */
    public const float PROGRESS_MIN_INTERVAL_SECONDS = 0.3;

    /** How long an upload may go unchanged before it is dropped with its file. */
    public const int UPLOAD_TTL_SECONDS = 3600;

    /** Shortest time between two sweeps of the uploads, so the tick stays cheap. */
    private const float SWEEP_INTERVAL_SECONDS = 1.0;

    /** Bytes of the head of a received file its type is read from - the amount libmagic reads itself. */
    private const int SNIFF_HEAD_BYTES = 1048576;

    private const string MESSAGE_NOT_ACCEPTED = 'This upload is not accepted here';

    private const string MESSAGE_IN_PROGRESS = 'This upload is already in progress';

    private const string MESSAGE_TOO_MANY = 'Too many uploads at once';

    private const string MESSAGE_CANNOT_START = 'Cannot start upload';

    private const string MESSAGE_OVERFLOW = 'Uploaded data exceeds declared size';

    private const string MESSAGE_WRITE_ERROR = 'Cannot store upload data';

    private const string MESSAGE_CANNOT_FINISH = 'Cannot finish upload';

    /** @var array<string, AbstractUploadTarget> Targets by name, created once on start */
    private array $targets = [];

    /** @var array<string, list<UploadCheckInterface>> Checks of each target, in the order they run */
    private array $checks = [];

    /** @var array<string, int> Exact bytes received by upload row id */
    private array $receivedBytes = [];

    /** @var array<string, float> Moment of the last write of each upload row, by row id */
    private array $writtenAt = [];

    /** Moment of the last sweep. */
    private float $lastSweepAt = 0.0;

    /**
     * Creates the targets and drops whatever a predecessor left: its chunks will not continue.
     *
     * @throws HilosException Whatever wiping the collection and its files raises
     */
    public function onStart(): void
    {
        foreach (Hilos::appClass()::UPLOAD_TARGETS as $name => $class) {
            $target = new $class();
            $this->targets[$name] = $target;
            $this->checks[$name] = $this->checksOf($target);
        }

        $this->lastSweepAt = microtime(true);
        Hilos::$rt->hilosUploads->actions->clearAllWithFiles();
    }

    /**
     * Answers a declaration or a cancel.
     *
     * @param string $acceptKey Acting connection accept key
     * @param string $action Owned action name from AGENT_ACTIONS
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return null Both actions answer with nothing: success is the whole answer
     * @throws AgentUnknownActionException When the action is not one of the two
     * @throws ValidationException When the declaration is refused, with the sentence the person reads
     * @throws ActionUnauthorizedException When the target requires sign-in and the connection is anonymous
     * @throws HilosException Whatever writing the upload row or deleting a replaced file raises
     */
    public function onAgentAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        if ($dto instanceof UploadInitActionDTO) {
            $this->declare($acceptKey, $dto);

            return null;
        }

        if ($dto instanceof UploadCancelActionDTO) {
            $this->cancel($acceptKey, $dto);

            return null;
        }

        throw new AgentUnknownActionException($action);
    }

    /**
     * Receives one signed chunk.
     *
     * A frame that names no upload of its connection - no signature, an unknown id, an upload
     * that no longer receives - is dropped with a debug line: it creates no row, or a client
     * sending garbage would create rows by the frame.
     *
     * @param WebSocketFrameBinarySignalDTO $data Binary frame of one connection
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused)
     * @throws HilosException Whatever writing the upload row or deleting a failed file raises
     */
    public function onSignalFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void
    {
        $frame = UploadFrame::parse($data->payload);
        if ($frame === null) {
            $this->logAgentDebug("Dropped a binary frame of {$data->acceptKey}: it carries no upload signature");

            return;
        }

        $upload = Hilos::$rt->hilosUploads->find($data->acceptKey, $frame->clientUploadId);
        if ($upload === null || !$upload->phase->isReceiving()) {
            $this->logAgentDebug("Dropped a chunk of {$data->acceptKey} for upload {$frame->clientUploadId}: it is not receiving");

            return;
        }

        if ($frame->bytes === '') {
            return;
        }

        $this->receive($upload, $frame->bytes);
    }

    /**
     * Drops the uploads whose connection is gone, silently, and those unchanged for an hour.
     *
     * An upload that cannot be dropped - its file will not delete - is logged and tried again on
     * the next sweep; one bad file does not stop the others from being dropped.
     *
     * @throws InvalidArgumentException When the state frame cannot be named
     */
    public function onTick(): void
    {
        $now = microtime(true);
        if ($now - $this->lastSweepAt < self::SWEEP_INTERVAL_SECONDS) {
            return;
        }
        $this->lastSweepAt = $now;

        $connections = Hilos::$rt->connectionsSource();
        $expiredBefore = time() - self::UPLOAD_TTL_SECONDS;
        foreach (iterator_to_array(Hilos::$rt->hilosUploads, false) as $upload) {
            $connectionGone = $connections !== null && $connections->get($upload->acceptKey) === null;
            if (!$connectionGone && $upload->updatedAt >= $expiredBefore) {
                continue;
            }

            try {
                $this->forget($upload);
            } catch (HilosException $unremovable) {
                $this->logAgentError("Cannot drop upload {$upload->clientUploadId} of {$upload->acceptKey}: {$unremovable->getMessage()}");

                continue;
            }

            if (!$connectionGone) {
                $this->sendState($upload->acceptKey, UploadStateSignalData::gone($upload->clientUploadId));
            }
        }
    }

    /**
     * Drops every upload with its file and tells each connection still here that its upload is gone.
     *
     * @throws HilosException Whatever wiping the collection and its files raises
     * @throws InvalidArgumentException When the state frame cannot be named
     */
    public function onStop(): void
    {
        $connections = Hilos::$rt->connectionsSource();
        foreach (Hilos::$rt->hilosUploads->actions->clearAllWithFiles() as $upload) {
            if ($connections === null || $connections->get($upload->acceptKey) !== null) {
                $this->sendState($upload->acceptKey, UploadStateSignalData::gone($upload->clientUploadId));
            }
        }

        $this->receivedBytes = [];
        $this->writtenAt = [];
    }

    /**
     * Accepts or refuses one declaration, in the order the refusals are specified.
     *
     * @param string $acceptKey Acting connection accept key
     * @param UploadInitActionDTO $dto Declaration, already in its checked form
     * @throws ValidationException When the declaration is refused
     * @throws ActionUnauthorizedException When the target requires sign-in and the connection is anonymous
     * @throws HilosException Whatever writing the upload row or deleting a replaced file raises
     */
    private function declare(string $acceptKey, UploadInitActionDTO $dto): void
    {
        $target = $this->targets[$dto->target] ?? throw new ValidationException(self::MESSAGE_NOT_ACCEPTED);

        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($target->requiresSignIn()) {
            ActionUnauthorizedException::requireUser($userId);
        }

        $uploads = Hilos::$rt->hilosUploads;
        $existing = $uploads->find($acceptKey, $dto->clientUploadId);
        if ($existing !== null) {
            if ($existing->phase->isReceiving()) {
                throw new ValidationException(self::MESSAGE_IN_PROGRESS);
            }
            $this->forget($existing);
        }

        if ($uploads->countHoldingFiles($acceptKey) >= self::MAX_OPEN_UPLOADS_PER_CONNECTION) {
            throw new ValidationException(self::MESSAGE_TOO_MANY);
        }

        $declaration = new UploadDeclaration(
            acceptKey: $acceptKey,
            clientUploadId: $dto->clientUploadId,
            target: $dto->target,
            userId: $userId,
            filename: $dto->filename,
            mimeType: UploadMime::normalize($dto->mimeType),
            size: $dto->size,
        );
        foreach ($this->checks[$dto->target] as $check) {
            $refusal = $check->checkDeclared($declaration);
            if ($refusal !== null) {
                throw new ValidationException($refusal->message);
            }
        }

        try {
            $tmpIndex = $this->tmp()->create();
        } catch (FsException $unwritable) {
            $this->logAgentError("Cannot create the temporary file of upload {$dto->clientUploadId}: {$unwritable->getMessage()}");

            throw new ValidationException(self::MESSAGE_CANNOT_START);
        }

        $upload = $uploads->actions->open(
            $acceptKey,
            $dto->clientUploadId,
            $dto->target,
            $userId,
            $declaration->filename,
            $declaration->mimeType,
            $declaration->size,
            $tmpIndex,
        );
        $key = StateHilosUpload::keyFor($acceptKey, $dto->clientUploadId);
        $this->receivedBytes[$key] = 0;
        $this->writtenAt[$key] = microtime(true);

        $this->sendState($acceptKey, UploadStateSignalData::fromUpload($upload));
    }

    /**
     * Drops one upload of the connection, if it still has it; a missing one is not an error.
     *
     * @param string $acceptKey Acting connection accept key
     * @param UploadCancelActionDTO $dto Cancel of one upload
     * @throws HilosException Whatever removing the upload row or deleting its file raises
     */
    private function cancel(string $acceptKey, UploadCancelActionDTO $dto): void
    {
        $upload = Hilos::$rt->hilosUploads->find($acceptKey, $dto->clientUploadId);
        if ($upload === null) {
            return;
        }

        $this->forget($upload);
        $this->sendState($acceptKey, UploadStateSignalData::gone($dto->clientUploadId));
    }

    /**
     * Appends one chunk and moves the upload on: failed, complete, or progress when it is due.
     *
     * @param HilosUpload $upload Upload that is receiving
     * @param string $bytes Non-empty chunk bytes
     * @throws HilosException Whatever writing the upload row or deleting its file raises
     */
    private function receive(HilosUpload $upload, string $bytes): void
    {
        $key = StateHilosUpload::keyFor($upload->acceptKey, $upload->clientUploadId);
        $received = ($this->receivedBytes[$key] ?? $upload->receivedBytes) + strlen($bytes);
        if ($received > $upload->declaredSize) {
            $this->fail($upload, UploadFailureCode::SIZE_OVERFLOW, self::MESSAGE_OVERFLOW);

            return;
        }

        try {
            $this->tmp()[$upload->tmpIndex ?? throw new DirectoryNotFoundException('The upload has no temporary file')]
                ->append($bytes);
        } catch (FsException $unwritable) {
            $this->logAgentError("Cannot append to upload {$upload->clientUploadId} of {$upload->acceptKey}: {$unwritable->getMessage()}");
            $this->fail($upload, UploadFailureCode::WRITE_ERROR, self::MESSAGE_WRITE_ERROR);

            return;
        }
        $this->receivedBytes[$key] = $received;

        if ($received === $upload->declaredSize) {
            $this->finish($upload);

            return;
        }

        $now = microtime(true);
        if ($upload->phase === UploadPhase::READY || $now - ($this->writtenAt[$key] ?? 0.0) >= self::PROGRESS_MIN_INTERVAL_SECONDS) {
            $upload->actions->advance($received);
            $this->writtenAt[$key] = $now;
            $this->sendState($upload->acceptKey, UploadStateSignalData::fromUpload($upload));
        }
    }

    /**
     * Judges the whole received file and completes or fails the upload.
     *
     * The sniffed type is written onto the row before the checks run, so every check - a
     * project's included - reads it there; the row does not become complete until they all let
     * it through.
     *
     * @param HilosUpload $upload Upload whose declared bytes have all arrived
     * @throws HilosException Whatever writing the upload row or deleting its file raises
     */
    private function finish(HilosUpload $upload): void
    {
        if ($this->targets[$upload->target]->sniffsContent()) {
            $detected = $this->sniff($upload);
            if ($detected === null) {
                $this->fail($upload, UploadFailureCode::STORAGE_ERROR, self::MESSAGE_CANNOT_FINISH);

                return;
            }
            $upload->actions->detect($detected);
        }

        foreach ($this->checks[$upload->target] as $check) {
            $refusal = $check->checkReceived($upload);
            if ($refusal !== null) {
                $this->fail($upload, $refusal->code, $refusal->message);

                return;
            }
        }

        $upload->actions->complete();
        $this->forgetMemory($upload);
        $this->sendState($upload->acceptKey, UploadStateSignalData::fromUpload($upload));
    }

    /**
     * Reads the type of a received file from the head of its content.
     *
     * @param HilosUpload $upload Upload whose file is whole
     * @return ?string Detected type, or null when the file could not be read
     */
    private function sniff(HilosUpload $upload): ?string
    {
        try {
            $path = $this->tmp()[$upload->tmpIndex ?? throw new DirectoryNotFoundException('The upload has no temporary file')]
                ->getPath();
            $head = FsPath::readWith($path, static fn($handle): string|false => fread($handle, self::SNIFF_HEAD_BYTES));
        } catch (FsException $unreadable) {
            $this->logAgentError("Cannot read upload {$upload->clientUploadId} of {$upload->acceptKey}: {$unreadable->getMessage()}");

            return null;
        }

        if ($head === false) {
            $this->logAgentError("Cannot read upload {$upload->clientUploadId} of {$upload->acceptKey}");

            return null;
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($head);

        return $detected === false ? null : $detected;
    }

    /**
     * Fails the upload, deleting its file, and tells its connection.
     *
     * @param HilosUpload $upload Upload that fails
     * @param string $code Stable error code
     * @param string $message Sentence shown to the person
     * @throws HilosException Whatever writing the upload row or deleting its file raises
     */
    private function fail(HilosUpload $upload, string $code, string $message): void
    {
        $upload->actions->fail($code, $message);
        $this->forgetMemory($upload);
        $this->sendState($upload->acceptKey, UploadStateSignalData::fromUpload($upload));
    }

    /**
     * Removes one upload with its file and what this agent remembers about it.
     *
     * @param HilosUpload $upload Upload to drop
     * @throws HilosException Whatever removing the upload row or deleting its file raises
     */
    private function forget(HilosUpload $upload): void
    {
        $upload->actions->forgetWithFile();
        $this->forgetMemory($upload);
    }

    /**
     * @param HilosUpload $upload Upload that no longer receives
     */
    private function forgetMemory(HilosUpload $upload): void
    {
        $key = StateHilosUpload::keyFor($upload->acceptKey, $upload->clientUploadId);
        unset($this->receivedBytes[$key], $this->writtenAt[$key]);
    }

    /**
     * Sends one connection the state of one of its uploads.
     *
     * @param string $acceptKey Connection the upload belongs to
     * @param UploadStateSignalData $state Whole state, or the gone frame
     * @throws InvalidArgumentException When the state frame cannot be named
     */
    private function sendState(string $acceptKey, UploadStateSignalData $state): void
    {
        $this->sendToUser(HilosSignalConstants::HILOS_UPLOAD_STATE, $acceptKey, $state);
    }

    /**
     * @return FsTmpDirectory The tmp directory the chunks are kept in
     * @throws DirectoryNotFoundException When the project configures no FS context or no tmp directory
     */
    private function tmp(): FsTmpDirectory
    {
        return (Hilos::$fs ?? throw new DirectoryNotFoundException('FS context is not configured'))->getTmp();
    }

    /**
     * Lists the checks of one target in the order they run: size, declared type, content, the project's.
     *
     * @param AbstractUploadTarget $target Target to check for
     * @return list<UploadCheckInterface> Its checks
     */
    private function checksOf(AbstractUploadTarget $target): array
    {
        $checks = [
            new SizeLimitCheck($target->maxBytes()),
            new DeclaredMimeCheck($target->acceptedMimeTypes()),
        ];
        if ($target->sniffsContent() && $target->acceptedMimeTypes() !== []) {
            $checks[] = new AllowedContentCheck($target->acceptedMimeTypes());
        }

        return [...$checks, ...$target->extraChecks()];
    }
}
