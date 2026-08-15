<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use DateTimeImmutable;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\DTO\BackupRestoreSignalData;
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
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use PHPUnit\Framework\TestCase;

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
        Hilos::$sr = null;
        Hilos::$env = null;

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

    /**
     * Drives one page restore through the agent's public signal entrance.
     *
     * @param ?string $initiator Accept key of the connection that asked, or null for an unattended run
     * @return BackupAgent The agent that admitted it, for a follow-up request
     */
    private function admitFromPage(?string $initiator = self::INITIATOR): BackupAgent
    {
        $agent = new BackupAgent();
        $agent->onSignalAgent(
            new AgentSignalData($this->restoreRequest('2026-08-15_10-30-00', $initiator)),
            'test',
            HilosSignalConstants::BACKUP_AGENT_RESTORE,
        );

        return $agent;
    }

    /**
     * @param string $id Backup id to restore
     * @param ?string $initiator Accept key of the connection that asked, or null when unattended
     * @param RestoreEnvDecision $decision Recorded ENV guard verdict
     * @return BackupRestoreSignalData Page → agent restore request
     */
    private function restoreRequest(
        string $id,
        ?string $initiator,
        RestoreEnvDecision $decision = RestoreEnvDecision::ALLOW,
    ): BackupRestoreSignalData {
        return new BackupRestoreSignalData($id, BackupScope::FULL->value, $decision->value, $initiator);
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
     * @return EnvAccessor Accessor answering the backup keys with fixtures
     */
    private function env(): EnvAccessor
    {
        return new class extends EnvAccessor {
            public function string(EnvConstants|string $name): string
            {
                return $name === EnvConstants::BACKUP_DIR ? '/app/data/backup' : '/app/cli.php';
            }

            public function int(EnvConstants|string $name): int
            {
                return 600;
            }
        };
    }
}
