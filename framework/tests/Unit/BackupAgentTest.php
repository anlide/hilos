<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use DateTimeImmutable;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\DTO\BackupDeleteSignalData;
use Hilos\Backup\Agent\DTO\BackupRestoreSignalData;
use Hilos\Backup\Agent\DTO\BackupSetKeepSignalData;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupScope;
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Context\DbContext;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\Runtime\State\Collection\BackupHistories as StateBackupHistories;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\State\Item\RestoreRuntime as StateRestoreRuntime;
use Hilos\Runtime\View\Actions\Collection\BackupHistoriesActions;
use Hilos\Runtime\View\Actions\Item\BackupHistoryActions;
use Hilos\Runtime\View\Collection\BackupHistories;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RestoreRuntime;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the pure, side-effect-free logic of the backup supervisor.
 *
 * The spawn/poll/timeout path drives a live child and is exercised at e2e; here we pin the
 * id-from-timestamp format, the child argv contract (command name + scope option) the
 * supervisor and the project child command must agree on, and the create-path precondition
 * that keeps a misconfigured install from launching a run it could never report on.
 *
 * The restore-admission cases (HIL-276) go through the public agent-signal entrance rather
 * than at the admission itself, because what they pin is what leaves the agent: the freeze
 * request naming the tab that asked, and the addressed refusal when the run is turned away
 * after the page already acked it.
 *
 * The out-of-reach cases (HIL-940) mount the backup index: an archive another node's disk holds
 * is refused by name at all three operator requests, and the readers of this node's own archives
 * never count it.
 */
final class BackupAgentTest extends TestCase
{
    private const string INITIATOR = 'accept-key-of-the-tab';

    protected function setUp(): void
    {
        parent::setUp();

        Hilos::$sr = new SignalRouter();
        Hilos::$env = $this->env();
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateRestoreRuntime::RT_ITEM);
        Hilos::$rt = null;
        Hilos::$sr = null;
        Hilos::$env = null;
        Hilos::$db = null;

        parent::tearDown();
    }

    public function testGenerateBackupIdIsTheSortableTimestampStem(): void
    {
        $id = BackupAgent::generateBackupId(new DateTimeImmutable('2026-07-19T10:30:00'));

        $this->assertSame('2026-07-19_10-30-00', $id);
    }

    public function testGenerateBackupIdZeroPadsSingleDigitParts(): void
    {
        $id = BackupAgent::generateBackupId(new DateTimeImmutable('2026-01-02T03:04:05'));

        $this->assertSame('2026-01-02_03-04-05', $id);
    }

    public function testChildArgsCarryTheCommandNameIdAndScopeOption(): void
    {
        $args = BackupAgent::buildChildArgs('/app/cli.php', '2026-07-19_10-30-00', BackupScope::FULL);

        $this->assertSame(
            ['/app/cli.php', BackupConstants::RUN_COMMAND, '2026-07-19_10-30-00', '--scope=full'],
            $args,
        );
    }

    public function testChildArgsUseTheScopeStorageValue(): void
    {
        $args = BackupAgent::buildChildArgs('/app/cli.php', 'id', BackupScope::SCHEMA_ONLY);

        $this->assertSame('--scope=schema-only', $args[3]);
    }

    public function testRestoreChildArgsCarryNoMigrationIndexWhenNobodyNamedOne(): void
    {
        // The option's absence is the message: the child reads "no level named" from a missing
        // option, so an argv carrying an empty one would say something else entirely.
        $args = BackupAgent::buildRestoreChildArgs(
            '/app/cli.php',
            'id',
            BackupScope::SCHEMA_ONLY,
            RestoreEnvDecision::ALLOW,
            null,
        );

        $this->assertSame(
            [
                '/app/cli.php',
                BackupConstants::RESTORE_RUN_COMMAND,
                'id',
                '--scope=schema-only',
                '--decision=' . RestoreEnvDecision::ALLOW->value,
            ],
            $args,
        );
    }

    public function testRestoreChildArgsCarryTheMigrationIndexTheOperatorNamed(): void
    {
        $args = BackupAgent::buildRestoreChildArgs(
            '/app/cli.php',
            'id',
            BackupScope::SCHEMA_ONLY,
            RestoreEnvDecision::ALLOW,
            3,
        );

        $this->assertSame('--' . BackupConstants::MIGRATION_INDEX_OPTION . '=3', $args[5]);
    }

    public function testALevelOfZeroReachesTheChildRatherThanBeingDroppedAsEmpty(): void
    {
        // "Never migrated" is a level a schema archive may legitimately be restored at, so it
        // must not share the wire shape of "the operator named nothing".
        $args = BackupAgent::buildRestoreChildArgs(
            '/app/cli.php',
            'id',
            BackupScope::SCHEMA_ONLY,
            RestoreEnvDecision::ALLOW,
            0,
        );

        $this->assertSame('--' . BackupConstants::MIGRATION_INDEX_OPTION . '=0', $args[5]);
    }

    public function testNothingIsMissingWhenBothCreateSettingsAreConfigured(): void
    {
        $missing = BackupAgent::missingCreateConfig('/app/data/backup', '/app/cli.php');

        $this->assertSame([], $missing);
    }

    public function testAnEmptyStorageRootIsReportedMissing(): void
    {
        $missing = BackupAgent::missingCreateConfig('', '/app/cli.php');

        $this->assertSame([EnvConstants::BACKUP_DIR->name], $missing);
    }

    public function testAnEmptyCliEntryIsReportedMissing(): void
    {
        $missing = BackupAgent::missingCreateConfig('/app/data/backup', '');

        $this->assertSame([EnvConstants::BACKUP_CLI_ENTRY->name], $missing);
    }

    public function testBothSettingsAreReportedWhenNeitherIsConfigured(): void
    {
        $missing = BackupAgent::missingCreateConfig('', '');

        $this->assertSame(
            [EnvConstants::BACKUP_DIR->name, EnvConstants::BACKUP_CLI_ENTRY->name],
            $missing,
        );
    }

    public function testFailureNoticeCarriesTheIdAndTheReason(): void
    {
        $notice = BackupAgent::failureNotice('2026-07-19_10-30-00', 'child exited with code 1');

        $this->assertSame('Backup 2026-07-19_10-30-00 failed: child exited with code 1', $notice);
    }

    public function testFailureNoticeKeepsOnlyTheFirstStderrLine(): void
    {
        $notice = BackupAgent::failureNotice('id', "dump failed\nmysqldump: not found\nstack trace");

        $this->assertSame('Backup id failed: dump failed', $notice);
    }

    public function testFailureNoticeCapsALongDetail(): void
    {
        $notice = BackupAgent::failureNotice('id', str_repeat('x', 500));

        $this->assertSame(200, mb_strlen(substr($notice, strlen('Backup id failed: '))));
        $this->assertStringEndsWith('…', $notice);
    }

    public function testFailureNoticeStandsAloneWithoutADetail(): void
    {
        $notice = BackupAgent::failureNotice('id', '');

        $this->assertSame('Backup id failed', $notice);
    }

    public function testTheIntactExitCodeIsTheOnlyOneThatMeansTheDatabaseWasNotTouched(): void
    {
        $this->assertFalse(BackupAgent::restoreTouchedDatabase(BackupConstants::RESTORE_EXIT_DATABASE_INTACT));
        $this->assertTrue(BackupAgent::restoreTouchedDatabase(ExitCode::SUCCESS));
        $this->assertTrue(BackupAgent::restoreTouchedDatabase(ExitCode::ERROR));
    }

    public function testAChildThatLeftNoExitCodeIsAssumedToHaveBeenWriting(): void
    {
        $this->assertTrue(
            BackupAgent::restoreTouchedDatabase(null),
            'A killed restore is assumed to have touched the database: the optimistic guess costs'
            . ' a production database nobody checked',
        );
    }

    public function testARestoreFromThePageFreezesTheNodeInTheNameOfTheTabThatAskedForIt(): void
    {
        $this->admitFromPage();

        $enable = $this->nextSignalOfType(SignalTypeConstants::PROTECTED_MODE_ENABLE);
        $this->assertInstanceOf(ProtectedModeEnableSignalData::class, $enable);
        $this->assertSame(
            self::INITIATOR,
            $enable->initiatorAcceptKey,
            'The freeze must name the initiator: protected mode keeps that one connection alive so'
            . ' the operation has somewhere to report',
        );
    }

    public function testARestoreWithNoInitiatorFreezesTheNodeInNobodyName(): void
    {
        $this->admitFromPage(initiator: null);

        $enable = $this->nextSignalOfType(SignalTypeConstants::PROTECTED_MODE_ENABLE);
        $this->assertInstanceOf(ProtectedModeEnableSignalData::class, $enable);
        $this->assertSame(
            '',
            $enable->initiatorAcceptKey,
            'A CLI restore has no browser connection to keep alive through the freeze, and says so'
            . ' the way the protected-mode request has always said it',
        );
    }

    public function testASecondRestoreIsRefusedToTheTabThatAskedForIt(): void
    {
        $agent = $this->admitFromPage();

        $agent->onSignalAgent(
            new AgentSignalData($this->restoreRequest('2026-08-15_11-00-00', self::INITIATOR)),
            'test',
            HilosSignalConstants::BACKUP_AGENT_RESTORE,
        );

        $error = $this->lastActionError();
        $this->assertNotNull($error, 'The tab that asked is told the subsystem is busy');
        $this->assertSame(HilosSignalConstants::BACKUP_RESTORE, $error->action);
        $this->assertStringStartsWith('Backup subsystem busy', $error->reason);
    }

    public function testARefusingEnvVerdictIsTurnedAwayAtTheAgentToo(): void
    {
        $agent = new BackupAgent();

        $agent->onSignalAgent(
            new AgentSignalData($this->restoreRequest(
                '2026-08-15_10-30-00',
                self::INITIATOR,
                RestoreEnvDecision::REFUSE,
            )),
            'test',
            HilosSignalConstants::BACKUP_AGENT_RESTORE,
        );

        $error = $this->lastActionError();
        $this->assertNotNull($error, 'The backstop refusal reaches the initiator as this action failing');
        $this->assertSame('Restore refused by the environment guard', $error->reason);
    }

    public function testARefusedRestoreNobodyAskedForTellsNobody(): void
    {
        $agent = new BackupAgent();

        $agent->onSignalAgent(
            new AgentSignalData($this->restoreRequest(
                '2026-08-15_10-30-00',
                null,
                RestoreEnvDecision::REFUSE,
            )),
            'test',
            HilosSignalConstants::BACKUP_AGENT_RESTORE,
        );

        $this->assertNull($this->lastActionError(), 'An unattended restore has no connection to answer');
    }

    public function testTheInitiatorIdentitiesArePhotographedWhileTheOldDatabaseIsStillTheLiveOne(): void
    {
        $db = new BackupAgentIdentityTestDbContext();
        Hilos::$db = $db;

        $this->admitFromPage(initiatorUserId: 41);

        self::assertSame(
            [41],
            $db->askedFor,
            'The identities are read at admission, before the freeze and long before the archive'
            . ' replaces the database that knows the answer',
        );
    }

    public function testAnUnattendedRestoreAsksTheDatabaseAboutNobody(): void
    {
        $db = new BackupAgentIdentityTestDbContext();
        Hilos::$db = $db;

        $this->admitFromPage(initiator: null);

        self::assertSame([], $db->askedFor, 'A CLI restore names no person to announce the outcome to');
    }

    public function testARestoreNamingAnUnknownScopeIsDroppedRatherThanAdmitted(): void
    {
        $agent = new BackupAgent();

        $agent->onSignalAgent(
            new AgentSignalData(
                new BackupRestoreSignalData('2026-08-15_10-30-00', 'not-a-scope', 'allow', self::INITIATOR),
            ),
            'test',
            HilosSignalConstants::BACKUP_AGENT_RESTORE,
        );

        $this->assertNull(
            $this->nextSignalOfType(SignalTypeConstants::PROTECTED_MODE_ENABLE),
            'A payload the page cannot have produced freezes nothing',
        );
    }

    public function testARestoreOfAnArchiveOnAnotherNodeIsRefusedBeforeAnythingIsEngaged(): void
    {
        $this->mountIndex($this->indexRow('there', [StateBackupHistory::nodeId => 'm1', StateBackupHistory::reachable => false]));

        new BackupAgent()->onSignalAgent(
            new AgentSignalData($this->restoreRequest('there', self::INITIATOR)),
            'test',
            HilosSignalConstants::BACKUP_AGENT_RESTORE,
        );

        $error = $this->lastActionError();
        $this->assertNotNull($error, 'The button is dead on the page, and the agent says why all the same');
        $this->assertSame('Backup there is stored on node m1 and cannot be reached from here', $error->reason);
        $this->assertFalse($this->restoreRow()->running, 'A refusal leaves no pending restore behind');
    }

    public function testADeleteOfAnArchiveOnAnotherNodeIsIgnoredByName(): void
    {
        $this->mountIndex($this->indexRow('there', [StateBackupHistory::nodeId => 'm1', StateBackupHistory::reachable => false]));

        ob_start();
        new BackupAgent()->onSignalAgent(
            new AgentSignalData(new BackupDeleteSignalData('there')),
            'test',
            HilosSignalConstants::BACKUP_AGENT_DELETE,
        );
        $log = (string)ob_get_clean();

        $this->assertStringContainsString('Ignoring delete of backup there stored on node m1', $log);
        $this->assertNotNull($this->histories()['there'], 'The archive is still on the disk of m1');
    }

    public function testAKeepToggleOfAnArchiveOnAnotherNodeIsIgnoredByName(): void
    {
        $this->mountIndex($this->indexRow('there', [StateBackupHistory::nodeId => 'm1', StateBackupHistory::reachable => false]));

        ob_start();
        new BackupAgent()->onSignalAgent(
            new AgentSignalData(new BackupSetKeepSignalData('there', true)),
            'test',
            HilosSignalConstants::BACKUP_AGENT_SET_KEEP,
        );
        $log = (string)ob_get_clean();

        $this->assertStringContainsString('Ignoring keep toggle of backup there stored on node m1', $log);
        $this->assertFalse($this->histories()['there']?->keep);
    }

    public function testTheReadersOfThisNodesArchivesNeverCountAnArchiveOutOfReach(): void
    {
        // The restore estimate is one of the readers of the index rows: a speed times a size, the
        // speed being the median of the recent restores. Counted, the far archive would lift it
        // from 0.1 s/byte to the median of 0.1 and 0.9 - that is, from 100 s to 500 s.
        $this->mountIndex(
            $this->indexRow('target', [StateBackupHistory::nodeId => 'm2', StateBackupHistory::sizeBytes => 1000]),
            $this->indexRow('restored-here', [
                StateBackupHistory::nodeId => 'm2',
                StateBackupHistory::sizeBytes => 1000,
                StateBackupHistory::restoredAt => '2026-09-01T00:00:00+00:00',
                StateBackupHistory::restoreDurationSeconds => 100,
            ]),
            $this->indexRow('restored-there', [
                StateBackupHistory::nodeId => 'm1',
                StateBackupHistory::reachable => false,
                StateBackupHistory::sizeBytes => 1000,
                StateBackupHistory::restoredAt => '2026-09-02T00:00:00+00:00',
                StateBackupHistory::restoreDurationSeconds => 900,
            ]),
        );

        new BackupAgent()->onSignalAgent(
            new AgentSignalData($this->restoreRequest('target', null)),
            'test',
            HilosSignalConstants::BACKUP_AGENT_RESTORE,
        );

        $this->assertTrue($this->restoreRow()->running);
        $this->assertSame(100, $this->restoreRow()->estimatedSeconds);
    }

    /**
     * Mounts the backup index holding the given rows, and the restore runtime row beside it.
     *
     * @param StateBackupHistory ...$rows Index rows
     */
    private function mountIndex(StateBackupHistory ...$rows): void
    {
        $states = StateBackupHistories::init();
        foreach ($rows as $row) {
            $states->add($row);
        }

        Hilos::$rt = new BackupAgentTestRtContext();
        Hilos::$rt->mountFeatureCollection(StateBackupHistory::RT_COLLECTION, $states);
        Hilos::$rt->setRepresent(
            StateBackupHistory::RT_COLLECTION,
            BackupHistories::class,
            BackupHistoriesActions::class,
            BackupHistoryActions::class,
        );
        Hilos::$rt->mountFeatureItem(StateRestoreRuntime::RT_ITEM, StateRestoreRuntime::create());
        RtTruthSourceRegistry::registerDaemon(StateRestoreRuntime::RT_ITEM);
    }

    /**
     * @param string $id Backup id
     * @param array<string, mixed> $overrides Fields replacing the successful full-scope default
     * @return StateBackupHistory Index row
     */
    private function indexRow(string $id, array $overrides = []): StateBackupHistory
    {
        return StateBackupHistory::fromRow($overrides + [
            StateBackupHistory::id => $id,
            StateBackupHistory::createdAt => '2026-08-15T10:30:00+00:00',
            StateBackupHistory::env => 'dev',
            StateBackupHistory::scope => BackupScope::FULL->value,
            StateBackupHistory::status => 'success',
            StateBackupHistory::connections => [],
            StateBackupHistory::sizeBytes => 0,
            StateBackupHistory::durationSeconds => 0,
            StateBackupHistory::keep => false,
            StateBackupHistory::dumpBytes => 0,
            StateBackupHistory::restoreDurationSeconds => 0,
            StateBackupHistory::reachable => true,
        ]);
    }

    /**
     * @return BackupHistories The mounted backup index view
     */
    private function histories(): BackupHistories
    {
        $view = Hilos::$rt?->hilosBackupHistories;

        return $view instanceof BackupHistories
            ? $view
            : throw new RuntimeException('The backup index is not mounted.');
    }

    /**
     * @return RestoreRuntime The mounted restore runtime row
     */
    private function restoreRow(): RestoreRuntime
    {
        $view = Hilos::$rt?->hilosRestoreRuntime;

        return $view instanceof RestoreRuntime
            ? $view
            : throw new RuntimeException('The restore runtime singleton is not mounted.');
    }

    /**
     * Drives one page restore through the agent's public signal entrance.
     *
     * @param ?string $initiator Accept key of the connection that asked, or null for an unattended run
     * @param ?int $initiatorUserId User id behind that connection, or null when unattended
     * @return BackupAgent The agent that admitted it, for a follow-up request
     */
    private function admitFromPage(
        ?string $initiator = self::INITIATOR,
        ?int $initiatorUserId = null,
    ): BackupAgent {
        $agent = new BackupAgent();
        $agent->onSignalAgent(
            new AgentSignalData($this->restoreRequest('2026-08-15_10-30-00', $initiator, initiatorUserId: $initiatorUserId)),
            'test',
            HilosSignalConstants::BACKUP_AGENT_RESTORE,
        );

        return $agent;
    }

    /**
     * @param string $id Backup id to restore
     * @param ?string $initiator Accept key of the connection that asked, or null when unattended
     * @param RestoreEnvDecision $decision Recorded ENV guard verdict
     * @param ?int $initiatorUserId User id behind the connection, or null when unattended
     * @return BackupRestoreSignalData Page → agent restore request
     */
    private function restoreRequest(
        string $id,
        ?string $initiator,
        RestoreEnvDecision $decision = RestoreEnvDecision::ALLOW,
        ?int $initiatorUserId = null,
    ): BackupRestoreSignalData {
        return new BackupRestoreSignalData(
            $id,
            BackupScope::FULL->value,
            $decision->value,
            $initiator,
            $initiatorUserId,
        );
    }

    /**
     * Drains the queue up to the first signal of a type, and answers with its payload.
     *
     * @param string $type Signal type to look for
     * @return ?object Payload of that signal, or null when the queue held none
     */
    private function nextSignalOfType(string $type): ?object
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalType->getType() === $type) {
                return $signal->data;
            }
        }

        return null;
    }

    /**
     * Drains the queue and answers with the last addressed action error in it.
     *
     * @return ?PageActionErrorSignalData The action error a client would receive, or null when none was sent
     */
    private function lastActionError(): ?PageActionErrorSignalData
    {
        $error = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalConstants::ACTION_ERROR) {
                continue;
            }
            $data = $signal->data;
            if ($data instanceof WebSocketSignalData && $data->data instanceof PageActionErrorSignalData) {
                $error = $data->data;
            }
        }

        return $error;
    }

    /**
     * Builds an environment the restore admission can read every value it needs from.
     *
     * The fixtures answer at the catalog and the value seam rather than at the typed readers,
     * because an index read reaches those two and never the readers themselves.
     *
     * @return EnvAccessor Accessor answering the backup keys with fixtures
     */
    private function env(): EnvAccessor
    {
        return new class extends EnvAccessor {
            /**
             * @return array<string, array<string, mixed>> Framework catalog plus the entry point a project declares
             */
            protected function getCatalog(): array
            {
                return [
                    ...parent::getCatalog(),
                    EnvConstants::BACKUP_CLI_ENTRY->name => [
                        EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING,
                    ],
                ];
            }

            /**
             * @param string $key Environment variable name
             * @return mixed Fixture for a string or integer key, the catalog answer for every other type
             * @throws EnvException When the key is invalid, uncataloged, or has no answer
             */
            public function effectiveValueFor(string $key): mixed
            {
                return match ($this->typeFor($key)) {
                    EnvCatalogConstants::TYPE_STRING => $key === EnvConstants::BACKUP_DIR->name
                        ? '/app/data/backup'
                        : '/app/cli.php',
                    EnvCatalogConstants::TYPE_INTEGER => 600,
                    default => parent::effectiveValueFor($key),
                };
            }
        };
    }
}

/**
 * Runtime context that registers no project state: a case mounts the backup index itself.
 */
final class BackupAgentTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

/**
 * DB context recording whose identities the admission asked for.
 */
final class BackupAgentIdentityTestDbContext extends DbContext
{
    /** @var list<int> User ids the agent asked the identities of, in order */
    public array $askedFor = [];

    /**
     * No-op DB configuration for the fixture.
     */
    public function configure(): void
    {
    }

    /**
     * Answers any collection name with the identities stand-in: it is the only one asked for.
     *
     * @param string $name Collection name
     * @return object Identities collection stand-in
     */
    public function __get(string $name)
    {
        $context = $this;

        return new class ($context) {
            /**
             * @param BackupAgentIdentityTestDbContext $context Context recording the calls
             */
            public function __construct(private readonly BackupAgentIdentityTestDbContext $context)
            {
            }

            /**
             * @param int $userId Owning user id
             * @return list<object> Identity stand-ins of that user
             */
            public function listByUser(int $userId): array
            {
                $this->context->askedFor[] = $userId;

                return [
                    new class ('email', 'boss@example.test') {
                        /**
                         * @param string $type Identity type
                         * @param string $identifier Normalized identifier
                         */
                        public function __construct(
                            public readonly string $type,
                            public readonly string $identifier,
                        ) {
                        }
                    },
                ];
            }
        };
    }
}
