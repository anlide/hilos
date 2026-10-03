<?php

declare(strict_types=1);

namespace Hilos\Legal\Export;

use Hilos\Auth\Session\DTO\RaiseSessionToastSignalData;
use Hilos\Auth\Session\SessionToastSeverity;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\View\Item\LegalAcceptanceExport;
use Hilos\Files\Download\PrivateDownloadResponse;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\FsDirectory;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\Export\DTO\LegalAcceptancesExportStateSignalData;
use Hilos\Pages\Legal\DTO\HilosLegalAcceptancesExportActionDTO;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use Hilos\Tables\Legal\AbstractHilosLegalAcceptancesTable;
use Hilos\Users\AskingAdministrator;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;
use Hilos\WiringRefusal;
use Random\RandomException;

/**
 * Files of acceptance records an administrator orders on the acceptances page (HIL-1234).
 *
 * The legal agent holds them, in its own process: an order is a row of the agent's table, the file is
 * built a part per tick so the pages of the section never wait, it is kept a day after it is ready and
 * handed only to the administrator who ordered it, over the session and never over a key in the address.
 * The state lives in the process the way {@see LegalAdminAudience} does: the build in progress, the
 * browser each order is to be told in, and the expiry schedule; a restarted agent builds an unfinished
 * order again from its first line and says nothing about it but its state on the page.
 */
final class LegalAcceptancesExports
{
    /** How long a finished file is kept, counted from the moment it is ready. */
    public const int LIFETIME_SECONDS = TimeConstants::SECONDS_PER_DAY;

    /** Most acceptance records one tick writes. */
    public const int RECORDS_PER_PART = 500;

    private const int STORED_NAME_BYTES = 16;
    private const string CSV_EXTENSION = '.csv';
    private const string BUILDING_EXTENSION = '.building.csv';
    private const string EXPIRE_RULE = 'hilos_legal_acceptances_export_expire';
    private const string EXPIRE_CRON = '0 * * * *';
    private const string CONTENT_TYPE = 'text/csv; charset=utf-8';
    private const string FILENAME_PREFIX = 'legal-acceptances-';

    private static ?FsDirectory $directory = null;
    private static ?CronRule $expireRule = null;

    /** The worker flushes action replies after ticks, so a new order lets that first turn go. */
    private static bool $orderReplyPending = false;

    private static ?LegalAcceptancesExportBuild $build = null;

    /** @var array<int, string> Hash of the session token of the browser that placed each order, keyed by order id */
    private static array $toastSessions = [];

    /**
     * Reconciles the orders with the directory: a ready order without its file is removed, then the sweep runs.
     *
     * @param PageAgentInterface $agent The legal agent starting
     * @throws HilosException When the directory, an order or a state frame cannot be read or written
     */
    public static function onStart(PageAgentInterface $agent): void
    {
        self::$directory = Hilos::$fs->getDirectory(FsContext::LEGAL_EXPORT);
        self::$directory->ensureDirectory();
        self::$expireRule = new CronRule(self::EXPIRE_RULE, self::EXPIRE_CRON);
        foreach (Hilos::$db->legalAcceptanceExports->allReady() as $export) {
            if ($export->storedName === null || !self::$directory[$export->storedName]->exists()) {
                self::remove($export);
                self::sendState($agent, $export->userId);
            }
        }
        self::expire($agent);
    }

    /**
     * Lets a new order's reply leave first, then either sweeps on schedule or writes one part of a file.
     *
     * @param PageAgentInterface $agent The legal agent ticking
     * @throws HilosException When the orders, the sweep or a state frame fail outside a build
     */
    public static function onTick(PageAgentInterface $agent): void
    {
        if (self::$expireRule === null) {
            return;
        }
        if (self::$orderReplyPending) {
            self::$orderReplyPending = false;

            return;
        }
        if (self::$expireRule->shouldRun()) {
            self::expire($agent);

            return;
        }
        self::buildPart($agent);
    }

    /**
     * Places an administrator's order for the records the table shows under the given filters and search.
     *
     * The confirmation is required before anything else is read: it is the administrator of this browser
     * who confirms. An order already preparing answers silently; any other order of theirs is replaced
     * together with its file.
     *
     * @param PageAgentInterface $agent Agent serving the acceptances page
     * @param string $acceptKey Connection that ordered
     * @param HilosLegalAcceptancesExportActionDTO $dto Filters and search of the table at the press
     * @throws HilosException When the administrator, the confirmation or the order is refused, or the file cannot be removed
     */
    public static function order(PageAgentInterface $agent, string $acceptKey, HilosLegalAcceptancesExportActionDTO $dto): void
    {
        $userId = AskingAdministrator::confirmed($acceptKey, StepUpOperationKey::EXPORT_LEGAL_ACCEPTANCES);
        $previous = Hilos::$db->legalAcceptanceExports->ofUser($userId);
        if ($previous?->state === LegalAcceptancesExportState::PREPARING) {
            return;
        }
        if ($previous !== null) {
            if ($previous->storedName !== null) {
                self::directory()[$previous->storedName]->unlink();
            }
            unset(self::$toastSessions[(int) $previous->id]);
        }
        $export = Hilos::$db->legalAcceptanceExports->actions->order(
            $userId,
            $dto->document,
            $dto->revisionId,
            $dto->search,
            TimeHelper::getSqlDateTime(),
        );
        $sessionToken = Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey)?->sessionToken;
        if ($export->id !== null && $sessionToken !== null) {
            self::$toastSessions[$export->id] = ProtectedModeRuntime::hashSessionToken($sessionToken);
        }
        self::$orderReplyPending = true;
        self::sendState($agent, $userId);
    }

    /**
     * Removes every finished file after an account is erased and starts the file being built again.
     *
     * Which person is in which file is not known, and the records of an erased account are gone with it;
     * no copy on the server may outlive them. The erased person's own order goes in the same pass.
     *
     * @param PageAgentInterface $agent The legal agent
     * @param int $userId Erased account
     * @throws HilosException When an order, a file or a state frame cannot be removed or sent
     */
    public static function forget(PageAgentInterface $agent, int $userId): void
    {
        $touched = [];
        foreach (Hilos::$db->legalAcceptanceExports->allFinished() as $export) {
            self::remove($export);
            $touched[$export->userId] = true;
        }
        if (self::$build !== null) {
            FsPath::delete(self::directory()[self::$build->buildingName]->getPath());
            self::$build = null;
        }
        $own = Hilos::$db->legalAcceptanceExports->ofUser($userId);
        if ($own !== null) {
            self::remove($own);
            $touched[$userId] = true;
        }
        foreach (array_keys($touched) as $adminId) {
            self::sendState($agent, $adminId);
        }
    }

    /**
     * Answers the download address: the file of the administrator whose browser asks, while it is kept.
     *
     * @param PageAgentInterface $agent The legal agent, whose journal names a transport the operator must fix
     * @param HttpRequestDTO $request Request carrying a cookie or header session, never a URL credential
     * @return HttpReplyDTO The file or a refusal without caching
     * @throws HilosException When a database source, an environment value or this node's cluster identity cannot be read
     */
    public static function serve(PageAgentInterface $agent, HttpRequestDTO $request): HttpReplyDTO
    {
        $session = $request->sessionToken === null ? null : Hilos::$db->sessions->findByToken($request->sessionToken);
        if ($session === null || ($session->expiresAt !== null && $session->expiresAt <= TimeHelper::getSqlDateTime())) {
            return HttpReplyDTO::refusal($request, HttpConstants::HTTP_UNAUTHORIZED);
        }
        if ($session->impersonatorUserId !== null) {
            return HttpReplyDTO::refusal($request, HttpConstants::HTTP_FORBIDDEN);
        }
        if ($session->userId === null) {
            return HttpReplyDTO::refusal($request, HttpConstants::HTTP_UNAUTHORIZED);
        }
        if (!in_array($session->userId, Hilos::adminAudienceClass()::all(), true)) {
            return HttpReplyDTO::refusal($request, HttpConstants::HTTP_FORBIDDEN);
        }
        $export = Hilos::$db->legalAcceptanceExports->ofUser($session->userId);
        if ($export?->state !== LegalAcceptancesExportState::READY || $export->storedName === null || $export->finishedAt === null
            || $export->expiresAt === null || $export->expiresAt <= TimeHelper::getSqlDateTime()
        ) {
            return HttpReplyDTO::refusal($request, HttpConstants::HTTP_NOT_FOUND);
        }
        try {
            $response = PrivateDownloadResponse::forFile(
                $request,
                self::directory()[$export->storedName],
                self::CONTENT_TYPE,
                self::FILENAME_PREFIX
                    . gmdate('Y-m-d', intdiv(TimeHelper::sqlToMs($export->finishedAt), TimeConstants::MS_PER_SECOND))
                    . self::CSV_EXTENSION,
                Hilos::$env[EnvConstants::HILOS_LEGAL_EXPORT_XACCEL_LOCATION]->string(),
                Hilos::$cluster?->localNodeId(),
            );
        } catch (FsException $e) {
            Logger::logAgentError($agent->getId(), 'Cannot serve the export of legal acceptances: ' . $e->getMessage());

            return HttpReplyDTO::refusal($request, HttpConstants::HTTP_INTERNAL_ERROR);
        }
        if ($response->tooLarge) {
            Logger::logAgentError(
                $agent->getId(),
                'The export of legal acceptances exceeds the direct response ceiling; set '
                . EnvConstants::HILOS_LEGAL_EXPORT_XACCEL_LOCATION->name,
            );
        }
        if ($response->onAnotherNode) {
            Logger::logAgentError(
                $agent->getId(),
                "The export of legal acceptances is not sent by the daemon: the browser's connection is on node "
                . "{$request->originNodeId} and the daemon's own body does not travel between nodes; set "
                . EnvConstants::HILOS_LEGAL_EXPORT_XACCEL_LOCATION->name,
            );
        }

        return $response->reply;
    }

    /** Forgets the build in progress, the browsers to tell and the schedule before another agent uses this worker. */
    public static function reset(): void
    {
        self::$directory = null;
        self::$expireRule = null;
        self::$orderReplyPending = false;
        self::$build = null;
        self::$toastSessions = [];
    }

    /**
     * Starts the earliest order, or writes one part of the file being built and finishes it after the last.
     *
     * @param PageAgentInterface $agent The legal agent ticking
     * @throws HilosException When the queue cannot be read, or a failed build cannot be recorded
     */
    private static function buildPart(PageAgentInterface $agent): void
    {
        $build = self::$build;
        if ($build === null) {
            $export = Hilos::$db->legalAcceptanceExports->nextPreparing();
            if ($export !== null) {
                self::startBuild($agent, $export);
            }

            return;
        }
        try {
            $rows = self::table()->exportChunk($build->where, $build->params, $build->after, self::RECORDS_PER_PART);
            if ($rows !== []) {
                FsPath::append(
                    self::directory()[$build->buildingName]->getPath(),
                    implode('', array_map(LegalAcceptancesCsv::line(...), $rows)),
                );
                $build->records += count($rows);
                $build->after = $rows[array_key_last($rows)];
            }
            if (count($rows) < self::RECORDS_PER_PART) {
                self::finishBuild($agent, $build);
            }
        } catch (WiringRefusal $e) {
            self::discard($build);
            throw $e;
        } catch (HilosException $e) {
            self::fail($agent, $build->exportId, $build->userId, $build, $e);
        }
    }

    /**
     * Resolves the order's filters and search once and opens its file with the byte order mark and the header.
     *
     * @param PageAgentInterface $agent The legal agent ticking
     * @param LegalAcceptanceExport $export Earliest preparing order
     * @throws HilosException When a failed start cannot be recorded
     */
    private static function startBuild(PageAgentInterface $agent, LegalAcceptanceExport $export): void
    {
        $exportId = (int) $export->id;
        $build = null;
        try {
            try {
                $basename = RandomHelper::secureHex(self::STORED_NAME_BYTES);
            } catch (RandomException $e) {
                throw new FsException('Cannot mint a legal acceptances export filename', previous: $e);
            }
            [$where, $params] = self::table()->exportScope($export->document, $export->revisionId, $export->search);
            $build = new LegalAcceptancesExportBuild(
                $exportId,
                $export->userId,
                $basename . self::CSV_EXTENSION,
                $basename . self::BUILDING_EXTENSION,
                $where,
                $params,
            );
            FsPath::write(self::directory()[$build->buildingName]->getPath(), LegalAcceptancesCsv::bom() . LegalAcceptancesCsv::header());
            self::$build = $build;
        } catch (WiringRefusal $e) {
            if ($build !== null) {
                self::discard($build);
            }
            throw $e;
        } catch (HilosException $e) {
            self::fail($agent, $exportId, $export->userId, $build, $e);
        }
    }

    /**
     * Publishes the whole file under its stored name and records the order ready, unless it went meanwhile.
     *
     * @param PageAgentInterface $agent The legal agent ticking
     * @param LegalAcceptancesExportBuild $build The build whose last part was written
     * @throws HilosException When the file cannot be published, the order recorded or its state sent
     */
    private static function finishBuild(PageAgentInterface $agent, LegalAcceptancesExportBuild $build): void
    {
        $finalPath = self::directory()[$build->storedName]->getPath();
        FsPath::move(self::directory()[$build->buildingName]->getPath(), $finalPath);
        self::$build = null;
        $export = Hilos::$db->legalAcceptanceExports->ofUser($build->userId);
        if ($export?->id !== $build->exportId || !$export->actions->finishReady(
            $build->storedName,
            FsPath::size($finalPath),
            $build->records,
            TimeHelper::getSqlDateTime(),
            self::expiresAt(),
        )) {
            FsPath::delete($finalPath);

            return;
        }
        self::sendState($agent, $build->userId);
        self::announce($agent, $build->exportId, $build->userId, true);
    }

    /**
     * Removes what the failed build wrote, says why in the journal and records the order failed.
     *
     * @param PageAgentInterface $agent The legal agent ticking
     * @param int $exportId Order that failed
     * @param int $userId Administrator who placed it
     * @param ?LegalAcceptancesExportBuild $build Its build, or null when the build never opened
     * @param HilosException $e Reason for the journal
     * @throws HilosException When the files, the order or the state frame cannot be written
     */
    private static function fail(
        PageAgentInterface $agent,
        int $exportId,
        int $userId,
        ?LegalAcceptancesExportBuild $build,
        HilosException $e,
    ): void {
        if ($build !== null) {
            self::discard($build);
        }
        Logger::logAgentError($agent->getId(), "Cannot prepare the export of legal acceptances {$exportId}: {$e->getMessage()}");
        $export = Hilos::$db->legalAcceptanceExports->ofUser($userId);
        if ($export?->id !== $exportId || !$export->actions->finishFailed(TimeHelper::getSqlDateTime(), self::expiresAt())) {
            return;
        }
        self::sendState($agent, $userId);
        self::announce($agent, $exportId, $userId, false);
    }

    /**
     * @param LegalAcceptancesExportBuild $build Build whose files go and which stops being the current one
     * @throws FsException When a file exists and cannot be removed
     */
    private static function discard(LegalAcceptancesExportBuild $build): void
    {
        if (self::$build === $build) {
            self::$build = null;
        }
        FsPath::delete(self::directory()[$build->buildingName]->getPath());
        FsPath::delete(self::directory()[$build->storedName]->getPath());
    }

    /**
     * Removes expired orders with their files, then every file of the directory nothing keeps.
     *
     * The directory is listed by {@see FsDirectory::entries()}: the cluster directory marker is the
     * framework's, not one of the files, and stays (HIL-1242).
     *
     * @param PageAgentInterface $agent The legal agent
     * @throws HilosException When an expired order, a file or a state frame fails
     */
    private static function expire(PageAgentInterface $agent): void
    {
        foreach (Hilos::$db->legalAcceptanceExports->expiredBy(TimeHelper::getSqlDateTime()) as $export) {
            self::remove($export);
            self::sendState($agent, $export->userId);
        }
        $keptNames = [];
        foreach (Hilos::$db->legalAcceptanceExports->allReady() as $export) {
            if ($export->storedName !== null) {
                $keptNames[$export->storedName] = true;
            }
        }
        if (self::$build !== null) {
            $keptNames[self::$build->buildingName] = true;
        }
        foreach (self::directory()->entries() as $name) {
            if (isset($keptNames[$name])) {
                continue;
            }
            $path = self::directory()[$name]->getPath();
            if (!is_dir($path) || is_link($path)) {
                FsPath::delete($path);
            }
        }
    }

    /**
     * @param LegalAcceptanceExport $export Order whose row and file are removed
     * @throws HilosException When either removal fails
     */
    private static function remove(LegalAcceptanceExport $export): void
    {
        if ($export->storedName !== null) {
            self::directory()[$export->storedName]->unlink();
        }
        unset(self::$toastSessions[(int) $export->id]);
        $export->actions->delete();
    }

    /**
     * Sends an administrator's export state to each of their connections on the acceptances page.
     *
     * A connection the page's verdict refuses today is skipped, as the vocabulary frame skips it; a viewer
     * of the admin view mode has no export of their own to see.
     *
     * @param PageAgentInterface $agent The legal agent
     * @param int $userId Administrator whose state changed
     * @throws HilosException When the order or the administrator access cannot be read
     * @throws InvalidArgumentException When the state signal cannot be named
     */
    private static function sendState(PageAgentInterface $agent, int $userId): void
    {
        $pageClass = Hilos::appClass()::PAGES[HilosPageConstants::HILOS_LEGAL_ACCEPTANCES] ?? null;
        $subscribers = LegalAdminAudience::acceptancesSubscribers();
        if ($pageClass === null || $subscribers === []) {
            return;
        }
        $data = new LegalAcceptancesExportStateSignalData(
            LegalAcceptancesExportProjector::nodeFor(Hilos::$db->legalAcceptanceExports->ofUser($userId)),
        );
        foreach ($subscribers as $acceptKey) {
            if (Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey)?->userId !== $userId) {
                continue;
            }
            try {
                PageAccessGate::assert($pageClass, $acceptKey);
                Hilos::$browser?->assertSubscriptionAccess(HilosPageConstants::HILOS_LEGAL_ACCEPTANCES, $acceptKey, new PageRouteParams([]));
            } catch (PageSubscriptionException) {
                // A reassessment keeps the subscription alive while its current verdict denies delivery.
                continue;
            }
            if (Hilos::$browser?->isAdminViewModeViewer($pageClass, $acceptKey) === true) {
                continue;
            }
            Hilos::$sr->queueSignal(
                signalSource: $agent->getAgentSignalSource(),
                signalType: new SignalType(SignalTypeConstants::WS_USER),
                signalName: new SignalName(HilosSignalConstants::HILOS_LEGAL_ACCEPTANCES_EXPORT_STATE),
                signalData: new WebSocketSignalData(data: $data, targetAcceptKey: $acceptKey),
            );
        }
    }

    /**
     * Tells the browser that placed the order how it ended; an order rebuilt after a restart has no browser to tell.
     *
     * @param PageAgentInterface $agent The legal agent
     * @param int $exportId Order that ended
     * @param int $userId Administrator who placed it, at the keyboard: an impersonated session cannot order
     * @param bool $ready Whether the file is ready rather than failed
     * @throws InvalidArgumentException When the toast cannot be named or queued
     */
    private static function announce(PageAgentInterface $agent, int $exportId, int $userId, bool $ready): void
    {
        $sessionTokenHash = self::$toastSessions[$exportId] ?? null;
        unset(self::$toastSessions[$exportId]);
        if ($sessionTokenHash === null) {
            return;
        }
        Hilos::$sr->queueSignal(
            signalSource: $agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            signalName: new SignalName(HilosSignalConstants::HILOS_SESSION_TOAST_RAISE),
            signalData: new AgentSignalData(data: new RaiseSessionToastSignalData(
                sessionTokenHash: $sessionTokenHash,
                addresseeUserId: $userId,
                message: $ready ? LegalAcceptancesExportMessages::READY : LegalAcceptancesExportMessages::FAILED,
                severity: $ready ? SessionToastSeverity::SUCCESS : SessionToastSeverity::WARNING,
                source: LegalAcceptancesExportMessages::TOAST_SOURCE,
                destination: LegalAcceptancesExportMessages::TOAST_DESTINATION,
            )),
        );
    }

    /**
     * @return AbstractHilosLegalAcceptancesTable The project's acceptances table, with its names
     * @throws LogicException When the project registers no acceptances table
     */
    private static function table(): AbstractHilosLegalAcceptancesTable
    {
        $table = Hilos::$table?->get(AbstractHilosLegalAcceptancesTable::TABLE);
        if (!$table instanceof AbstractHilosLegalAcceptancesTable) {
            throw new LogicException('The legal acceptances table is not registered');
        }

        return $table;
    }

    /**
     * @return FsDirectory The export directory, read again after a stop
     * @throws FsException When the directory is not registered
     */
    private static function directory(): FsDirectory
    {
        return self::$directory ??= Hilos::$fs->getDirectory(FsContext::LEGAL_EXPORT);
    }

    /**
     * @return string The moment a file finished now stops being kept, in SQL form
     */
    private static function expiresAt(): string
    {
        return date('Y-m-d H:i:s', time() + self::LIFETIME_SECONDS);
    }
}
