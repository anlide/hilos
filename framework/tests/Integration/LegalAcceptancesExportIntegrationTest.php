<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\Session\DTO\RaiseSessionToastSignalData;
use Hilos\Auth\Session\SessionToastSeverity;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\StepUp\StepUpSettingsCatalog;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosLegalAgent;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Database\Database;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\View\Item\LegalAcceptanceExport;
use Hilos\Fs\ClusterDirectoryMarker;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\Export\DTO\LegalAcceptancesExportForgetSignalData;
use Hilos\Legal\Export\LegalAcceptancesCsv;
use Hilos\Legal\Export\LegalAcceptancesExportHttp;
use Hilos\Legal\Export\LegalAcceptancesExports;
use Hilos\Legal\Export\LegalAcceptancesExportState;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Pages\Legal\DTO\HilosLegalAcceptancesExportActionDTO;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use Hilos\Tables\Legal\AbstractHilosLegalAcceptancesTable;
use Hilos\Tables\Legal\HilosLegalAcceptanceTableRow;
use Hilos\Utils\Logger;

/**
 * The administrators' files of acceptance records against the real tables, directory and legal agent (HIL-1234).
 *
 * The agent is the real one, ticked by hand; the people, their sessions and their confirmations are rows of the
 * framework tables, and the browsers are session connections of a mounted runtime. What a file must say is read
 * from the seeded rows here, not from the code that writes it: the expected lines are spelled out per person.
 */
final class LegalAcceptancesExportIntegrationTest extends HilosSessionIntegrationTestCase
{
    /** Tables beside the session base's: the orders, and the passkeys a step-up resolver asks about. */
    private const array EXTRA_TABLES = ['hilos_passkey_credential', 'hilos_legal_acceptance_export'];

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
    private const string CREATED_AT = '2026-01-01 00:00:00';
    private const string PAST = '2026-01-01 00:00:00';

    /** The person every refusal and erasure case is about: no administrator. */
    private const int PERSON_ID = 101;

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
        foreach (self::EXTRA_TABLES as $table) {
            Database::sqlRun('DROP TABLE IF EXISTS `' . $table . '`');
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
        foreach (self::EXTRA_TABLES as $table) {
            Database::sqlRun('DROP TABLE IF EXISTS `' . $table . '`');
        }
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
        self::assertSame(self::expectedLines(static fn (): bool => true), $lines);
        self::assertSame(self::RECORDS, $export->records);
        self::assertSame(filesize($path), $export->sizeBytes);
        self::assertSame(self::SECONDS_PER_DAY, strtotime((string) $export->expiresAt) - strtotime((string) $export->finishedAt));
        self::assertWindowMatches($lines, null, null, null);
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
            self::assertSame(self::expectedLines($keep), $lines, $label);
            self::assertCount($count, $lines, $label);
            self::assertSame($count, $export->records, $label);
            self::assertWindowMatches($lines, $document, $revisionId, $search);
            $previousName = $export->storedName;
        }
    }

    /**
     * Nothing under the filters is still a file: the byte order mark and the header.
     *
     * @throws HilosException When a fixture, the order or a tick fails
     */
    public function testNothingMatchingLeavesTheHeaderAlone(): void
    {
        self::seedAcceptances();
        $agent = self::startedAgent();
        self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID);
        self::order($agent, self::ADMIN_KEY, null, null, 'nobody-by-this-name');

        self::assertSame(3, $this->finish($agent, self::ADMIN_ID));
        $export = self::exportOf(self::ADMIN_ID);
        self::assertSame(LegalAcceptancesExportState::READY, $export?->state);
        self::assertSame(0, $export->records);
        self::assertSame(self::BOM . self::HEADER_LINE, file_get_contents($this->directory . '/' . $export->storedName));
        self::assertSame(strlen(self::BOM . self::HEADER_LINE), $export->sizeBytes);
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
        self::confirm(self::ADMIN_TOKEN, self::ADMIN_ID, self::PAST);
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
            ->actions->finishReady('expired.csv', 3, 0, self::PAST, '2026-01-02 00:00:00');
        file_put_contents($this->directory . '/expired.csv', self::BOM);
        self::assertSame(404, self::download($agent, self::OTHER_ADMIN_TOKEN)->status, 'An expired file is not served before the sweep');

        self::order($agent, self::ADMIN_KEY);
        self::assertSame(404, self::download($agent, self::ADMIN_TOKEN)->status, 'An order being prepared has no file');
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
        $exports->order(self::ADMIN_ID, null, null, null, self::PAST)->actions->finishReady('kept.csv', 4, 0, self::PAST, $future);
        $exports->order(self::OTHER_ADMIN_ID, null, null, null, self::PAST)->actions->finishReady('expired.csv', 4, 0, self::PAST, self::PAST);
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
        $exports->order(self::ADMIN_ID, null, null, null, self::PAST)->actions->finishReady('missing.csv', 4, 0, self::PAST, $future);
        $exports->order(self::OTHER_ADMIN_ID, null, null, null, self::PAST)->actions->finishReady('present.csv', 4, 0, self::PAST, $future);
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
        $exports->order(self::OTHER_ADMIN_ID, null, null, null, self::PAST)->actions->finishReady('other.csv', 4, 1, self::PAST, $future);
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
        self::assertSame(self::expectedLines(static fn (): bool => true), $lines);
        self::assertCount(self::RECORDS - 201, $lines);
        self::assertNotContains(self::PERSON_CELLS[self::PERSON_ID], array_map(self::personCellsOf(...), $lines));
        self::assertSame(self::RECORDS - 201, $export->records);
    }

    /**
     * An erased administrator's own order goes with its file, even while it is being built.
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

        LegalAcceptancesExports::forget($agent, self::ADMIN_ID);

        self::assertNull(self::exportOf(self::ADMIN_ID));
        self::assertSame([], $this->files());
        $agent->onTick();
        $agent->onTick();
        self::assertSame([], $this->files());
        self::assertSame(0, self::exportRowCount());
        self::assertSame([], self::toasts());
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
        self::assertSame(self::expectedLines(static fn (): bool => true), self::linesOf($this->directory . '/' . $export->storedName));
        self::assertSame([], self::toasts());
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
     * @return LegalExportIntegrationAgent A legal agent past its start
     * @throws HilosException When the start fails
     */
    private static function startedAgent(): LegalExportIntegrationAgent
    {
        $agent = new LegalExportIntegrationAgent();
        $agent->onStart();

        return $agent;
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
     * @return list<string> Expected lines without their line ends
     * @throws HilosException When the records cannot be read
     */
    private static function expectedLines(callable $keep): array
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
            str_replace(' ', 'T', (string) $row['accepted_at']) . 'Z',
        ]), $rows);
    }

    /**
     * The first window the acceptances table shows under the same filters and search is the head of the file.
     *
     * @param list<string> $lines Lines of the file
     * @param ?string $document Document filter
     * @param ?string $revisionId Revision filter
     * @param ?string $search Search
     * @throws HilosException When the window cannot be read
     */
    private static function assertWindowMatches(array $lines, ?string $document, ?string $revisionId, ?string $search): void
    {
        $table = new LegalExportIntegrationTable();
        $filter = array_filter(
            [AbstractHilosLegalAcceptancesTable::FILTER_DOCUMENT => $document, AbstractHilosLegalAcceptancesTable::FILTER_REVISION => $revisionId],
            static fn (?string $value): bool => $value !== null,
        );
        $window = $table->getPage(new TableQueryDTO(search: $search, sort: $table->defaultSort(), limit: self::WINDOW, filter: $filter));
        self::assertSame(
            array_map(static fn (HilosLegalAcceptanceTableRow $row): string => rtrim(LegalAcceptancesCsv::line($row), self::LINE_END), $window->rows),
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
     * @param ?string $confirmedUntil End of the confirmation, or null for an hour from now
     * @throws HilosException When the row cannot be written
     */
    private static function confirm(string $sessionToken, int $userId, ?string $confirmedUntil = null): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_step_up` (`session_token_hash`, `user_id`, `operation`, `confirmed_until`) VALUES (?, ?, ?, ?)',
            [
                ProtectedModeRuntime::hashSessionToken($sessionToken),
                $userId,
                StepUpOperationKey::EXPORT_LEGAL_ACCEPTANCES,
                $confirmedUntil ?? date('Y-m-d H:i:s', time() + 3600),
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
            "INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`, `verified`) VALUES (?, 'magic_link', ?, 0)",
            [106, 'taras@example.test'],
        );
        self::seedSession(self::ADMIN_TOKEN, self::ADMIN_ID, self::CREATED_AT, null);
        self::seedSession(self::OTHER_ADMIN_TOKEN, self::OTHER_ADMIN_ID, self::CREATED_AT, null);
        self::seedSession(self::PERSON_TOKEN, self::PERSON_ID, self::CREATED_AT, null);
        self::seedSession(self::IMPERSONATED_TOKEN, self::ADMIN_ID, self::CREATED_AT, null, self::OTHER_ADMIN_ID);
        self::seedSession(self::EXPIRED_TOKEN, self::ADMIN_ID, self::CREATED_AT, '2026-01-01 00:01:00');
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
            $name = Hilos::$db->users[$userId]?->name;
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
    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> No project fields
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Incoming field changes
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}
