<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationStepRefusal;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\Session\DTO\RaiseSessionToastSignalData;
use Hilos\Auth\Session\SessionToastSeverity;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\StepUp\StepUpSettingsCatalog;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosLegalAgent;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Object\Collection\LegalAcceptanceExports as ObjectLegalAcceptanceExports;
use Hilos\Database\Object\Item\LegalAcceptanceExport as ObjectLegalAcceptanceExport;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\View\Collection\LegalAcceptanceExports as ViewLegalAcceptanceExports;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Database\View\Item\LegalAcceptanceExport;
use Hilos\Fs\ClusterDirectoryMarker;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\Export\DTO\LegalAcceptancesExportForgetSignalData;
use Hilos\Legal\Export\LegalAcceptancesCsv;
use Hilos\Legal\Export\LegalAcceptancesExportErasureResult;
use Hilos\Legal\Export\LegalAcceptancesExportHttp;
use Hilos\Legal\Export\LegalAcceptancesExportState;
use Hilos\Legal\Export\LegalAcceptancesExports;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Pages\Legal\DTO\HilosLegalAcceptancesExportActionDTO;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use Hilos\Tables\Legal\AbstractHilosLegalAcceptancesTable;
use Hilos\Tables\Legal\HilosLegalAcceptanceTableRow;
use Hilos\Users\SecondFactorFate;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The administrators' files of acceptance records against the real tables, directory and legal agent (HIL-1234).
 *
 * The agent is the real one, ticked by hand; the people, their sessions and their confirmations are rows of the
 * framework tables, and the browsers are session connections of a mounted runtime. What a file must say is read
 * from the seeded rows here, not from the code that writes it: the expected lines are spelled out per person.
 */
final class LegalAcceptancesExportIntegrationTest extends HilosSessionIntegrationTestCase
{
    /**
     * Tables beside the session base's: the orders, the passkeys a step-up resolver asks about, and the account
     * operations whose erasure part the agent carries, in the order their keys point.
     */
    private const array EXTRA_TABLES = [
        'hilos_passkey_credential',
        'hilos_legal_acceptance_export',
        'hilos_account_operation',
        'hilos_account_operation_assignment',
        'hilos_account_operation_result',
    ];

    private const int ADMIN_ID = 1;
    private const int OTHER_ADMIN_ID = 2;
    private const int THIRD_ADMIN_ID = 3;
    private const string ADMIN_TOKEN = 'legal-export-admin-session';
    private const string OTHER_ADMIN_TOKEN = 'legal-export-other-admin-session';
    private const string PERSON_TOKEN = 'legal-export-person-session';
    private const string IMPERSONATED_TOKEN = 'legal-export-impersonated-session';
    private const string EXPIRED_TOKEN = 'legal-export-expired-session';
    private const string ANONYMOUS_TOKEN = 'legal-export-anonymous-session';
    private const string ADMIN_KEY = 'accept-legal-export-admin';
    private const string OTHER_ADMIN_KEY = 'accept-legal-export-other-admin';
    private const string PERSON_KEY = 'accept-legal-export-person';
    private const string IMPERSONATED_KEY = 'accept-legal-export-impersonated';
    private const int CREATED_AT = 1767225600000;
    private const string PAST = '2026-01-01 00:00:00';
    private const int CONFIRMATION_PAST = 1767225600000;

    /** The person every refusal and erasure case is about: no administrator. */
    private const int PERSON_ID = 101;

    /** The deletion request an erasure case opens its operation for; the operation keeps it as a soft reference. */
    private const int REQUEST_ID = 19470;

    /** A table of the failing erasure case only: its row pins one finished export so that deleting it fails. */
    private const string PIN_TABLE = 'legal_export_test_pin';

    /** People who accepted the documents, by id. */
    private const array PEOPLE = [
        101 => 'Alice Kovalenko',
        102 => 'Bohdan Shevchenko',
        103 => 'Petrenko, Olena',
        104 => '=Dmytro Melnyk',
        105 => 'Ірина Бондар',
        106 => 'Taras Lysenko',
    ];

    /** Verified emails; the last person has only an unverified address. */
    private const array EMAILS = [
        self::ADMIN_ID => 'root@example.test',
        101 => 'alice@example.test',
        102 => 'bohdan@example.test',
        103 => 'olena@example.test',
        104 => 'dmytro@example.test',
        105 => 'iryna@example.test',
    ];

    /** The first three cells of each person's line, as a spreadsheet must read them. */
    private const array PERSON_CELLS = [
        101 => '101,Alice Kovalenko,alice@example.test',
        102 => '102,Bohdan Shevchenko,bohdan@example.test',
        103 => '103,"Petrenko, Olena",olena@example.test',
        104 => "104,'=Dmytro Melnyk,dmytro@example.test",
        105 => '105,Ірина Бондар,iryna@example.test',
        106 => '106,Taras Lysenko,',
    ];

    /** Revisions the fixture catalog declares, by document. */
    private const array DECLARED = ['terms' => ['old', 'wording'], 'privacy' => ['privacy']];

    /** Acceptance records seeded: two parts of 500 and a short third one. */
    private const int RECORDS = 1201;
    private const string FIRST_ACCEPTANCE = '2026-03-01 00:00:00';

    /** Distinct minutes the records are spread over, so most moments are shared by three records. */
    private const int MINUTES = 401;
    private const int MINUTE_STRIDE = 7;

    private const string BOM = "\xEF\xBB\xBF";
    private const string HEADER_LINE = "user_id,name,email,document,revision,in_code,accepted_at\r\n";
    private const string LINE_END = "\r\n";
    private const int PART = 500;
    private const int WINDOW = 25;
    private const int TICK_CEILING = 20;
    private const int SECONDS_PER_DAY = 86400;
    private const string BUILDING_SUFFIX = '.building.csv';

    /** The ordering administrator's own zone, where the seeded moments of 1 March before 08:00 UTC are still February. */
    private const string ADMIN_ZONE = 'America/Los_Angeles';

    /** The project's zone, where the seeded moments after 05:00 UTC are on another day than those before. */
    private const string PROJECT_ZONE = 'America/New_York';

    /** The zone of every person whose acceptances the file lists, which the file must never take. */
    private const string PEOPLE_ZONE = 'Asia/Tokyo';

    /** The zone the administrator and the project move to while a file is being built. */
    private const string LATER_ZONE = 'Pacific/Auckland';

    /** A readiness moment whose UTC day is the day before its Warsaw day. */
    private const string LATE_EVENING = '2026-10-22 23:30:00';
    private const string SAVED_ZONE = 'Europe/Warsaw';

    private string $directory;
    private string $logFile;
    private ?SignalRouter $previousRouter = null;
    private ?RtContext $previousRt = null;
    private ?SettingsAccessor $previousSetting = null;
    private ?TableContext $previousTable = null;
    private ?FsContext $previousFs = null;

    /**
     * @throws HilosException When the fixture tables, people, sessions or contexts cannot be mounted
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::dropExtraTables();
        foreach (self::EXTRA_TABLES as $table) {
            Database::sqlRun((string) file_get_contents(self::stubPath($table)));
        }
        Schema::reset();
        Schema::initialize();
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        $this->previousSetting = Hilos::$setting;
        $this->previousTable = Hilos::$table;
        $this->previousFs = Hilos::$fs;
        Hilos::$sr = new SignalRouter();
        $this->directory = sys_get_temp_dir() . '/hilos-legal-export-' . bin2hex(random_bytes(8));
        $this->logFile = $this->directory . '.log';
        Logger::setLogFile($this->logFile);
        Hilos::$fs = new LegalExportIntegrationFs($this->directory);
        Hilos::$fs->configure();
        Hilos::$table = new LegalExportIntegrationTables();
        Hilos::$table->configure();
        Hilos::$setting = new SettingsAccessor(LegalExportIntegrationSettingsCatalog::class);
        LegalExportIntegrationHilos::initBrowser();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        LegalAdminAudience::reset();
        LegalAcceptancesExports::reset();
        OwnershipDeclaration::claimDb(LegalExportIntegrationCoordinator::class, HilosAgentType::HILOS_SESSIONS_LIBRARY);
        self::seedPeopleAndSessions();
        $rt = new LegalExportIntegrationRtContext([
            LegalExportIntegrationConnection::create(self::ADMIN_KEY, self::ADMIN_ID, self::ADMIN_TOKEN, self::sessionIdOf(self::ADMIN_TOKEN)),
            LegalExportIntegrationConnection::create(
                self::OTHER_ADMIN_KEY,
                self::OTHER_ADMIN_ID,
                self::OTHER_ADMIN_TOKEN,
                self::sessionIdOf(self::OTHER_ADMIN_TOKEN),
            ),
            LegalExportIntegrationConnection::create(self::PERSON_KEY, self::PERSON_ID, self::PERSON_TOKEN, self::sessionIdOf(self::PERSON_TOKEN)),
            LegalExportIntegrationConnection::create(
                self::IMPERSONATED_KEY,
                self::ADMIN_ID,
                self::IMPERSONATED_TOKEN,
                self::sessionIdOf(self::IMPERSONATED_TOKEN),
            ),
        ]);
        $rt->configure();
        Hilos::$rt = $rt;
    }

    /**
     * @throws HilosException When the fixture tables cannot be dropped
     */
    protected function tearDown(): void
    {
        foreach ([HilosAgentType::HILOS_LEGAL, HilosAgentType::HILOS_SESSIONS_LIBRARY] as $agentId) {
            TruthSourceRegistry::unregisterAgent($agentId);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
        }
        LegalAcceptancesExports::reset();
        LegalAdminAudience::reset();
        SourceChangeBus::reset();
        Logger::resetLogFile();
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt = $this->previousRt;
        Hilos::$setting = $this->previousSetting;
        Hilos::$table = $this->previousTable;
        Hilos::$fs = $this->previousFs;
        Hilos::initBrowser();
        Hilos::resetBrowser();
        foreach (glob($this->directory . '/*') as $path) {
            unlink($path);
        }
        if (is_file(ClusterDirectoryMarker::pathIn($this->directory))) {
            unlink(ClusterDirectoryMarker::pathIn($this->directory));
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        self::dropExtraTables();
        Schema::reset();
        parent::tearDown();
    }

    /**
     * The order's tick lets its reply go, the next opens the file, and each next one writes a part; the short
     * part finishes it, the lines are the table's in its own order, and the ordering browser hears it is ready.
     *
     * @throws HilosException When a fixture, the order or a tick fails
     */
    public function testAnOrderIsWrittenAPartPerTickInTheTablesOrder(): void
    {
        self::seedAcceptances();
        $agent = self::startedAgent();
        self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID);
        self::order($agent, self::ADMIN_KEY);
        self::assertSame(LegalAcceptancesExportState::PREPARING, self::exportOf(self::ADMIN_ID)?->state);

        $agent->onTick();
        self::assertSame([], $this->files(), 'The order tick writes nothing');
        $agent->onTick();
        $building = $this->buildingFile();
        self::assertSame(self::BOM . self::HEADER_LINE, file_get_contents($building));
        $agent->onTick();
        self::assertCount(self::PART, self::linesOf($building));
        $agent->onTick();
        self::assertCount(2 * self::PART, self::linesOf($building));
        self::assertSame(LegalAcceptancesExportState::PREPARING, self::exportOf(self::ADMIN_ID)?->state);
        self::assertSame([], self::toasts());
        $agent->onTick();

        $export = self::exportOf(self::ADMIN_ID);
        self::assertSame(LegalAcceptancesExportState::READY, $export?->state);
        self::assertSame([$export->storedName], $this->files());
        self::assertFileDoesNotExist($building);
        $path = $this->directory . '/' . $export->storedName;
        $lines = self::linesOf($path);
        self::assertSame(self::expectedLines(static fn (): bool => true, self::utc()), $lines);
        self::assertSame(self::RECORDS, $export->records);
        self::assertSame(filesize($path), $export->sizeBytes);
        self::assertSame(self::SECONDS_PER_DAY, strtotime((string) $export->expiresAt) - strtotime((string) $export->finishedAt));
        self::assertWindowMatches($lines, null, null, null, self::utc());
        $toasts = self::toasts();
        self::assertCount(1, $toasts);
        self::assertSame(ProtectedModeRuntime::hashSessionToken(self::ADMIN_TOKEN), $toasts[0]->sessionTokenHash);
        self::assertSame(self::ADMIN_ID, $toasts[0]->addresseeUserId);
        self::assertSame('Your export of legal acceptances is ready.', $toasts[0]->message);
        self::assertSame(SessionToastSeverity::SUCCESS, $toasts[0]->severity);
        self::assertSame('Legal', $toasts[0]->source);
        self::assertSame('/hilos/legal/acceptances', $toasts[0]->destination);
    }

    /**
     * A document filter, a revision filter, a search by name and one by email each select what the table shows
     * under them, part by part, and each new order replaces the ready file of the one before.
     *
     * @throws HilosException When a fixture, an order or a tick fails
     */
    public function testFiltersAndSearchSelectWhatTheTableShows(): void
    {
        self::seedAcceptances();
        $agent = self::startedAgent();
        self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID);
        $scenarios = [
            'document' => ['privacy', null, null, static fn (array $row): bool => $row['document'] === 'privacy', 600],
            'revision' => ['terms', 'old', null, static fn (array $row): bool => $row['revision_id'] === 'old', 6],
            'name' => [null, null, 'KOVALENKO', static fn (array $row): bool => (int) $row['user_id'] === 101, 201],
            'email' => [null, null, 'bohdan@example', static fn (array $row): bool => (int) $row['user_id'] === 102, 200],
        ];
        $previousName = null;
        foreach ($scenarios as $label => [$document, $revisionId, $search, $keep, $count]) {
            self::order($agent, self::ADMIN_KEY, $document, $revisionId, $search);
            self::assertSame(1, self::exportRowCount(), $label);
            if ($previousName !== null) {
                self::assertFileDoesNotExist($this->directory . '/' . $previousName, $label);
            }
            self::assertSame(2 + intdiv($count, self::PART) + 1, $this->finish($agent, self::ADMIN_ID), $label);
            $export = self::exportOf(self::ADMIN_ID);
            self::assertSame(LegalAcceptancesExportState::READY, $export?->state, $label);
            self::assertSame([$document, $revisionId, $search], [$export->document, $export->revisionId, $export->search], $label);
            $lines = self::linesOf($this->directory . '/' . $export->storedName);
            self::assertSame(self::expectedLines($keep, self::utc()), $lines, $label);
            self::assertCount($count, $lines, $label);
            self::assertSame($count, $export->records, $label);
            self::assertWindowMatches($lines, $document, $revisionId, $search, self::utc());
            $previousName = $export->storedName;
        }
    }

    /**
     * Nothing under the filters is still a file: the byte order mark and the header, ready with the zone chosen
     * for the administrator and named by its day there.
     *
     * @throws HilosException When a fixture, the order, a tick or the download fails
     */
    public function testNothingMatchingLeavesTheHeaderAlone(): void
    {
        self::seedAcceptances();
        Database::sqlRun('UPDATE `hilos_user` SET `time_zone` = ? WHERE `id` = ?', [self::ADMIN_ZONE, self::ADMIN_ID]);
        $this->withZoneEnvironment('recipient', self::PROJECT_ZONE, function (): void {
            $agent = self::startedAgent();
            self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID);
            self::order($agent, self::ADMIN_KEY, null, null, 'nobody-by-this-name');

            self::assertSame(3, $this->finish($agent, self::ADMIN_ID));
            $export = self::exportOf(self::ADMIN_ID);
            self::assertSame(LegalAcceptancesExportState::READY, $export?->state);
            self::assertSame(0, $export->records);
            self::assertSame(self::ADMIN_ZONE, $export->timeZone);
            self::assertSame(self::BOM . self::HEADER_LINE, file_get_contents($this->directory . '/' . $export->storedName));
            self::assertSame(strlen(self::BOM . self::HEADER_LINE), $export->sizeBytes);
            $day = new DateTimeImmutable((string) $export->finishedAt, self::utc())->setTimezone(new DateTimeZone(self::ADMIN_ZONE))->format('Y-m-d');
            self::assertSame(self::attachment($day), self::download($agent, self::ADMIN_TOKEN)->headers['Content-Disposition']);
        });
    }

    /**
     * A second press while the file is prepared answers nothing and changes nothing, before and during the build.
     *
     * @throws HilosException When a fixture, an order or a tick fails
     */
    public function testARepeatedOrderWhilePreparingIsSilent(): void
    {
        self::seedAcceptances();
        $agent = self::startedAgent();
        self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID);
        self::order($agent, self::ADMIN_KEY);
        $id = self::exportOf(self::ADMIN_ID)?->id;

        self::order($agent, self::ADMIN_KEY, 'terms', null, 'alice');
        $agent->onTick();
        $agent->onTick();
        self::order($agent, self::ADMIN_KEY, 'privacy', null, null);

        $export = self::exportOf(self::ADMIN_ID);
        self::assertSame(1, self::exportRowCount());
        self::assertSame($id, $export?->id);
        self::assertSame([null, null, null], [$export->document, $export->revisionId, $export->search]);
        self::assertSame(3, $this->finish($agent, self::ADMIN_ID));
        self::assertSame(self::RECORDS, self::exportOf(self::ADMIN_ID)?->records);
    }

    /**
     * An order after a ready one takes the ready file and its row away.
     *
     * @throws HilosException When a fixture, an order or a tick fails
     */
    public function testANewOrderReplacesTheReadyOneAndItsFile(): void
    {
        $agent = self::startedAgent();
        self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID);
        self::order($agent, self::ADMIN_KEY);
        $this->finish($agent, self::ADMIN_ID);
        $ready = self::exportOf(self::ADMIN_ID);
        self::assertSame(LegalAcceptancesExportState::READY, $ready?->state);
        self::assertFileExists($this->directory . '/' . $ready->storedName);

        self::order($agent, self::ADMIN_KEY, 'terms', null, null);

        $next = self::exportOf(self::ADMIN_ID);
        self::assertSame(1, self::exportRowCount());
        self::assertNotSame($ready->id, $next?->id);
        self::assertSame(LegalAcceptancesExportState::PREPARING, $next?->state);
        self::assertSame([], $this->files());
    }

    /**
     * The administrator of this browser confirms: with no confirmation, or an expired one, nothing is ordered.
     *
     * @throws HilosException When a fixture fails
     */
    public function testAnOrderWithoutALiveConfirmationIsRefused(): void
    {
        $agent = self::startedAgent();
        self::assertRefused($agent, self::ADMIN_KEY, StepUpMessages::EXPIRED);
        self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID, self::CONFIRMATION_PAST);
        self::assertRefused($agent, self::ADMIN_KEY, StepUpMessages::EXPIRED);
        self::confirm(self::OTHER_ADMIN_TOKEN, self::ADMIN_ID);
        self::assertRefused($agent, self::ADMIN_KEY, StepUpMessages::EXPIRED);
    }

    /**
     * A signed-in person who administers nothing cannot order, confirmed or not.
     *
     * @throws HilosException When a fixture fails
     */
    public function testAPersonWhoIsNoAdministratorCannotOrder(): void
    {
        $agent = self::startedAgent();
        self::confirm(self::PERSON_TOKEN, self::PERSON_ID);
        self::assertRefused($agent, self::PERSON_KEY, 'Only an active administrator can do this');
    }

    /**
     * An administrator's account taken over by another administrator cannot order, confirmed or not.
     *
     * @throws HilosException When a fixture fails
     */
    public function testAnImpersonatedSessionCannotOrder(): void
    {
        $agent = self::startedAgent();
        self::confirm(self::IMPERSONATED_TOKEN, self::ADMIN_ID);
        self::assertRefused($agent, self::IMPERSONATED_KEY, 'Only an active administrator can do this');
    }

    /**
     * Only the ordering administrator's own session gets the file while it is kept; nothing is cached, and a
     * token in the address is no identity.
     *
     * @throws HilosException When a fixture, the order, a tick or a request fails
     */
    public function testTheDownloadAnswersOnlyTheAdministratorsOwnSession(): void
    {
        self::seedAcceptances();
        $agent = self::startedAgent();
        self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID);
        self::order($agent, self::ADMIN_KEY, null, null, 'kovalenko');
        $this->finish($agent, self::ADMIN_ID);
        $export = self::exportOf(self::ADMIN_ID);
        self::assertSame(LegalAcceptancesExportState::READY, $export?->state);

        foreach ([
            'no session' => [null, 401],
            'unknown session' => ['legal-export-unknown-session', 401],
            'expired session' => [self::EXPIRED_TOKEN, 401],
            'anonymous session' => [self::ANONYMOUS_TOKEN, 401],
            'impersonated session' => [self::IMPERSONATED_TOKEN, 403],
            'no administrator' => [self::PERSON_TOKEN, 403],
            "another administrator's file only" => [self::OTHER_ADMIN_TOKEN, 404],
            'the ordering administrator' => [self::ADMIN_TOKEN, 200],
        ] as $label => [$token, $status]) {
            $reply = self::download($agent, $token);
            self::assertSame($status, $reply->status, $label);
            self::assertStringContainsString('no-store', $reply->headers['Cache-Control'], $label);
        }
        $reply = self::download($agent, self::ADMIN_TOKEN);
        self::assertSame('text/csv; charset=utf-8', $reply->headers['Content-Type']);
        self::assertStringStartsWith(
            'attachment; filename="legal-acceptances-' . substr((string) $export->finishedAt, 0, 10) . '.csv"',
            $reply->headers['Content-Disposition'],
        );
        self::assertSame(file_get_contents($this->directory . '/' . $export->storedName), $reply->body);
        self::assertCount(201, self::linesOf($this->directory . '/' . $export->storedName));

        $agent->onSignalHttpRequest(
            new HttpRequestDTO('request', 'GET', LegalAcceptancesExportHttp::DOWNLOAD_PATH, ['token' => self::ADMIN_TOKEN], null, null),
            '',
            '',
        );
        self::assertSame(401, $agent->httpReply?->status, 'A query-string token is never an identity');

        Hilos::$db->legalAcceptanceExports->actions->order(self::OTHER_ADMIN_ID, null, null, null, self::PAST)
            ->actions->finishReady('expired.csv', 3, 0, self::PAST, '2026-01-02 00:00:00', 'UTC');
        file_put_contents($this->directory . '/expired.csv', self::BOM);
        self::assertSame(404, self::download($agent, self::OTHER_ADMIN_TOKEN)->status, 'An expired file is not served before the sweep');

        self::order($agent, self::ADMIN_KEY);
        self::assertSame(404, self::download($agent, self::ADMIN_TOKEN)->status, 'An order being prepared has no file');
    }

    /** @return iterable<string, array{string, string}> Policy, and the zone it gives the ordering administrator */
    public static function buildZones(): iterable
    {
        yield 'recipient' => ['recipient', self::ADMIN_ZONE];
        yield 'project' => ['project', self::PROJECT_ZONE];
        yield 'utc' => ['utc', TimeConstants::UTC_TIME_ZONE];
    }

    /**
     * The zone is chosen once, for the administrator who ordered, when the file opens: every part is written in
     * it though the administrator's zone and the policy change between the parts, the people the lines list keep
     * zones of their own, and the ready order keeps the zone's name.
     *
     * @param string $mode Server text policy while the file opens
     * @param string $zone Zone that policy gives the ordering administrator
     * @throws HilosException When a fixture, the order or a tick fails
     */
    #[DataProvider('buildZones')]
    public function testEveryPartIsWrittenInTheZoneChosenForTheAdministratorAtTheStart(string $mode, string $zone): void
    {
        self::seedAcceptances();
        Database::sqlRun('UPDATE `hilos_user` SET `time_zone` = ? WHERE `id` = ?', [self::ADMIN_ZONE, self::ADMIN_ID]);
        Database::sqlRun(
            'UPDATE `hilos_user` SET `time_zone` = ? WHERE `id` IN (' . implode(', ', array_keys(self::PEOPLE)) . ')',
            [self::PEOPLE_ZONE],
        );
        $this->withZoneEnvironment($mode, self::PROJECT_ZONE, function () use ($mode): void {
            $agent = self::startedAgent();
            self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID);
            self::order($agent, self::ADMIN_KEY);
            $agent->onTick();
            $agent->onTick();
            $agent->onTick();
            self::assertCount(self::PART, self::linesOf($this->buildingFile()));

            Database::sqlRun('UPDATE `hilos_user` SET `time_zone` = ? WHERE `id` = ?', [self::LATER_ZONE, self::ADMIN_ID]);
            $this->withZoneEnvironment($mode === 'utc' ? 'recipient' : 'utc', self::LATER_ZONE, function () use ($agent): void {
                self::assertSame(2, $this->finish($agent, self::ADMIN_ID), 'Two more parts finish the file');
            });
        });

        $export = self::exportOf(self::ADMIN_ID);
        self::assertSame(LegalAcceptancesExportState::READY, $export?->state);
        self::assertSame($zone, $export->timeZone);
        Database::sql('SELECT `time_zone` FROM `hilos_legal_acceptance_export` WHERE `user_id` = ?', [self::ADMIN_ID]);
        self::assertSame([['time_zone' => $zone]], Database::rows());
        $lines = self::linesOf($this->directory . '/' . $export->storedName);
        self::assertCount(self::RECORDS, $lines);
        self::assertSame(self::expectedLines(static fn (): bool => true, new DateTimeZone($zone)), $lines);
        self::assertWindowMatches($lines, null, null, null, new DateTimeZone($zone));
    }

    /**
     * The download is named by the day the file became ready in the zone saved with it, and in UTC for a ready
     * file older than the saved zone: neither the administrator's zone of the moment nor the policy moves the
     * name, before or after a restart.
     *
     * @throws HilosException When a fixture, a start or a download fails
     */
    public function testTheDownloadIsNamedInTheZoneSavedWithTheReadyFile(): void
    {
        mkdir($this->directory);
        $future = date('Y-m-d H:i:s', time() + self::SECONDS_PER_DAY);
        Database::sqlRun(
            'INSERT INTO `hilos_legal_acceptance_export` (`user_id`, `state`, `requested_at`, `finished_at`, `expires_at`, `stored_name`, '
            . "`size_bytes`, `time_zone`, `records`) VALUES (?, 'ready', ?, ?, ?, 'older.csv', 3, NULL, 0)",
            [self::OTHER_ADMIN_ID, self::LATE_EVENING, self::LATE_EVENING, $future],
        );
        Hilos::$db->legalAcceptanceExports->actions->order(self::ADMIN_ID, null, null, null, self::LATE_EVENING)
            ->actions->finishReady('saved.csv', 3, 0, self::LATE_EVENING, $future, self::SAVED_ZONE);
        file_put_contents($this->directory . '/saved.csv', self::BOM);
        file_put_contents($this->directory . '/older.csv', self::BOM);
        Database::sqlRun('UPDATE `hilos_user` SET `time_zone` = ? WHERE `id` = ?', [self::ADMIN_ZONE, self::ADMIN_ID]);
        Database::sqlRun('UPDATE `hilos_user` SET `time_zone` = ? WHERE `id` = ?', [self::PEOPLE_ZONE, self::OTHER_ADMIN_ID]);

        $names = [];
        foreach (['recipient', 'project', 'utc'] as $mode) {
            $this->withZoneEnvironment($mode, self::LATER_ZONE, function () use ($mode, &$names): void {
                $agent = self::startedAgent();
                foreach ([self::ADMIN_TOKEN, self::OTHER_ADMIN_TOKEN] as $token) {
                    $reply = self::download($agent, $token);
                    self::assertSame(200, $reply->status, $mode);
                    $names[$mode][] = $reply->headers['Content-Disposition'];
                }
                $agent->onStop();
            });
        }

        $named = [self::attachment('2026-10-23'), self::attachment('2026-10-22')];
        self::assertSame(['recipient' => $named, 'project' => $named, 'utc' => $named], $names);
    }

    /**
     * The start sweeps expired orders with their files and every file no ready order keeps, a stray building
     * file included; the cluster directory marker is the framework's and stays.
     *
     * @throws HilosException When a fixture or the start fails
     */
    public function testStartupSweepsExpiredOrdersAndOrphanFilesButKeepsTheMarker(): void
    {
        mkdir($this->directory);
        $future = date('Y-m-d H:i:s', time() + self::SECONDS_PER_DAY);
        $exports = Hilos::$db->legalAcceptanceExports->actions;
        $exports->order(self::ADMIN_ID, null, null, null, self::PAST)->actions->finishReady('kept.csv', 4, 0, self::PAST, $future, 'UTC');
        $exports->order(self::OTHER_ADMIN_ID, null, null, null, self::PAST)->actions->finishReady('expired.csv', 4, 0, self::PAST, self::PAST, 'UTC');
        $exports->order(self::THIRD_ADMIN_ID, null, null, null, self::PAST)->actions->finishFailed(self::PAST, self::PAST);
        $exports->order(self::PERSON_ID, null, null, null, self::PAST);
        foreach (['kept.csv', 'expired.csv', 'orphan.csv', 'f00d' . self::BUILDING_SUFFIX, ClusterDirectoryMarker::FILE_NAME] as $name) {
            file_put_contents($this->directory . '/' . $name, 'file');
        }

        self::startedAgent();

        self::assertSame(['kept.csv'], $this->files());
        self::assertFileExists(ClusterDirectoryMarker::pathIn($this->directory));
        self::assertSame(LegalAcceptancesExportState::READY, self::exportOf(self::ADMIN_ID)?->state);
        self::assertNull(self::exportOf(self::OTHER_ADMIN_ID));
        self::assertNull(self::exportOf(self::THIRD_ADMIN_ID));
        self::assertSame(LegalAcceptancesExportState::PREPARING, self::exportOf(self::PERSON_ID)?->state);
    }

    /**
     * A ready order whose file is gone is no order: the start removes it and keeps the one whose file is there.
     *
     * @throws HilosException When a fixture or the start fails
     */
    public function testStartupRemovesAReadyOrderWhoseFileIsGone(): void
    {
        mkdir($this->directory);
        $future = date('Y-m-d H:i:s', time() + self::SECONDS_PER_DAY);
        $exports = Hilos::$db->legalAcceptanceExports->actions;
        $exports->order(self::ADMIN_ID, null, null, null, self::PAST)->actions->finishReady('missing.csv', 4, 0, self::PAST, $future, 'UTC');
        $exports->order(self::OTHER_ADMIN_ID, null, null, null, self::PAST)->actions->finishReady('present.csv', 4, 0, self::PAST, $future, 'UTC');
        file_put_contents($this->directory . '/present.csv', 'file');

        self::startedAgent();

        self::assertNull(self::exportOf(self::ADMIN_ID));
        self::assertSame(LegalAcceptancesExportState::READY, self::exportOf(self::OTHER_ADMIN_ID)?->state);
        self::assertSame(['present.csv'], $this->files());
    }

    /**
     * An erased account takes every finished file with it, and the file being built is started again from its
     * first line: the part already written named the erased person, the rebuilt file does not.
     *
     * @throws HilosException When a fixture, the order, a tick or the erasure fails
     */
    public function testAnErasureRemovesFinishedFilesAndRebuildsTheOneInProgress(): void
    {
        self::seedAcceptances();
        mkdir($this->directory);
        $future = date('Y-m-d H:i:s', time() + self::SECONDS_PER_DAY);
        $exports = Hilos::$db->legalAcceptanceExports->actions;
        $exports->order(self::OTHER_ADMIN_ID, null, null, null, self::PAST)->actions->finishReady('other.csv', 4, 1, self::PAST, $future, 'UTC');
        $exports->order(self::THIRD_ADMIN_ID, null, null, null, self::PAST)->actions->finishFailed(self::PAST, $future);
        file_put_contents($this->directory . '/other.csv', 'file');
        $agent = self::startedAgent();
        self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID);
        self::order($agent, self::ADMIN_KEY);
        $id = self::exportOf(self::ADMIN_ID)?->id;
        $agent->onTick();
        $agent->onTick();
        $agent->onTick();
        $building = $this->buildingFile();
        self::assertContains(self::PERSON_CELLS[self::PERSON_ID], array_map(self::personCellsOf(...), self::linesOf($building)));

        Database::sqlRun('DELETE FROM `hilos_legal_acceptance` WHERE `user_id` = ?', [self::PERSON_ID]);
        $agent->onSignalAgent(
            new AgentSignalData(new LegalAcceptancesExportForgetSignalData(self::PERSON_ID)),
            'hilos_users',
            HilosSignalConstants::HILOS_LEGAL_ACCEPTANCES_EXPORT_FORGET,
        );

        self::assertNull(self::exportOf(self::OTHER_ADMIN_ID));
        self::assertNull(self::exportOf(self::THIRD_ADMIN_ID));
        self::assertSame([], $this->files());
        self::assertSame($id, self::exportOf(self::ADMIN_ID)?->id);
        self::assertSame(LegalAcceptancesExportState::PREPARING, self::exportOf(self::ADMIN_ID)?->state);
        self::assertSame(4, $this->finish($agent, self::ADMIN_ID));
        $export = self::exportOf(self::ADMIN_ID);
        self::assertSame(LegalAcceptancesExportState::READY, $export?->state);
        $lines = self::linesOf($this->directory . '/' . $export->storedName);
        self::assertSame(self::expectedLines(static fn (): bool => true, self::utc()), $lines);
        self::assertCount(self::RECORDS - 201, $lines);
        self::assertNotContains(self::PERSON_CELLS[self::PERSON_ID], array_map(self::personCellsOf(...), $lines));
        self::assertSame(self::RECORDS - 201, $export->records);
    }

    /**
     * Erasure deletes an administrator's own order before the forget frame removes its file.
     *
     * @throws HilosException When a fixture, the order, a tick or the erasure fails
     */
    public function testAnErasedAdministratorsOwnOrderGoes(): void
    {
        self::seedAcceptances();
        $agent = self::startedAgent();
        self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID);
        self::order($agent, self::ADMIN_KEY);
        $agent->onTick();
        $agent->onTick();
        $agent->onTick();
        self::assertCount(1, $this->files());

        self::exportOf(self::ADMIN_ID)?->actions->delete();
        LegalAcceptancesExports::forget($agent, self::ADMIN_ID);

        self::assertNull(self::exportOf(self::ADMIN_ID));
        self::assertSame([], $this->files());
        $agent->onTick();
        $agent->onTick();
        self::assertSame([], $this->files());
        self::assertSame(0, self::exportRowCount());
        self::assertSame([], self::toasts());
    }

    /** @return iterable<string, array{string}> State of the erased administrator's own order */
    public static function ownOrderStates(): iterable
    {
        yield 'ready, its file on disk' => [LegalAcceptancesExportState::READY];
        yield 'failed' => [LegalAcceptancesExportState::FAILED];
        yield 'preparing, its file being built' => [LegalAcceptancesExportState::PREPARING];
    }

    /**
     * The participant step deletes the erased administrator's own order, whatever its state, with every finished
     * export around it, and records both counts; after the commit its file or the file being built is gone too,
     * and the order that went says nothing more.
     *
     * @param string $state State of the erased administrator's own order
     * @throws HilosException When a fixture, the order, a tick or the step fails
     */
    #[DataProvider('ownOrderStates')]
    public function testTheErasureStepDeletesTheAdministratorsOwnOrderAndItsFiles(string $state): void
    {
        $agent = self::startedAgent();
        $this->seedFinished(self::OTHER_ADMIN_ID, 'other.csv');
        if ($state === LegalAcceptancesExportState::PREPARING) {
            self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID);
            self::order($agent, self::ADMIN_KEY);
            $agent->onTick();
            $agent->onTick();
            $this->buildingFile();
        } else {
            $this->seedFinished(self::ADMIN_ID, $state === LegalAcceptancesExportState::READY ? 'own.csv' : null);
        }
        self::assertSame($state, self::exportOf(self::ADMIN_ID)?->state);
        $assignmentId = self::legalPart(self::openErasure(self::ADMIN_ID), self::ADMIN_ID);

        $result = self::erase($agent, $assignmentId);

        self::assertSame('{"ownOrders":1,"sharedExports":1}', $result->payloadJson);
        self::assertSame(['ownOrders' => 1, 'sharedExports' => 1], LegalAcceptancesExportErasureResult::countsOf($result->payload));
        self::assertSame([[$assignmentId, HilosAgentType::HILOS_LEGAL, '{"ownOrders":1,"sharedExports":1}']], self::storedResults());
        self::assertSame(0, self::exportRowCount());
        self::assertSame([], $this->files());
        $agent->onTick();
        $agent->onTick();
        self::assertSame([], $this->files());
        self::assertSame(0, self::exportRowCount());
        self::assertSame([], self::toasts());
    }

    /**
     * Erasing a person who orders nothing deletes the finished exports of two administrators with their files, and
     * the third administrator's file being built starts again from its first line: the shared export is not lost,
     * and the rebuilt file no longer names the person.
     *
     * @throws HilosException When a fixture, the order, a tick or the step fails
     */
    public function testTheErasureStepDeletesSharedExportsAndRebuildsTheOneInProgress(): void
    {
        self::seedAcceptances();
        $agent = self::startedAgent();
        $this->seedFinished(self::OTHER_ADMIN_ID, 'other.csv');
        $this->seedFinished(self::THIRD_ADMIN_ID, null);
        self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID);
        self::order($agent, self::ADMIN_KEY);
        $id = self::exportOf(self::ADMIN_ID)?->id;
        $agent->onTick();
        $agent->onTick();
        $agent->onTick();
        self::assertContains(self::PERSON_CELLS[self::PERSON_ID], array_map(self::personCellsOf(...), self::linesOf($this->buildingFile())));
        Database::sqlRun('DELETE FROM `hilos_legal_acceptance` WHERE `user_id` = ?', [self::PERSON_ID]);
        $assignmentId = self::legalPart(self::openErasure(self::PERSON_ID), self::PERSON_ID);

        $result = self::erase($agent, $assignmentId);

        self::assertSame('{"ownOrders":0,"sharedExports":2}', $result->payloadJson);
        self::assertSame([[$assignmentId, HilosAgentType::HILOS_LEGAL, '{"ownOrders":0,"sharedExports":2}']], self::storedResults());
        self::assertNull(self::exportOf(self::OTHER_ADMIN_ID));
        self::assertNull(self::exportOf(self::THIRD_ADMIN_ID));
        self::assertSame([], $this->files());
        self::assertSame($id, self::exportOf(self::ADMIN_ID)?->id);
        self::assertSame(LegalAcceptancesExportState::PREPARING, self::exportOf(self::ADMIN_ID)?->state);
        self::assertSame(4, $this->finish($agent, self::ADMIN_ID));
        $export = self::exportOf(self::ADMIN_ID);
        self::assertSame(LegalAcceptancesExportState::READY, $export?->state);
        $lines = self::linesOf($this->directory . '/' . $export->storedName);
        self::assertSame(self::expectedLines(static fn (): bool => true, self::utc()), $lines);
        self::assertCount(self::RECORDS - 201, $lines);
        self::assertNotContains(self::PERSON_CELLS[self::PERSON_ID], array_map(self::personCellsOf(...), $lines));
    }

    /**
     * With no order of the person and no finished export around, the step records none of either, and the file
     * being built still starts again.
     *
     * @throws HilosException When a fixture, the order, a tick or the step fails
     */
    public function testTheErasureStepWithNothingToDeleteStillRestartsTheBuild(): void
    {
        $agent = self::startedAgent();
        self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID);
        self::order($agent, self::ADMIN_KEY);
        $id = self::exportOf(self::ADMIN_ID)?->id;
        $agent->onTick();
        $agent->onTick();
        $building = $this->buildingFile();
        $assignmentId = self::legalPart(self::openErasure(self::PERSON_ID), self::PERSON_ID);

        $result = self::erase($agent, $assignmentId);

        self::assertSame('{"ownOrders":0,"sharedExports":0}', $result->payloadJson);
        self::assertSame(['ownOrders' => 0, 'sharedExports' => 0], LegalAcceptancesExportErasureResult::countsOf($result->payload));
        self::assertFileDoesNotExist($building);
        self::assertSame([], $this->files());
        self::assertSame($id, self::exportOf(self::ADMIN_ID)?->id);
        self::assertSame(2, $this->finish($agent, self::ADMIN_ID));
        self::assertSame(LegalAcceptancesExportState::READY, self::exportOf(self::ADMIN_ID)?->state);
    }

    /**
     * A repeat answers with the saved result and deletes nothing: an export finished after the first call stays.
     *
     * @throws HilosException When a fixture or the step fails
     */
    public function testARepeatedErasureStepAnswersTheSavedResultAndDeletesNothing(): void
    {
        $agent = self::startedAgent();
        $this->seedFinished(self::ADMIN_ID, 'own.csv');
        $assignmentId = self::legalPart(self::openErasure(self::ADMIN_ID), self::ADMIN_ID);
        $first = self::erase($agent, $assignmentId);
        $this->seedFinished(self::OTHER_ADMIN_ID, 'later.csv');

        $repeat = self::erase($agent, $assignmentId);

        self::assertSame($first->id, $repeat->id);
        self::assertSame('{"ownOrders":1,"sharedExports":0}', $repeat->payloadJson);
        self::assertSame([[$assignmentId, HilosAgentType::HILOS_LEGAL, '{"ownOrders":1,"sharedExports":0}']], self::storedResults());
        self::assertSame(LegalAcceptancesExportState::READY, self::exportOf(self::OTHER_ADMIN_ID)?->state);
        self::assertSame(['later.csv'], $this->files());
    }

    /**
     * While the person's acceptances are still stored the step refuses and touches nothing, since a file built
     * after it could list them; once they are gone the step is carried, and its result is written once.
     *
     * @throws HilosException When a fixture or the step fails
     */
    public function testTheErasureStepWaitsForThePersonsAcceptancesToGo(): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_legal_acceptance` (`user_id`, `document`, `revision_id`, `accepted_at`) VALUES (?, ?, ?, ?)',
            [self::PERSON_ID, 'terms', 'old', self::FIRST_ACCEPTANCE],
        );
        $agent = self::startedAgent();
        $this->seedFinished(self::OTHER_ADMIN_ID, 'other.csv');
        $this->seedFinished(self::THIRD_ADMIN_ID, null);
        $assignmentId = self::legalPart(self::openErasure(self::PERSON_ID), self::PERSON_ID);

        $this->assertErasureRefused($agent, $assignmentId, 'A legal export erasure part waits for the account\'s acceptances to go.');

        Database::sqlRun('DELETE FROM `hilos_legal_acceptance` WHERE `user_id` = ?', [self::PERSON_ID]);
        $result = self::erase($agent, $assignmentId);
        self::assertSame('{"ownOrders":0,"sharedExports":2}', $result->payloadJson);
        self::assertSame([[$assignmentId, HilosAgentType::HILOS_LEGAL, '{"ownOrders":0,"sharedExports":2}']], self::storedResults());
        self::assertSame(0, self::exportRowCount());
        self::assertSame([], $this->files());
    }

    /**
     * A legal export part assigned inside a merge is refused before any work.
     *
     * @throws HilosException When a fixture or the step fails otherwise
     */
    public function testAnErasureStepAssignedInAMergeIsRefused(): void
    {
        $agent = self::startedAgent();
        $this->seedFinished(self::OTHER_ADMIN_ID, 'other.csv');
        $this->seedFinished(self::THIRD_ADMIN_ID, null);
        $merge = self::asCoordinator(static fn (): AccountOperation => Hilos::$db->accountOperations->actions->recordMerge(
            102,
            self::PERSON_ID,
            new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false),
        ));

        $this->assertErasureRefused($agent, self::legalPart($merge, self::PERSON_ID), 'A legal export erasure part must belong to an erasure.');
    }

    /**
     * The part asked of the agent in the session holder's frame is somebody else's assignment there.
     *
     * @throws HilosException When a fixture or the step fails otherwise
     */
    public function testAnErasureStepInAnotherAgentsFrameIsRefused(): void
    {
        $agent = self::startedAgent();
        $this->seedFinished(self::OTHER_ADMIN_ID, 'other.csv');
        $this->seedFinished(self::THIRD_ADMIN_ID, null);
        $assignmentId = self::legalPart(self::openErasure(self::PERSON_ID), self::PERSON_ID);

        try {
            self::asCoordinator(static fn (): AccountOperationResult => LegalAcceptancesExports::eraseAccountPart($agent, $assignmentId));
            self::fail('The part was carried in a foreign frame');
        } catch (AccountOperationStepRefusedException $refusal) {
            self::assertSame(AccountOperationStepRefusal::ForeignAssignment, $refusal->reason);
        }

        $this->assertNothingErased();
    }

    /**
     * A deletion failing after the person's own order went rolls back that order, the exports already deleted
     * and the result together, and leaves every file where it was.
     *
     * @throws HilosException When a fixture or the step fails otherwise
     */
    public function testAFailedErasureStepRollsBackItsDeletionsAndKeepsTheFiles(): void
    {
        $agent = self::startedAgent();
        $this->seedFinished(self::ADMIN_ID, 'own.csv');
        $this->seedFinished(self::OTHER_ADMIN_ID, 'other.csv');
        $this->seedFinished(self::THIRD_ADMIN_ID, null);
        Database::sqlRun(
            'CREATE TABLE `' . self::PIN_TABLE . '` (`export_id` INT UNSIGNED NOT NULL, FOREIGN KEY (`export_id`) '
            . 'REFERENCES `hilos_legal_acceptance_export` (`id`)) ENGINE=InnoDB',
        );
        Database::sqlRun('INSERT INTO `' . self::PIN_TABLE . '` (`export_id`) VALUES (?)', [self::exportOf(self::THIRD_ADMIN_ID)?->id]);
        $assignmentId = self::legalPart(self::openErasure(self::ADMIN_ID), self::ADMIN_ID);

        try {
            self::erase($agent, $assignmentId);
            self::fail('The pinned export was deleted');
        } catch (DatabaseException) {
            // The pinned export refuses its deletion after the administrator's own order went.
        }

        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame(LegalAcceptancesExportState::READY, self::exportOf(self::ADMIN_ID)?->state);
        self::assertSame(LegalAcceptancesExportState::READY, self::exportOf(self::OTHER_ADMIN_ID)?->state);
        self::assertSame(LegalAcceptancesExportState::FAILED, self::exportOf(self::THIRD_ADMIN_ID)?->state);
        self::assertSame(3, self::exportRowCount());
        self::assertSame(['other.csv', 'own.csv'], $this->files());
        self::assertSame([], self::storedResults());
    }

    /**
     * An administrator whose erasure has begun cannot order a new file: the order is refused in the erasing words,
     * and the ready order already there stays with its file.
     *
     * @throws HilosException When a fixture or the order fails otherwise
     */
    public function testAnAdministratorWhoseErasureHasBegunCannotOrder(): void
    {
        $agent = self::startedAgent();
        $this->seedFinished(self::ADMIN_ID, 'own.csv');
        self::requestDeletion(self::ADMIN_ID, 1767225660000);
        self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID);

        try {
            self::order($agent, self::ADMIN_KEY);
            self::fail('The order must be refused');
        } catch (ValidationException $e) {
            self::assertSame('This account is being deleted.', $e->getMessage());
        }

        self::assertSame(LegalAcceptancesExportState::READY, self::exportOf(self::ADMIN_ID)?->state);
        self::assertSame(1, self::exportRowCount());
        self::assertSame(['own.csv'], $this->files());
    }

    /**
     * A deletion scheduled but not yet begun leaves the administrator ordering as before.
     *
     * @throws HilosException When a fixture or the order fails
     */
    public function testAnAdministratorWhoseDeletionIsOnlyScheduledStillOrders(): void
    {
        $agent = self::startedAgent();
        self::requestDeletion(self::ADMIN_ID, null);
        self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID);

        self::order($agent, self::ADMIN_KEY);

        self::assertSame(LegalAcceptancesExportState::PREPARING, self::exportOf(self::ADMIN_ID)?->state);
    }

    /** The agent claims the results of its own erasure steps by its id and reads the operations, not the results. */
    public function testTheAgentClaimsItsErasureResultsAndReadsTheOperations(): void
    {
        $agent = new LegalExportIntegrationAgent();

        self::assertSame([TruthSourceOperation::Add], LegalExportIntegrationAgent::OWNS_DB_SET[HilosDbContext::accountOperationResults]);
        self::assertSame('hilos_legal', $agent->ownedDbSetKey(HilosDbContext::accountOperationResults));
        self::assertSame('', $agent->ownedDbSetKey(HilosDbContext::legalAcceptanceExports));
        self::assertContains(HilosDbContext::accountOperationAssignments, LegalExportIntegrationAgent::READS_DB);
        self::assertContains(HilosDbContext::accountOperations, LegalExportIntegrationAgent::READS_DB);
        self::assertNotContains(HilosDbContext::accountOperationResults, LegalExportIntegrationAgent::READS_DB);
    }

    /**
     * A restarted agent builds an unfinished order again from its first line and says nothing about it.
     *
     * @throws HilosException When a fixture, the order, a tick or the restart fails
     */
    public function testARestartRebuildsTheOrderWithoutAToast(): void
    {
        self::seedAcceptances();
        $agent = self::startedAgent();
        self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID);
        self::order($agent, self::ADMIN_KEY);
        $agent->onTick();
        $agent->onTick();
        $agent->onTick();
        self::assertCount(self::PART, self::linesOf($this->buildingFile()));

        $agent->onStop();
        $restarted = self::startedAgent();

        self::assertSame([], $this->files(), 'The start sweeps the abandoned part');
        self::assertSame(4, $this->finish($restarted, self::ADMIN_ID));
        $export = self::exportOf(self::ADMIN_ID);
        self::assertSame(LegalAcceptancesExportState::READY, $export?->state);
        self::assertSame(self::expectedLines(static fn (): bool => true, self::utc()), self::linesOf($this->directory . '/' . $export->storedName));
        self::assertSame([], self::toasts());
    }

    /**
     * An idle tick does not ask the database for orders: only an order through the agent, a start, or an
     * erasure dropping the build in progress sends it there. An order written behind the agent's back,
     * which nothing in production does, waits for the next start.
     *
     * @throws HilosException When a fixture, a tick or the start fails
     */
    public function testAnIdleTickLeavesTheQueueUnreadUntilTheNextStart(): void
    {
        $agent = self::startedAgent();
        $agent->onTick();
        $agent->onTick();
        Hilos::$db->legalAcceptanceExports->actions->order(self::ADMIN_ID, null, null, null, self::PAST);
        $agent->onTick();
        $agent->onTick();
        self::assertSame(LegalAcceptancesExportState::PREPARING, self::exportOf(self::ADMIN_ID)?->state);
        self::assertSame([], $this->files());

        LegalAcceptancesExports::reset();
        $this->finish(self::startedAgent(), self::ADMIN_ID);
        self::assertSame(LegalAcceptancesExportState::READY, self::exportOf(self::ADMIN_ID)?->state);
    }

    /**
     * A build that cannot go on removes what it wrote, records the order failed and warns the ordering browser.
     *
     * @throws HilosException When a fixture, the order, a tick or a request fails
     */
    public function testAFailedBuildRemovesItsFileAndRaisesAWarning(): void
    {
        self::seedAcceptances();
        $agent = self::startedAgent();
        self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID);
        self::order($agent, self::ADMIN_KEY);
        $agent->onTick();
        $agent->onTick();
        self::assertCount(1, $this->files());

        Hilos::$table = new LegalExportIntegrationNoTables();
        Hilos::$table->configure();
        $agent->onTick();

        $export = self::exportOf(self::ADMIN_ID);
        self::assertSame(LegalAcceptancesExportState::FAILED, $export?->state);
        self::assertNull($export->storedName);
        self::assertNull($export->records);
        self::assertNotNull($export->finishedAt);
        self::assertNotNull($export->expiresAt);
        self::assertSame([], $this->files());
        $toasts = self::toasts();
        self::assertCount(1, $toasts);
        self::assertSame(ProtectedModeRuntime::hashSessionToken(self::ADMIN_TOKEN), $toasts[0]->sessionTokenHash);
        self::assertSame(self::ADMIN_ID, $toasts[0]->addresseeUserId);
        self::assertSame('We could not prepare the export of legal acceptances.', $toasts[0]->message);
        self::assertSame(SessionToastSeverity::WARNING, $toasts[0]->severity);
        self::assertSame('Legal', $toasts[0]->source);
        self::assertSame('/hilos/legal/acceptances', $toasts[0]->destination);
        self::assertSame(404, self::download($agent, self::ADMIN_TOKEN)->status);
        $agent->onTick();
        self::assertSame(LegalAcceptancesExportState::FAILED, self::exportOf(self::ADMIN_ID)?->state, 'A failure does not retry itself');
    }

    /**
     * A builder finishing an order that a newer one replaced records neither its file nor its time zone,
     * and the order that replaced it keeps the zone its own file was written in.
     *
     * @throws HilosException When an order or its completion fails
     */
    public function testALateCompletionOfAReplacedOrderRecordsNoTimeZone(): void
    {
        $exports = Hilos::$db->legalAcceptanceExports->actions;
        $old = $exports->order(self::ADMIN_ID, null, null, null, self::PAST);
        $new = $exports->order(self::ADMIN_ID, null, null, null, self::PAST);
        self::assertNull($new->timeZone);

        self::assertFalse($old->actions->finishReady('stale.csv', 3, 1, self::PAST, self::PAST, 'Asia/Tokyo'));
        self::assertNull($old->timeZone);
        self::assertNull($old->storedName);
        self::assertTrue($new->actions->finishReady('current.csv', 4, 2, self::PAST, self::PAST, 'America/New_York'));
        self::assertFalse($new->actions->finishFailed(self::PAST, self::PAST));

        self::assertSame(LegalAcceptancesExportState::READY, $new->state);
        self::assertSame('America/New_York', $new->timeZone);
        Database::sql(
            'SELECT `stored_name`, `time_zone` FROM `hilos_legal_acceptance_export` WHERE `user_id` = ?',
            [self::ADMIN_ID],
        );
        self::assertSame([['stored_name' => 'current.csv', 'time_zone' => 'America/New_York']], Database::rows());
    }

    /**
     * Target case for Object/View allReady, expiredBy, and allFinished returning static collections
     * with exact subsets, primary keys, empty results, instance identity, and no source changes.
     *
     * @throws HilosException When creating fixtures or querying collections fails
     */
    public function testCollectionsReturnStaticSubsetsWithoutPublishingSourceChanges(): void
    {
        mkdir($this->directory);
        $past = '2026-01-01 00:00:00';
        $now = '2026-06-01 00:00:00';
        $future = '2026-12-01 00:00:00';

        $exports = Hilos::$db->legalAcceptanceExports->actions;

        // 1. PREPARING order
        $preparing = $exports->order(self::ADMIN_ID, null, null, null, $past);
        $preparingId = $preparing->id;
        self::assertIsInt($preparingId);
        self::assertNull($preparing->timeZone);

        // 2. READY order, expired (expiresAt <= $now)
        $readyExpired = $exports->order(self::OTHER_ADMIN_ID, null, null, null, $past);
        $readyExpired->actions->finishReady('ready-expired.csv', 10, 100, $past, '2026-05-01 00:00:00', 'Europe/Warsaw');
        self::assertSame('Europe/Warsaw', $readyExpired->timeZone);
        $readyExpiredId = $readyExpired->id;
        self::assertIsInt($readyExpiredId);

        // 3. READY order, active (expiresAt > $now)
        $readyActive = $exports->order(self::THIRD_ADMIN_ID, null, null, null, $past);
        $readyActive->actions->finishReady('ready-active.csv', 20, 200, $past, $future, 'Pacific/Auckland');
        self::assertSame('Pacific/Auckland', $readyActive->timeZone);
        $readyActiveId = $readyActive->id;
        self::assertIsInt($readyActiveId);

        // 4. FAILED order, expired (expiresAt <= $now)
        $failedExpired = $exports->order(self::PERSON_ID, null, null, null, $past);
        $failedExpired->actions->finishFailed($past, '2026-04-01 00:00:00');
        self::assertNull($failedExpired->timeZone);
        $failedExpiredId = $failedExpired->id;
        self::assertIsInt($failedExpiredId);

        // Cache view items and object items
        $heldPreparingView = Hilos::$db->legalAcceptanceExports[$preparingId];
        $heldReadyExpiredView = Hilos::$db->legalAcceptanceExports[$readyExpiredId];
        $heldReadyActiveView = Hilos::$db->legalAcceptanceExports[$readyActiveId];
        $heldFailedExpiredView = Hilos::$db->legalAcceptanceExports[$failedExpiredId];

        self::assertInstanceOf(LegalAcceptanceExport::class, $heldPreparingView);
        self::assertInstanceOf(LegalAcceptanceExport::class, $heldReadyExpiredView);
        self::assertInstanceOf(LegalAcceptanceExport::class, $heldReadyActiveView);
        self::assertInstanceOf(LegalAcceptanceExport::class, $heldFailedExpiredView);

        $objectCollection = Hilos::$db->legalAcceptanceExports->getObjectCollection();
        self::assertInstanceOf(ObjectLegalAcceptanceExports::class, $objectCollection);

        $heldPreparingObj = $objectCollection[$preparingId];
        $heldReadyExpiredObj = $objectCollection[$readyExpiredId];
        $heldReadyActiveObj = $objectCollection[$readyActiveId];
        $heldFailedExpiredObj = $objectCollection[$failedExpiredId];

        self::assertInstanceOf(ObjectLegalAcceptanceExport::class, $heldPreparingObj);
        self::assertInstanceOf(ObjectLegalAcceptanceExport::class, $heldReadyExpiredObj);
        self::assertInstanceOf(ObjectLegalAcceptanceExport::class, $heldReadyActiveObj);
        self::assertInstanceOf(ObjectLegalAcceptanceExport::class, $heldFailedExpiredObj);

        // Subscribe test mirror to SourceChangeBus after fixture setup
        $mirror = new LegalAcceptancesExportTestMirror();
        SourceChangeBus::subscribe($mirror);

        // Test allReady (View and Object)
        $viewReady = Hilos::$db->legalAcceptanceExports->allReady();
        self::assertInstanceOf(ViewLegalAcceptanceExports::class, $viewReady);
        self::assertCount(2, $viewReady);
        self::assertArrayHasKey($readyExpiredId, $viewReady);
        self::assertArrayHasKey($readyActiveId, $viewReady);
        self::assertArrayNotHasKey($preparingId, $viewReady);
        self::assertArrayNotHasKey($failedExpiredId, $viewReady);
        self::assertSame($heldReadyExpiredView, $viewReady[$readyExpiredId]);
        self::assertSame($heldReadyActiveView, $viewReady[$readyActiveId]);

        $objectReady = $objectCollection->allReady();
        self::assertInstanceOf(ObjectLegalAcceptanceExports::class, $objectReady);
        self::assertCount(2, $objectReady);
        self::assertTrue(isset($objectReady[$readyExpiredId]));
        self::assertTrue(isset($objectReady[$readyActiveId]));
        self::assertFalse(isset($objectReady[$preparingId]));
        self::assertFalse(isset($objectReady[$failedExpiredId]));
        self::assertSame($heldReadyExpiredObj, $objectReady[$readyExpiredId]);
        self::assertSame($heldReadyActiveObj, $objectReady[$readyActiveId]);

        // Test expiredBy (View and Object)
        $viewExpired = Hilos::$db->legalAcceptanceExports->expiredBy($now);
        self::assertInstanceOf(ViewLegalAcceptanceExports::class, $viewExpired);
        self::assertCount(2, $viewExpired);
        self::assertArrayHasKey($readyExpiredId, $viewExpired);
        self::assertArrayHasKey($failedExpiredId, $viewExpired);
        self::assertArrayNotHasKey($preparingId, $viewExpired);
        self::assertArrayNotHasKey($readyActiveId, $viewExpired);
        self::assertSame($heldReadyExpiredView, $viewExpired[$readyExpiredId]);
        self::assertSame($heldFailedExpiredView, $viewExpired[$failedExpiredId]);

        $objectExpired = $objectCollection->expiredBy($now);
        self::assertInstanceOf(ObjectLegalAcceptanceExports::class, $objectExpired);
        self::assertCount(2, $objectExpired);
        self::assertTrue(isset($objectExpired[$readyExpiredId]));
        self::assertTrue(isset($objectExpired[$failedExpiredId]));
        self::assertFalse(isset($objectExpired[$preparingId]));
        self::assertFalse(isset($objectExpired[$readyActiveId]));
        self::assertSame($heldReadyExpiredObj, $objectExpired[$readyExpiredId]);
        self::assertSame($heldFailedExpiredObj, $objectExpired[$failedExpiredId]);

        // Test allFinished (View and Object)
        $viewFinished = Hilos::$db->legalAcceptanceExports->allFinished();
        self::assertInstanceOf(ViewLegalAcceptanceExports::class, $viewFinished);
        self::assertCount(3, $viewFinished);
        self::assertArrayHasKey($readyExpiredId, $viewFinished);
        self::assertArrayHasKey($readyActiveId, $viewFinished);
        self::assertArrayHasKey($failedExpiredId, $viewFinished);
        self::assertArrayNotHasKey($preparingId, $viewFinished);
        self::assertSame($heldReadyExpiredView, $viewFinished[$readyExpiredId]);
        self::assertSame($heldReadyActiveView, $viewFinished[$readyActiveId]);
        self::assertSame($heldFailedExpiredView, $viewFinished[$failedExpiredId]);

        $objectFinished = $objectCollection->allFinished();
        self::assertInstanceOf(ObjectLegalAcceptanceExports::class, $objectFinished);
        self::assertCount(3, $objectFinished);
        self::assertTrue(isset($objectFinished[$readyExpiredId]));
        self::assertTrue(isset($objectFinished[$readyActiveId]));
        self::assertTrue(isset($objectFinished[$failedExpiredId]));
        self::assertFalse(isset($objectFinished[$preparingId]));
        self::assertSame($heldReadyExpiredObj, $objectFinished[$readyExpiredId]);
        self::assertSame($heldReadyActiveObj, $objectFinished[$readyActiveId]);
        self::assertSame($heldFailedExpiredObj, $objectFinished[$failedExpiredId]);

        // Test empty result
        $viewEmpty = Hilos::$db->legalAcceptanceExports->expiredBy('2000-01-01 00:00:00');
        self::assertInstanceOf(ViewLegalAcceptanceExports::class, $viewEmpty);
        self::assertCount(0, $viewEmpty);
        self::assertNull($viewEmpty->first());
        $viewEmptyYielded = false;
        foreach ($viewEmpty as $item) {
            $viewEmptyYielded = true;
        }
        self::assertFalse($viewEmptyYielded);

        $objectEmpty = $objectCollection->expiredBy('2000-01-01 00:00:00');
        self::assertInstanceOf(ObjectLegalAcceptanceExports::class, $objectEmpty);
        self::assertCount(0, $objectEmpty);
        self::assertNull($objectEmpty->first());
        $objectEmptyYielded = false;
        foreach ($objectEmpty as $item) {
            $objectEmptyYielded = true;
        }
        self::assertFalse($objectEmptyYielded);

        // Verify mirror saw NO source changes
        self::assertSame([], $mirror->changes, 'Assembling static collections must not publish source changes');
    }

    /**
     * @return LegalExportIntegrationAgent A legal agent past its start
     * @throws HilosException When the start fails
     */
    private static function startedAgent(): LegalExportIntegrationAgent
    {
        $agent = new LegalExportIntegrationAgent();
        OwnershipDeclaration::claimAll($agent);
        $agent->onStart();

        return $agent;
    }

    /**
     * Gives an administrator a finished order: ready, its file on disk, when a file is named, failed otherwise.
     *
     * @param int $userId Administrator
     * @param ?string $storedName File of a ready order, or null for a failed one
     * @throws HilosException When the order cannot be written
     */
    private function seedFinished(int $userId, ?string $storedName): void
    {
        $future = date('Y-m-d H:i:s', time() + self::SECONDS_PER_DAY);
        $order = Hilos::$db->legalAcceptanceExports->actions->order($userId, null, null, null, self::PAST);
        if ($storedName === null) {
            self::assertTrue($order->actions->finishFailed(self::PAST, $future));

            return;
        }
        self::assertTrue($order->actions->finishReady($storedName, 4, 0, self::PAST, $future, 'UTC'));
        file_put_contents($this->directory . '/' . $storedName, 'file');
    }

    /**
     * @param int $userId Account the request is for
     * @param ?int $startedAt Moment its erasure began in epoch milliseconds, or null when it is only scheduled
     * @throws HilosException When the row cannot be written
     */
    private static function requestDeletion(int $userId, ?int $startedAt): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_account_deletion` (`user_id`, `requested_at`, `effective_at`, `started_at`) VALUES (?, ?, ?, ?)',
            [$userId, 1767225600000, 1767312000000, $startedAt],
        );
    }

    /**
     * @param int $userId Account erased alone
     * @return AccountOperation Its erasure, written by the session holder
     * @throws HilosException When the operation cannot be written
     */
    private static function openErasure(int $userId): AccountOperation
    {
        return self::asCoordinator(static fn (): AccountOperation => Hilos::$db->accountOperations->actions->recordErasure(
            self::REQUEST_ID,
            $userId,
            Hilos::$db->userMerges->erasureCircleOf($userId),
        ));
    }

    /**
     * Records the legal export part of an account on an operation, with the key, owner and payload the census
     * writes; runs in the session holder's frame.
     *
     * @param AccountOperation $operation Operation to record on, at its current stage
     * @param int $userId Account the part names
     * @return int Id of the assignment
     * @throws HilosException When the record refuses
     */
    private static function legalPart(AccountOperation $operation, int $userId): int
    {
        $expectation = new AccountOperationExpectation(
            AccountErasureParticipantCatalog::LEGAL_EXPORT,
            $userId,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_LEGAL),
        );

        return self::asCoordinator(static fn (): int => Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            $operation->stage,
            $expectation->assignmentKey(),
            $expectation->assignmentOwner(),
            $userId,
            $expectation->payload(),
        )->id);
    }

    /**
     * @param LegalExportIntegrationAgent $agent Agent carrying the part, in its own frame
     * @param int $assignmentId Assignment it carries
     * @return AccountOperationResult The recorded or saved result
     * @throws HilosException When the step refuses or fails
     */
    private static function erase(LegalExportIntegrationAgent $agent, int $assignmentId): AccountOperationResult
    {
        return ExecutionContext::run(
            new ExecutionFrame(agentId: $agent->getId()),
            static fn (): AccountOperationResult => LegalAcceptancesExports::eraseAccountPart($agent, $assignmentId),
        );
    }

    /**
     * Runs one write in the session holder's frame, which opens the erasure and writes its assignments.
     *
     * @template T
     * @param callable(): T $write Write to run as the holder
     * @return T Whatever the write returns
     */
    private static function asCoordinator(callable $write): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: HilosAgentType::HILOS_SESSIONS_LIBRARY), $write);
    }

    /**
     * @param LegalExportIntegrationAgent $agent Agent asked to carry the part
     * @param int $assignmentId Assignment it carries
     * @param string $words Words of the refusal
     * @throws HilosException When the step fails otherwise or a row cannot be read
     */
    private function assertErasureRefused(LegalExportIntegrationAgent $agent, int $assignmentId, string $words): void
    {
        try {
            self::erase($agent, $assignmentId);
            self::fail('The part was not refused');
        } catch (ValidationException $refusal) {
            self::assertNotInstanceOf(AccountOperationStepRefusedException::class, $refusal);
            self::assertSame($words, $refusal->getMessage());
        }

        $this->assertNothingErased();
    }

    /**
     * The second administrator's ready order with its file and the third's failed one are where the fixture left
     * them, and no result is stored.
     *
     * @throws HilosException When a row cannot be read
     */
    private function assertNothingErased(): void
    {
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame(LegalAcceptancesExportState::READY, self::exportOf(self::OTHER_ADMIN_ID)?->state);
        self::assertSame(LegalAcceptancesExportState::FAILED, self::exportOf(self::THIRD_ADMIN_ID)?->state);
        self::assertSame(2, self::exportRowCount());
        self::assertSame(['other.csv'], $this->files());
        self::assertSame([], self::storedResults());
    }

    /**
     * @return list<array{int, string, string}> Assignment, owner and payload of every stored result
     * @throws HilosException When the query fails
     */
    private static function storedResults(): array
    {
        return array_map(
            static fn (array $row): array => [(int) $row['assignment_id'], (string) $row['owner_agent_id'], (string) $row['payload_json']],
            Database::sql('SELECT `assignment_id`, `owner_agent_id`, `payload_json` FROM `hilos_account_operation_result` ORDER BY `id`')->rows(),
        );
    }

    /**
     * @param LegalExportIntegrationAgent $agent Agent serving the page
     * @param string $acceptKey Browser that presses the button
     * @param ?string $document Document filter
     * @param ?string $revisionId Revision filter
     * @param ?string $search Search
     * @throws HilosException When the order is refused or cannot be written
     */
    private static function order(
        LegalExportIntegrationAgent $agent,
        string $acceptKey,
        ?string $document = null,
        ?string $revisionId = null,
        ?string $search = null,
    ): void {
        LegalAcceptancesExports::order($agent, $acceptKey, new HilosLegalAcceptancesExportActionDTO($document, $revisionId, $search));
    }

    /**
     * @param LegalExportIntegrationAgent $agent Agent serving the page
     * @param string $acceptKey Browser that presses the button
     * @param string $message Refusal the press must meet
     * @throws HilosException When the order fails otherwise or the rows cannot be counted
     */
    private static function assertRefused(LegalExportIntegrationAgent $agent, string $acceptKey, string $message): void
    {
        try {
            self::order($agent, $acceptKey);
            self::fail('The order must be refused');
        } catch (ValidationException $e) {
            self::assertSame($message, $e->getMessage());
        }
        self::assertSame(0, self::exportRowCount());
    }

    /**
     * Ticks until the administrator's order is no longer preparing.
     *
     * @param LegalExportIntegrationAgent $agent Agent building the file
     * @param int $userId Administrator who ordered
     * @return int Ticks it took
     * @throws HilosException When a tick fails
     */
    private function finish(LegalExportIntegrationAgent $agent, int $userId): int
    {
        $ticks = 0;
        while (self::exportOf($userId)?->state === LegalAcceptancesExportState::PREPARING) {
            if ($ticks === self::TICK_CEILING) {
                self::fail('The export never finished');
            }
            $agent->onTick();
            $ticks++;
        }

        return $ticks;
    }

    /**
     * @param string $day Day the file is named by
     * @return string The Content-Disposition header of the download named by that day
     */
    private static function attachment(string $day): string
    {
        return "attachment; filename=\"legal-acceptances-{$day}.csv\"; filename*=UTF-8''legal-acceptances-{$day}.csv";
    }

    /**
     * @return DateTimeZone The zone stored moments are in
     */
    private static function utc(): DateTimeZone
    {
        return new DateTimeZone(TimeConstants::UTC_TIME_ZONE);
    }

    /**
     * Runs work under one server text policy and project zone, and puts the environment back after it.
     *
     * @param string $mode Server text policy for the work
     * @param string $project Project zone for the work
     * @param Closure(): void $work Work done under that policy
     */
    private function withZoneEnvironment(string $mode, string $project, Closure $work): void
    {
        $previousMode = getenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name);
        $previousProject = getenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
        try {
            putenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name . '=' . $mode);
            putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=' . $project);
            $work();
        } finally {
            putenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name . ($previousMode === false ? '' : '=' . $previousMode));
            putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . ($previousProject === false ? '' : '=' . $previousProject));
        }
    }

    /**
     * @param LegalExportIntegrationAgent $agent Agent answering the address
     * @param ?string $sessionToken Cookie session of the asking browser
     * @return HttpReplyDTO The answer
     * @throws HilosException When the request cannot be answered
     */
    private static function download(LegalExportIntegrationAgent $agent, ?string $sessionToken): HttpReplyDTO
    {
        $agent->httpReply = null;
        $agent->onSignalHttpRequest(
            new HttpRequestDTO('request', 'GET', LegalAcceptancesExportHttp::DOWNLOAD_PATH, [], $sessionToken, null),
            '',
            '',
        );
        self::assertNotNull($agent->httpReply);

        return $agent->httpReply;
    }

    /**
     * @param int $userId Administrator
     * @return ?LegalAcceptanceExport Their order, read again
     * @throws HilosException When the order cannot be read
     */
    private static function exportOf(int $userId): ?LegalAcceptanceExport
    {
        return Hilos::$db->legalAcceptanceExports->ofUser($userId);
    }

    /**
     * @return int Orders stored, straight from the table
     * @throws HilosException When the count fails
     */
    private static function exportRowCount(): int
    {
        return (int) Database::sql('SELECT COUNT(*) AS `total` FROM `hilos_legal_acceptance_export`')->firstRow()['total'];
    }

    /**
     * @return list<string> Names in the export directory, the dot-named marker aside
     */
    private function files(): array
    {
        $names = array_map('basename', glob($this->directory . '/*'));
        sort($names);

        return $names;
    }

    /**
     * @return string Path of the one file being built
     */
    private function buildingFile(): string
    {
        $paths = glob($this->directory . '/*' . self::BUILDING_SUFFIX);
        self::assertCount(1, $paths);

        return $paths[0];
    }

    /**
     * @param string $path File of acceptance records
     * @return list<string> Its record lines without their line ends, after the checked mark and header
     */
    private static function linesOf(string $path): array
    {
        $text = (string) file_get_contents($path);
        self::assertStringStartsWith(self::BOM . self::HEADER_LINE, $text);
        $body = substr($text, strlen(self::BOM . self::HEADER_LINE));
        if ($body === '') {
            return [];
        }
        self::assertStringEndsWith(self::LINE_END, $body);

        return explode(self::LINE_END, substr($body, 0, -strlen(self::LINE_END)));
    }

    /**
     * @param string $line Record line
     * @return string Its user id, name and email cells
     */
    private static function personCellsOf(string $line): string
    {
        foreach (self::PERSON_CELLS as $cells) {
            if (str_starts_with($line, $cells . ',')) {
                return $cells;
            }
        }
        self::fail("No person starts the line {$line}");
    }

    /**
     * The lines a file must hold: the seeded records still stored, newest first, the later id first on a shared moment.
     *
     * @param callable(array<string, mixed>): bool $keep Whether a stored record is under the order's filters
     * @param DateTimeZone $timeZone Zone the file is written in
     * @return list<string> Expected lines without their line ends
     * @throws HilosException When the records cannot be read
     */
    private static function expectedLines(callable $keep, DateTimeZone $timeZone): array
    {
        $rows = array_values(array_filter(
            Database::sql('SELECT `id`, `user_id`, `document`, `revision_id`, `accepted_at` FROM `hilos_legal_acceptance`')->rows(),
            $keep,
        ));
        usort($rows, static fn (array $a, array $b): int => [$b['accepted_at'], (int) $b['id']] <=> [$a['accepted_at'], (int) $a['id']]);

        return array_map(static fn (array $row): string => implode(',', [
            self::PERSON_CELLS[(int) $row['user_id']],
            $row['document'],
            $row['revision_id'],
            in_array($row['revision_id'], self::DECLARED[$row['document']], true) ? 'yes' : 'no',
            new DateTimeImmutable((string) $row['accepted_at'], self::utc())->setTimezone($timeZone)->format('Y-m-d\TH:i:sP'),
        ]), $rows);
    }

    /**
     * The first window the acceptances table shows under the same filters and search is the head of the file.
     *
     * @param list<string> $lines Lines of the file
     * @param ?string $document Document filter
     * @param ?string $revisionId Revision filter
     * @param ?string $search Search
     * @param DateTimeZone $timeZone Zone the file is written in
     * @throws HilosException When the window cannot be read
     */
    private static function assertWindowMatches(
        array $lines,
        ?string $document,
        ?string $revisionId,
        ?string $search,
        DateTimeZone $timeZone,
    ): void {
        $table = new LegalExportIntegrationTable();
        $filter = array_filter(
            [AbstractHilosLegalAcceptancesTable::FILTER_DOCUMENT => $document, AbstractHilosLegalAcceptancesTable::FILTER_REVISION => $revisionId],
            static fn (?string $value): bool => $value !== null,
        );
        $window = $table->getPage(new TableQueryDTO(search: $search, sort: $table->defaultSort(), limit: self::WINDOW, filter: $filter));
        self::assertSame(
            array_map(
                static fn (HilosLegalAcceptanceTableRow $row): string => rtrim(LegalAcceptancesCsv::line($row, $timeZone), self::LINE_END),
                $window->rows,
            ),
            array_slice($lines, 0, self::WINDOW),
        );
    }

    /**
     * @return list<RaiseSessionToastSignalData> Toasts queued since the last call; every other frame is dropped
     */
    private static function toasts(): array
    {
        $toasts = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_SESSION_TOAST_RAISE) {
                continue;
            }
            self::assertInstanceOf(AgentSignalData::class, $signal->data);
            self::assertInstanceOf(RaiseSessionToastSignalData::class, $signal->data->data);
            $toasts[] = $signal->data->data;
        }

        return $toasts;
    }

    /**
     * Stores the browser's live confirmation of the export.
     *
     * @param string $sessionToken Session of the confirming browser
     * @param int $userId Person who confirmed
     * @param ?int $confirmedUntil End of the confirmation in epoch milliseconds, or null for an hour from now
     * @throws HilosException When the row cannot be written
     */
    private static function confirm(string $sessionToken, int $userId, ?int $confirmedUntil = null): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_step_up` (`session_token_hash`, `user_id`, `operation`, `confirmed_until`, `created_at`) '
            . 'VALUES (?, ?, ?, ?, ?)',
            [
                ProtectedModeRuntime::hashSessionToken($sessionToken),
                $userId,
                StepUpOperationKey::EXPORT_LEGAL_ACCEPTANCES,
                $confirmedUntil ?? TimeHelper::nowMs() + 3600 * TimeConstants::MS_PER_SECOND,
                TimeHelper::nowMs(),
            ],
        );
    }

    /**
     * Seeds 1201 records of two documents: each person accepts an own revision per round, most of which no
     * catalog declares, and the moments repeat so that the order needs the id as well.
     *
     * @throws HilosException When the insert fails
     */
    private static function seedAcceptances(): void
    {
        $people = array_keys(self::PEOPLE);
        $values = [];
        $params = [];
        for ($index = 0; $index < self::RECORDS; $index++) {
            $round = intdiv($index, count($people));
            $values[] = '(?, ?, ?, DATE_ADD(?, INTERVAL ? MINUTE))';
            array_push(
                $params,
                $people[$index % count($people)],
                $round % 2 === 0 ? 'terms' : 'privacy',
                match ($round) {
                    0 => 'old',
                    1 => 'privacy',
                    2 => 'wording',
                    default => sprintf('r%03d', intdiv($round, 2)),
                },
                self::FIRST_ACCEPTANCE,
                ($index * self::MINUTE_STRIDE) % self::MINUTES,
            );
        }
        Database::sqlRun(
            'INSERT INTO `hilos_legal_acceptance` (`user_id`, `document`, `revision_id`, `accepted_at`) VALUES ' . implode(', ', $values),
            $params,
        );
    }

    /**
     * Seeds the administrators and the people, their addresses, and the sessions every case asks with.
     *
     * @throws HilosException When an insert fails
     */
    private static function seedPeopleAndSessions(): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_user` (`id`, `name`, `admin`) VALUES (?, ?, 1), (?, ?, 1), (?, ?, 1)',
            [self::ADMIN_ID, 'Root Admin', self::OTHER_ADMIN_ID, 'Second Admin', self::THIRD_ADMIN_ID, 'Third Admin'],
        );
        foreach (self::PEOPLE as $userId => $name) {
            Database::sqlRun('INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, ?)', [$userId, $name]);
        }
        foreach (self::EMAILS as $userId => $email) {
            self::seedIdentity($userId, 'magic_link', $email);
        }
        Database::sqlRun(
            "INSERT INTO `hilos_identity` (`created_at`, `updated_at`, `user_id`, `type`, `identifier`, `verified`) "
            . "VALUES (1735689600000, 1735689600000, ?, 'magic_link', ?, 0)",
            [106, 'taras@example.test'],
        );
        self::seedSession(self::ADMIN_TOKEN, self::ADMIN_ID, self::CREATED_AT, null);
        self::seedSession(self::OTHER_ADMIN_TOKEN, self::OTHER_ADMIN_ID, self::CREATED_AT, null);
        self::seedSession(self::PERSON_TOKEN, self::PERSON_ID, self::CREATED_AT, null);
        self::seedSession(self::IMPERSONATED_TOKEN, self::ADMIN_ID, self::CREATED_AT, null, self::OTHER_ADMIN_ID);
        self::seedSession(self::EXPIRED_TOKEN, self::ADMIN_ID, self::CREATED_AT, 1767225660000);
        self::seedSession(self::ANONYMOUS_TOKEN, null, self::CREATED_AT, null);
    }

    /**
     * @param string $token Session cookie token
     * @return int Id of its row
     * @throws HilosException When the lookup fails
     */
    private static function sessionIdOf(string $token): int
    {
        return (int) Database::sql('SELECT `id` FROM `hilos_session` WHERE `token` = ?', [$token])->firstRow()['id'];
    }

    /**
     * @param string $table Framework table
     * @return string Path of its create stub
     */
    private static function stubPath(string $table): string
    {
        return dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . '.sql';
    }

    /**
     * Drops the pin of the failing case and the extra tables, each before the table it points at.
     *
     * @throws HilosException When a drop fails
     */
    private static function dropExtraTables(): void
    {
        Database::sqlRun('DROP TABLE IF EXISTS `' . self::PIN_TABLE . '`');
        foreach (array_reverse(self::EXTRA_TABLES) as $table) {
            Database::sqlRun('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }
}

/** The legal agent of the fixture project, its download answers captured. */
final class LegalExportIntegrationAgent extends AbstractHilosLegalAgent
{
    public ?HttpReplyDTO $httpReply = null;

    /**
     * @param HttpReplyDTO $reply Answer to the download address
     */
    public function replyToHttpRequest(HttpReplyDTO $reply): void
    {
        $this->httpReply = $reply;
    }
}

/** The session holder, which opens the erasure and writes its assignments. */
final class LegalExportIntegrationCoordinator extends AbstractSessionsLibraryAgent
{
}

/** A private export directory per case. */
final class LegalExportIntegrationFs extends FsContext
{
    /**
     * @param string $path Private test directory
     */
    public function __construct(private readonly string $path)
    {
    }

    /** Registers the export directory, the cluster's as the framework requires. */
    public function configure(): void
    {
        $this->registerDirectory(self::LEGAL_EXPORT, $this->path, DirectoryScope::CLUSTER);
    }
}

/** The project's acceptances table, as the demos register it. */
final class LegalExportIntegrationTables extends TableContext
{
    /** Registers the acceptances table. */
    public function configure(): void
    {
        $this->register(AbstractHilosLegalAcceptancesTable::TABLE, new LegalExportIntegrationTable());
    }
}

/** A project that has lost its acceptances table, for the failure of a build under way. */
final class LegalExportIntegrationNoTables extends TableContext
{
    /** Registers nothing. */
    public function configure(): void
    {
    }
}

/** Names people from the framework's person table, the way the demos do. */
final class LegalExportIntegrationTable extends AbstractHilosLegalAcceptancesTable
{
    /**
     * @param list<int> $userIds People in one window or part
     * @return array<int, string> Their names by id
     * @throws HilosException When a person cannot be read
     */
    protected function displayNamesOf(array $userIds): array
    {
        $names = [];
        foreach ($userIds as $userId) {
            if (!isset(Hilos::$db->users[$userId])) {
                continue;
            }

            $name = Hilos::$db->users[$userId]->name;
            if ($name !== null) {
                $names[$userId] = $name;
            }
        }

        return $names;
    }

    /**
     * @param string $term Name substring
     * @return list<int> People whose name holds it, in any case
     * @throws HilosException When the people cannot be read
     */
    protected function userIdsNamed(string $term): array
    {
        $ids = [];
        foreach (Hilos::$db->users->listAll() as $user) {
            if ($user->id !== null && str_contains(mb_strtolower($user->name), mb_strtolower($term))) {
                $ids[] = $user->id;
            }
        }

        return $ids;
    }
}

/** Terms with two revisions and privacy with one; every other revision on record is undeclared. */
final class LegalExportIntegrationCatalog implements LegalCatalogProviderInterface
{
    /**
     * @return array<string, list<LegalRevision>> Fixture declarations
     */
    public static function revisions(): array
    {
        return [
            'terms' => [
                new LegalRevision(LegalDocument::TERMS, 'old', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
                new LegalRevision(LegalDocument::TERMS, 'wording', '2026-02-01', 1, LegalSignificance::EDITORIAL, '2026-02-01', []),
            ],
            'privacy' => [
                new LegalRevision(LegalDocument::PRIVACY, 'privacy', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
            ],
        ];
    }
}

/** Binds the fixture catalog. */
abstract class LegalExportIntegrationHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = LegalExportIntegrationCatalog::class;
}

/** The settings the step-up gate and its proof resolver read. */
final class LegalExportIntegrationSettingsCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Fixture settings catalog
     */
    public static function getCatalog(): array
    {
        return array_replace(StepUpSettingsCatalog::getCatalog(), SecondFactorSettingsCatalog::getCatalog());
    }
}

/** Runtime holding the signed-in browsers of a case. */
final class LegalExportIntegrationRtContext extends RtContext
{
    /**
     * @param list<LegalExportIntegrationConnection> $connections Browsers to mount
     */
    public function __construct(private readonly array $connections)
    {
        parent::__construct();
    }

    /** Mounts the browsers as the session connections. */
    public function configure(): void
    {
        $connections = LegalExportIntegrationConnections::init();
        foreach ($this->connections as $connection) {
            $connections->add($connection);
        }
        $this->_stateCollections[LegalExportIntegrationConnections::RT_COLLECTION] = $connections;
    }
}

/** Session-stage connections of the fixture. */
final class LegalExportIntegrationConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'legalExportIntegrationConnections';
    public const string STATE_CLASS = LegalExportIntegrationConnection::class;
}

/** Session-stage connection adding no project fields. */
final class LegalExportIntegrationConnection extends HilosSessionConnection
{
    /** Adds no project fields. */
    protected function initOwn(): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowReader $row Serialized runtime row
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function hydrateOwn(RtRowReader $row): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowWriter $out Segmented destination after both base stages
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function exportOwn(RtRowWriter $out): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowReader $diff Incoming field changes
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function applyOwnDiff(RtRowReader $diff): Generator
    {
        yield new RtWork();
    }
}

/** Synchronous mirror to detect unwanted mutations published during collection assembly. */
final class LegalAcceptancesExportTestMirror implements SourceMirrorSubscriberInterface
{
    /** @var list<SourceChange> */
    public array $changes = [];

    /**
     * @param SourceChange $change Announced change
     * @param SourceChangeProvenance $provenance Announced provenance
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->changes[] = $change;
    }
}
