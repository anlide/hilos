<?php

declare(strict_types=1);

namespace Hilos\DataExport;

use Hilos\Socket\Http\DTO\HttpRequestDTO;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\EnvConstants;
use Hilos\DataExport\DTO\DataExportOrderActionDTO;
use Hilos\DataExport\DTO\DataExportForgetUserSignalData;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\StepUp\StepUpGate;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Exception\NotImplementedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\View\Item\DataExport;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\FsDirectory;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Helpers\TimeHelper;
use Random\RandomException;
use Hilos\WiringRefusal;

/**
 * Cluster-wide owner of personal data copies (HIL-303).
 * Assembly is deliberately one blocking tick in a monopolistic worker.
 */
abstract class AbstractDataExportAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_DATA_EXPORT;
    public const array OWNS_DB = [HilosDbContext::dataExports => TruthSourceOperation::ALL];
    public const array READS_DB = [
        HilosDbContext::identities,
        HilosDbContext::passkeyCredentials,
        HilosDbContext::sessions,
        HilosDbContext::secondFactors,
        HilosDbContext::secondFactorBackupCodes,
        HilosDbContext::notifications,
        HilosDbContext::notificationPreferences,
        HilosDbContext::pushSubscriptions,
        HilosDbContext::accountDeletions,
        HilosDbContext::stepUps,
    ];

    public const array AGENT_ACTIONS = [HilosSignalConstants::HILOS_DATA_EXPORT_ORDER => DataExportOrderActionDTO::class];
    public const array AGENT_SIGNALS = [HilosSignalConstants::HILOS_DATA_EXPORT_FORGET_USER => DataExportForgetUserSignalData::class];

    public const array AGENT_HTTP_ROUTES = [HttpConstants::METHOD_GET => [DataExportHttp::DOWNLOAD_PATH]];

    protected const int LIFETIME_DAYS = 7;

    private const int STORED_NAME_BYTES = 16;
    private const string ZIP_EXTENSION = '.zip';
    private const string BUILDING_EXTENSION = '.building.zip';
    private const string EXPIRE_RULE = 'hilos_data_export_expire';
    private const string EXPIRE_CRON = '0 * * * *';

    private ?FsDirectory $directory = null;
    private ?CronRule $expireRule = null;

    /** The worker flushes action replies after ticks, so a new order must yield that first turn. */
    private bool $orderReplyPending = false;

    /**
     * Reconciles persisted requests with storage before resuming unfinished copies.
     *
     * @throws HilosException When storage, reconciliation or publication fails
     */
    public function onStart(): void
    {
        $this->directory = Hilos::$fs->getDirectory(FsContext::DATA_EXPORT);
        $this->directory->ensureDirectory();
        $this->expireRule = new CronRule(self::EXPIRE_RULE, self::EXPIRE_CRON);
        foreach (Hilos::$db->dataExports->allReady() as $export) {
            if ($export->storedName === null || !$this->directory[$export->storedName]->exists()) {
                $this->removeExport($export);
                $this->publishState($export->userId);
            }
        }
        $this->expireCopies();
    }

    /**
     * Lets a new order reply leave the worker, then sweeps and builds the earliest request in one tick.
     *
     * @throws HilosException When the queue, sweep or state publication fails
     */
    public function onTick(): void
    {
        if ($this->directory === null) {
            return;
        }
        if ($this->orderReplyPending) {
            $this->orderReplyPending = false;

            return;
        }
        if ($this->expireRule?->shouldRun() === true) {
            $this->expireCopies();
        }
        $this->buildNext();
    }

    /**
     * @param string $acceptKey Browser ordering the copy
     * @param string $action Declared action name
     * @param ActionPayloadDTO $dto Parsed empty order payload
     * @return ?ActionReplyDTO Empty success acknowledgement
     * @throws HilosException When the action, proof, person or queue write is refused
     */
    public function onAgentAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_DATA_EXPORT_ORDER:
                if (!$dto instanceof DataExportOrderActionDTO) {
                    throw new InvalidActionPayloadException($action, DataExportOrderActionDTO::class, $dto);
                }
                $this->order($acceptKey);

                return null;

            default:
                throw new AgentUnknownActionException($action);
        }
    }

    /**
     * @param AgentSignalData $data Typed erasure frame
     * @param string $sender Sending agent identity
     * @param string $name Declared signal name
     * @throws HilosException When the frame is unknown, malformed, or the copy cannot be removed
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_DATA_EXPORT_FORGET_USER:
                if (!$data->data instanceof DataExportForgetUserSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, DataExportForgetUserSignalData::class, $data->data);
                }
                $this->forgetUser($data->data->userId);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * @param HttpRequestDTO $data Request whose session selects the copy
     * @param string $source Request source, unused
     * @param string $name Declared method and path, unused
     * @throws HilosException When the session, export or reply cannot be read or sent
     */
    public function onSignalHttpRequest(HttpRequestDTO $data, string $source, string $name): void
    {
        $this->replyToHttpRequest($this->serveCopy($data));
    }

    /** Leaves durable requests for the next holder; no archive stays open between ticks. */
    public function onStop(): void
    {
        $this->directory = null;
        $this->expireRule = null;
        $this->orderReplyPending = false;
    }

    /**
     * The project must write its person's row and all content belonging to that person.
     * Exclude other people's records; copy attachments only through the writer's file() seam.
     *
     * @param int $userId Person whose project records must be exported
     * @param DataExportWriter $writer Archive serialization boundary
     * @throws HilosException When the project has no implementation or cannot export its records
     */
    protected function applyAccountExport(int $userId, DataExportWriter $writer): void
    {
        throw new NotImplementedException('Account export is not wired in this project');
    }

    /**
     * @param int $userId Person whose new state is fanned to their group
     * @throws HilosException When the state cannot be read or the signal cannot be named
     */
    protected function publishState(int $userId): void
    {
        $this->sendToGroup(
            HilosSignalConstants::HILOS_DATA_EXPORT_STATE,
            DataExportGroup::forUser($userId),
            DataExportStateProjector::stateFor($userId),
        );
    }

    /**
     * @param HttpRequestDTO $request Request carrying a cookie/header session, never a URL credential
     * @return HttpReplyDTO This session's archive or a refusal without caching
     * @throws HilosException When a required database source or environment value cannot be read
     */
    private function serveCopy(HttpRequestDTO $request): HttpReplyDTO
    {
        $session = $request->sessionToken === null ? null : Hilos::$db->sessions->findByToken($request->sessionToken);
        if ($session === null || ($session->expiresAt !== null && $session->expiresAt <= TimeHelper::getSqlDateTime())) {
            return HttpReplyDTO::refusal($request, HttpConstants::HTTP_UNAUTHORIZED);
        }
        if ($session->impersonatorUserId !== null) {
            return HttpReplyDTO::refusal($request, HttpConstants::HTTP_FORBIDDEN);
        }
        $person = $session->userId ?? $session->blockedUserId;
        if ($person === null) {
            return HttpReplyDTO::refusal($request, HttpConstants::HTTP_UNAUTHORIZED);
        }
        $export = Hilos::$db->dataExports->ofUser($person);
        if ($export?->state !== DataExportState::READY || $export->storedName === null || $export->finishedAt === null
            || $export->expiresAt === null || $export->expiresAt <= TimeHelper::getSqlDateTime()
        ) {
            return HttpReplyDTO::refusal($request, HttpConstants::HTTP_NOT_FOUND);
        }
        try {
            $response = DataExportDownloadResponse::forFile(
                $request,
                $this->directory[$export->storedName],
                $export->finishedAt,
                Hilos::$env[EnvConstants::HILOS_DATA_EXPORT_XACCEL_LOCATION]->string(),
            );
        } catch (FsException $e) {
            $this->logAgentError('Cannot serve the data export: ' . $e->getMessage());

            return HttpReplyDTO::refusal($request, HttpConstants::HTTP_INTERNAL_ERROR);
        }
        if ($response->tooLarge) {
            $this->logAgentError(
                'Data export exceeds the direct response ceiling; set ' . EnvConstants::HILOS_DATA_EXPORT_XACCEL_LOCATION->name,
            );
        }

        return $response->reply;
    }

    /**
     * @param string $acceptKey Browser asking for its person's copy
     * @throws HilosException When the person or proof is absent, or the order cannot be written
     */
    private function order(string $acceptKey): void
    {
        $connection = Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey);
        if ($connection?->sessionToken === null) {
            throw new ValidationException(DataExportMessages::NOBODY);
        }
        $person = $connection->userId;
        if ($person === null) {
            $person = Hilos::$db->sessions->findByToken($connection->sessionToken)?->blockedUserId;
            if ($person === null || Hilos::$db->users[$person]?->block !== true) {
                throw new ValidationException(DataExportMessages::NOBODY);
            }
        }
        (new StepUpGate())->require($connection->sessionToken, $person, StepUpOperationKey::EXPORT_DATA);
        $previous = Hilos::$db->dataExports->ofUser($person);
        if ($previous?->state === DataExportState::PREPARING) {
            return;
        }
        if ($previous?->storedName !== null) {
            $this->directory[$previous->storedName]->unlink();
        }
        Hilos::$db->dataExports->actions->order($person, TimeHelper::getSqlDateTime());
        $this->orderReplyPending = true;
        $this->publishState($person);
    }

    /**
     * @param int $userId Erased person whose copy is discarded
     * @throws HilosException When removal or publication fails
     */
    private function forgetUser(int $userId): void
    {
        $export = Hilos::$db->dataExports->ofUser($userId);
        if ($export !== null) {
            $this->removeExport($export);
        }
        $this->publishState($userId);
    }

    /**
     * @throws HilosException When the queue or the completion write fails
     */
    private function buildNext(): void
    {
        $export = Hilos::$db->dataExports->nextPreparing();
        if ($export === null) {
            return;
        }
        try {
            $basename = RandomHelper::secureHex(self::STORED_NAME_BYTES);
        } catch (RandomException $e) {
            throw new FsException('Cannot mint a data export filename', previous: $e);
        }
        $storedName = $basename . self::ZIP_EXTENSION;
        $buildingPath = $this->directory[$basename . self::BUILDING_EXTENSION]->getPath();
        $finalPath = $this->directory[$storedName]->getPath();
        try {
            $archive = new DataExportArchive($buildingPath);
            FrameworkDataExportSections::write($export->userId, $archive);
            $this->applyAccountExport($export->userId, $archive);
            $archive->text('README.txt', $this->readme());
            $size = $archive->close();
            unset($archive);

            if (Hilos::$db->accountDeletions->erasedOf($export->userId) !== null) {
                FsPath::delete($buildingPath);
                $export->actions->delete();

                return;
            }
            FsPath::move($buildingPath, $finalPath);
            $finishedAt = TimeHelper::getSqlDateTime();
            $expiresAt = date('Y-m-d H:i:s', time() + self::LIFETIME_DAYS * TimeConstants::SECONDS_PER_DAY);
            if (!$export->actions->finishReady($storedName, $size, $finishedAt, $expiresAt)) {
                FsPath::delete($finalPath);

                return;
            }
        } catch (WiringRefusal $e) {
            unset($archive);
            FsPath::delete($buildingPath);
            FsPath::delete($finalPath);
            throw $e;
        } catch (HilosException $e) {
            unset($archive);
            FsPath::delete($buildingPath);
            FsPath::delete($finalPath);
            if (Hilos::$db->accountDeletions->erasedOf($export->userId) !== null) {
                $export->actions->delete();

                return;
            }
            $this->logAgentError("Cannot prepare the data export of user {$export->userId}: {$e->getMessage()}");
            if (!$export->actions->finishFailed(
                TimeHelper::getSqlDateTime(),
                date('Y-m-d H:i:s', time() + self::LIFETIME_DAYS * TimeConstants::SECONDS_PER_DAY),
            )) {
                return;
            }
        }
        $this->publishState($export->userId);
        $this->announce($export->userId, $export->state === DataExportState::READY);
    }

    /**
     * @param int $userId Person whose request reached a recorded outcome
     * @param bool $ready Whether the copy is ready rather than failed
     * @throws InvalidArgumentException When the notification signal cannot be queued
     */
    private function announce(int $userId, bool $ready): void
    {
        Hilos::$notify?->emit(new NotificationDraft(
            userId: $userId,
            type: $ready ? DataExportNotificationType::READY : DataExportNotificationType::FAILED,
            title: $ready ? DataExportMessages::READY_TITLE : DataExportMessages::FAILED_TITLE,
            severity: $ready ? NotificationSeverity::INFO : NotificationSeverity::WARNING,
            body: $ready ? sprintf(DataExportMessages::READY_BODY, self::LIFETIME_DAYS) : DataExportMessages::FAILED_BODY,
            data: [DataExportNotificationType::DATA_URL => DataExportNotificationType::SECTION_PATH],
        ));
    }

    /**
     * @throws HilosException When an expired request, file or state publication fails
     */
    private function expireCopies(): void
    {
        foreach (Hilos::$db->dataExports->expiredBy(TimeHelper::getSqlDateTime()) as $export) {
            $this->removeExport($export);
            $this->publishState($export->userId);
        }
        $keptNames = [];
        foreach (Hilos::$db->dataExports->allReady() as $export) {
            if ($export->storedName !== null) {
                $keptNames[$export->storedName] = true;
            }
        }
        foreach (FsPath::entries($this->directory->getPath()) as $name) {
            if ($name === '.' || $name === '..' || isset($keptNames[$name])) {
                continue;
            }
            $path = $this->directory[$name]->getPath();
            if (!is_dir($path) || is_link($path)) {
                FsPath::delete($path);
            }
        }
    }

    /**
     * @param DataExport $export Request whose row and published file are removed
     * @throws HilosException When either removal fails
     */
    private function removeExport(DataExport $export): void
    {
        if ($export->storedName !== null) {
            $this->directory[$export->storedName]->unlink();
        }
        $export->actions->delete();
    }

    /**
     * @return string Human-readable archive index
     */
    private function readme(): string
    {
        return 'Your data copy, prepared at ' . DataExportTime::iso(TimeHelper::getSqlDateTime()) . ".\n\n"
            . "JSON files contain your account's records. Dates are ISO 8601 in UTC.\n"
            . "account: account number and the first sign-in method's creation date.\n"
            . "sign_in_methods: sign-in methods without secrets. passkeys: device names and dates.\n"
            . "sessions: your sessions and devices. second_factor: status and remaining backup-code count.\n"
            . "notifications and notification_preferences: your messages and channel choices, when enabled.\n"
            . "push_subscriptions: devices and dates without subscription addresses or keys.\n"
            . "account_deletion: your pending deletion request, or null.\n"
            . "The section named after the project contains your profile and project content.\n"
            . "files/ contains original attachments referenced by relative paths in that section.\n\n"
            . "Preparing this copy does not change or remove anything in your account.\n";
    }
}
