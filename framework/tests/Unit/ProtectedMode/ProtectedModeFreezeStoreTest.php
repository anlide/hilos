<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\DTO\ProtectedModeWatchdogSnapshot;
use Hilos\ProtectedMode\Exception\ProtectedModeFreezeUnreadableException;
use Hilos\ProtectedMode\ProtectedModeFreezeStore;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The freeze a node leaves on disk so a restart does not quietly reopen it (HIL-482).
 *
 * The row lives in runtime state, which is memory only, so everything here defends one sentence: a
 * daemon that goes down under a freeze must come back under it. That splits into the two halves the
 * cases below are grouped by - what the store writes and reads, and what the daemon executor leaves
 * behind as it moves the phase.
 *
 * The refusal cases all approach the same rule from different sides: a file that is THERE and cannot
 * be understood refuses the startup, while a file that is ABSENT is the ordinary node that was never
 * frozen. A file damaged by permissions rather than by content is not among them on purpose - the
 * suite runs as root in the test container, where a mode of 0000 still reads - so the truncated file
 * stands for that branch, and it is the one a crash mid-write actually produces.
 */
final class ProtectedModeFreezeStoreTest extends TestCase
{
    /** @var string Operation the freeze in these cases protects */
    private const string OPERATION = 'backup:restore';

    /** @var string Agent type recorded as the initiator */
    private const string INITIATOR_TYPE = 'hilos_backup';

    /** @var string Session token hash of the browser the operator started the freeze from */
    private const string INITIATOR_SESSION_HASH = 'session-hash-of-the-operator';

    /** @var int Epoch second the freeze in these cases began at */
    private const int STARTED_AT = 1_700_000_000;

    /** Daemon log directory the store keeps its file beside */
    private string $logDirectory = '';

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDirectory = (string)tempnam(sys_get_temp_dir(), 'hilos-freeze-store');
        unlink($this->logDirectory);
        mkdir($this->logDirectory);

        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();
        putenv(EnvConstants::DAEMON_LOG_FILE->name . '=' . $this->logDirectory . '/daemon.log');
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateProtectedModeRuntime::RT_ITEM);
        Hilos::$rt = null;
        Hilos::$cluster = null;

        foreach ((array)glob($this->logDirectory . '/*') as $leftover) {
            unlink((string)$leftover);
        }
        rmdir($this->logDirectory);

        putenv(EnvConstants::DAEMON_LOG_FILE->name);
        Hilos::$env = $this->previousEnv;

        parent::tearDown();
    }

    public function testTheFreezeIsReadBackTheWayItWasLeft(): void
    {
        $row = $this->row(StateProtectedModeRuntime::PHASE_ACTIVE);
        $row[StateProtectedModeRuntime::enteredFromBrowser] = true;
        new ProtectedModeFreezeStore()->save($row);

        $restored = new ProtectedModeFreezeStore()->load();

        $this->assertNotNull($restored);
        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, $restored->phase);
        $this->assertSame(self::OPERATION, $restored->operation);
        $this->assertSame(self::INITIATOR_TYPE, $restored->initiatorAgentType);
        $this->assertSame(2, $restored->initiatorAgentIndex);
        $this->assertSame('node-a', $restored->initiatorNodeId);
        $this->assertSame(self::STARTED_AT, $restored->startedAt);
        $this->assertSame(self::STARTED_AT + 1, $restored->activatedAt);
        $this->assertSame(StateProtectedModeRuntime::ENTRY_MODE_FREEZE, $restored->entryMode);
        $this->assertTrue($restored->enteredFromBrowser);
    }

    public function testVersionOneFileRefusesStartup(): void
    {
        $row = $this->row(StateProtectedModeRuntime::PHASE_VERIFYING);
        unset($row[StateProtectedModeRuntime::enteredFromBrowser]);
        file_put_contents($this->freezeFile(), (string)json_encode(['version' => 1, 'row' => $row]));

        $this->expectException(ProtectedModeFreezeUnreadableException::class);
        $this->expectExceptionMessage('version 2 freeze file');
        new ProtectedModeFreezeStore()->load();
    }

    public function testDirectWindowPersistsInTheVersionTwoFile(): void
    {
        $executor = $this->executorOnAMountedNode();
        $executor->enterVerificationWindow($this->freeze(), null);

        $restored = (new ProtectedModeFreezeStore())->load();
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, $restored?->phase);
        $this->assertSame(StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW, $restored?->entryMode);
        $this->assertNull($restored?->activatedAt);
        $this->assertNotNull($restored?->progressAt);
    }

    public function testANodeThatWasNeverFrozenFindsNoFreeze(): void
    {
        // The ordinary startup, and the one case that must not be read as trouble: no file at all.
        $this->assertNull(new ProtectedModeFreezeStore()->load());
    }

    public function testALiftedFreezeLeavesNothingOnDisk(): void
    {
        $store = new ProtectedModeFreezeStore();
        $store->save($this->row(StateProtectedModeRuntime::PHASE_ACTIVE));

        $store->forget();

        $this->assertFileDoesNotExist($this->freezeFile());
        $this->assertNull($store->load());
    }

    public function testForgettingAFreezeThatWasNeverWrittenIsNotAFailure(): void
    {
        // The lift runs on every node, including the ones that never got as far as writing a file.
        new ProtectedModeFreezeStore()->forget();

        $this->assertFileDoesNotExist($this->freezeFile());
    }

    public function testTheWriteLeavesNoTempFileBesideIt(): void
    {
        // The file is published rather than streamed into place, and the temp it is published from
        // must not survive: a leftover beside the freeze is one more thing an operator has to judge.
        new ProtectedModeFreezeStore()->save($this->row(StateProtectedModeRuntime::PHASE_ACTIVE));

        $this->assertSame(
            [$this->freezeFile()],
            array_values(array_filter(
                (array)glob($this->logDirectory . '/*'),
                static fn(string $path): bool => !str_ends_with($path, '.log'),
            )),
        );
    }

    public function testATruncatedFileRefusesTheStartup(): void
    {
        // What a crash during the write leaves, and the reason the write is published at all. It is
        // not "no freeze": the file is there because this node was frozen when it went down.
        file_put_contents($this->freezeFile(), '');

        $this->expectException(ProtectedModeFreezeUnreadableException::class);
        new ProtectedModeFreezeStore()->load();
    }

    public function testAFileThatIsNotJsonRefusesTheStartup(): void
    {
        file_put_contents($this->freezeFile(), 'frozen, honestly');

        $this->expectException(ProtectedModeFreezeUnreadableException::class);
        new ProtectedModeFreezeStore()->load();
    }

    public function testAFileFromAFormatThisBuildDoesNotKnowRefusesTheStartup(): void
    {
        // A build that cannot tell what the fields mean must say so rather than restore a freeze by
        // guessing at them - the version key exists for exactly this moment.
        file_put_contents($this->freezeFile(), (string)json_encode(['version' => 3, 'row' => []]));

        $this->expectException(ProtectedModeFreezeUnreadableException::class);
        new ProtectedModeFreezeStore()->load();
    }

    public function testAFileCarryingNoRowRefusesTheStartup(): void
    {
        file_put_contents($this->freezeFile(), (string)json_encode(['version' => 2]));

        $this->expectException(ProtectedModeFreezeUnreadableException::class);
        new ProtectedModeFreezeStore()->load();
    }

    public function testTheExecutorLeavesEveryPhaseItWritesOnDisk(): void
    {
        $executor = $this->executorOnAMountedNode();

        $executor->enterActivating($this->freeze(), 'accept-7');
        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVATING, $this->persistedPhase());

        $executor->enterActive();
        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, $this->persistedPhase());

        $executor->enterVerifying();
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, $this->persistedPhase());

        // The close back from the window is an entry (HIL-1128): activating, then active.
        $executor->enterActivating($this->freeze(), 'accept-7');
        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVATING, $this->persistedPhase());

        $executor->enterActive();
        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, $this->persistedPhase());

        $executor->enterDeactivating();
        $this->assertSame(StateProtectedModeRuntime::PHASE_DEACTIVATING, $this->persistedPhase());
    }

    public function testTheLiftTakesTheFreezeOffDisk(): void
    {
        $executor = $this->executorOnAMountedNode();
        $executor->enterActivating($this->freeze(), 'accept-7');

        $executor->enterInactive();

        $this->assertFileDoesNotExist($this->freezeFile());
    }

    /**
     * @throws InvalidFormatException When the fixture row is not one the state can be built from
     * @throws RtActionsCollectionNameNullException When the mounted item has no collection name
     * @throws RtTruthSourceWriteNotAllowedException When this process is not the row's writer
     */
    public function testARestoredFreezeKeepsTheOperatorsBrowserAndNothingElse(): void
    {
        // Every accept key on the row was minted on a 101 that died with the daemon, and the
        // passes with them. The one thing that does come back is the browser the operator asked
        // from: the exit out of the window is theirs to press (HIL-676), and a row without their
        // session hash shows it to nobody at all.
        $this->mountNode();
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        $this->assertNotNull($view);

        $view->actions->restoreFromDisk($this->restoredRow());

        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, $view->phase);
        $this->assertSame(self::OPERATION, $view->operation);
        $this->assertSame(self::STARTED_AT, $view->startedAt);
        $this->assertNull($view->initiatorAcceptKey);
        $this->assertSame([], $view->passHashes);
        $this->assertSame([], $view->admittedSessionTokenHashes);
        $this->assertSame(self::INITIATOR_SESSION_HASH, $view->initiatorSessionTokenHash);
        $this->assertTrue($view->enteredFromBrowser);
        $this->assertTrue($view->belongsToInitiator(self::INITIATOR_SESSION_HASH));
        $this->assertFalse($view->locksOut('accept-new', self::INITIATOR_SESSION_HASH));
        $this->assertTrue($view->locksOut('accept-7', null));
        $this->assertTrue($view->locksOut('accept-9', 'session-hash-9'));
    }

    /**
     * @throws InvalidFormatException When the fixture row is not one the state can be built from
     * @throws RtActionsCollectionNameNullException When the mounted item has no collection name
     * @throws RtTruthSourceWriteNotAllowedException When this process is not the row's writer
     */
    public function testRestoredManualWindowForgetsTheInitiatorsBrowser(): void
    {
        $this->mountNode();
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        $this->assertNotNull($view);

        $view->actions->restoreFromDisk($this->restoredRow(
            StateProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE,
            StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW,
        ));

        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, $view->phase);
        $this->assertSame(StateProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE, $view->operation);
        $this->assertSame(StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW, $view->entryMode);
        $this->assertSame(self::INITIATOR_TYPE, $view->initiatorAgentType);
        $this->assertNull($view->initiatorSessionTokenHash);
        $this->assertTrue($view->enteredFromBrowser);
        $this->assertFalse($view->belongsToInitiator(self::INITIATOR_SESSION_HASH));
        $this->assertTrue($view->locksOut('accept-new', self::INITIATOR_SESSION_HASH));
        $this->assertSame([], $view->passHashes);
        $this->assertSame([], $view->admittedSessionTokenHashes);
        $this->assertSame([], $view->circleSessionTokenHashes);
    }

    public function testAnOldFreezeFileReadsDiagnosticsAsUnpublished(): void
    {
        $row = $this->row(StateProtectedModeRuntime::PHASE_ACTIVE);
        unset(
            $row[StateProtectedModeRuntime::diagnosticsNodeId],
            $row[StateProtectedModeRuntime::stoppedAgentCount],
            $row[StateProtectedModeRuntime::preservedAgentTypes],
            $row[StateProtectedModeRuntime::agentStartGateClosed],
        );
        file_put_contents($this->freezeFile(), (string)json_encode(['version' => 2, 'row' => $row]));

        $restored = new ProtectedModeFreezeStore()->load();

        $this->assertNotNull($restored);
        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, $restored->phase);
        $this->assertNull($restored->diagnosticsNodeId);
        $this->assertNull($restored->stoppedAgentCount);
        $this->assertNull($restored->preservedAgentTypes);
        $this->assertNull($restored->agentStartGateClosed);
    }

    public function testAFreezeFileWithAForeignDiagnosticListRefusesStartup(): void
    {
        $row = $this->row(StateProtectedModeRuntime::PHASE_ACTIVE);
        $row[StateProtectedModeRuntime::preservedAgentTypes] = 'hilos_mail';
        new ProtectedModeFreezeStore()->save($row);

        $this->expectException(ProtectedModeFreezeUnreadableException::class);
        new ProtectedModeFreezeStore()->load();
    }

    public function testRestoreDropsDiagnosticsTheFileStillCarries(): void
    {
        $this->mountNode();
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        $this->assertNotNull($view);
        $loaded = $this->restoredRow();
        $loaded->diagnosticsNodeId = 'node-a';
        $loaded->stoppedAgentCount = 4;
        $loaded->preservedAgentTypes = ['hilos_mail'];
        $loaded->agentStartGateClosed = true;

        $view->actions->restoreFromDisk($loaded);

        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, $view->phase);
        $this->assertSame(self::INITIATOR_SESSION_HASH, $view->initiatorSessionTokenHash);
        $this->assertNull($view->diagnosticsNodeId);
        $this->assertNull($view->stoppedAgentCount);
        $this->assertNull($view->preservedAgentTypes);
        $this->assertNull($view->agentStartGateClosed);
    }

    public function testPublishingDiagnosticsDoesNotRewriteTheFreezeFile(): void
    {
        $executor = $this->executorOnAMountedNode();
        $executor->enterActivating($this->freeze(), 'accept-7');
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        $this->assertNotNull($view);
        $before = file_get_contents($this->freezeFile());

        $view->actions->updateAgentDiagnostics('node-a', 0, [], false);

        $this->assertSame($before, file_get_contents($this->freezeFile()));
        $this->assertSame('node-a', $view->diagnosticsNodeId);
        $this->assertSame(0, $view->stoppedAgentCount);
        $this->assertSame([], $view->preservedAgentTypes);
        $this->assertFalse($view->agentStartGateClosed);

        $executor->enterActive();

        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, $view->phase);
        $this->assertSame('node-a', $view->diagnosticsNodeId);
        $this->assertSame(0, $view->stoppedAgentCount);
        $this->assertSame([], $view->preservedAgentTypes);
        $this->assertFalse($view->agentStartGateClosed);
    }

    public function testNodeReportsAreExcludedFromSaveAndIgnoredOnLoadAndRestore(): void
    {
        $this->mountNode();
        $nodes = [
            ['nodeId' => 'node-diagnostic-only', 'phase' => 'active', 'quiesced' => true,
                'isLeader' => true, 'isInitiator' => false, 'reportState' => 'current'],
        ];
        Hilos::$rt->hilosProtectedModeRuntime->actions->updateMaintenanceNodes($nodes);
        $row = $this->restoredRow()->toArray();
        $row[StateProtectedModeRuntime::maintenanceNodes] = $nodes;

        new ProtectedModeFreezeStore()->save($row);
        $json = file_get_contents($this->freezeFile());
        $this->assertIsString($json);
        $this->assertStringNotContainsString(StateProtectedModeRuntime::maintenanceNodes, $json);
        $this->assertStringNotContainsString('node-diagnostic-only', $json);
        $loaded = new ProtectedModeFreezeStore()->load();
        $this->assertNotNull($loaded);
        $this->assertNull($loaded->maintenanceNodes);

        // A stale or foreign diagnostic field cannot become restored truth, even if malformed.
        $row[StateProtectedModeRuntime::maintenanceNodes] = 'obsolete diagnostic value';
        file_put_contents($this->freezeFile(), (string)json_encode(['version' => 2, 'row' => $row]));
        $loaded = new ProtectedModeFreezeStore()->load();
        $this->assertNotNull($loaded);
        $this->assertNull($loaded->maintenanceNodes);
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, $loaded->phase);

        // restoreFromDisk also refuses to reuse a diagnostic tuple handed directly to it.
        $loaded->maintenanceNodes = $nodes;
        Hilos::$rt->hilosProtectedModeRuntime->actions->restoreFromDisk($loaded);
        $this->assertNull(Hilos::$rt->hilosProtectedModeRuntime->maintenanceNodes);
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, Hilos::$rt->hilosProtectedModeRuntime->phase);
        $this->assertSame(self::INITIATOR_SESSION_HASH, Hilos::$rt->hilosProtectedModeRuntime->initiatorSessionTokenHash);
    }

    public function testPublishingNodeReportsDoesNotRewriteTheFreezeFile(): void
    {
        $executor = $this->executorOnAMountedNode();
        $executor->enterActivating($this->freeze(), 'accept-7');
        $before = file_get_contents($this->freezeFile());

        Hilos::$rt->hilosProtectedModeRuntime->actions->updateMaintenanceNodes([]);

        $this->assertSame($before, file_get_contents($this->freezeFile()));
        $executor->enterActive();
        $this->assertSame([], Hilos::$rt->hilosProtectedModeRuntime->maintenanceNodes);
        $json = file_get_contents($this->freezeFile());
        $this->assertIsString($json);
        $this->assertStringNotContainsString(StateProtectedModeRuntime::maintenanceNodes, $json);
    }

    public function testFreezeSaveExcludesWatchdogPresentationAndNodeReports(): void
    {
        $row = $this->row(StateProtectedModeRuntime::PHASE_ACTIVE);
        $row[StateProtectedModeRuntime::diagnosticsNodeId] = 'node-a';
        $row[StateProtectedModeRuntime::stoppedAgentCount] = 4;
        $snapshot = $this->watchdogSnapshot();

        new ProtectedModeFreezeStore()->save([...$row, ...$snapshot->toArray()]);

        $json = file_get_contents($this->freezeFile());
        $this->assertIsString($json);
        $this->assertSame(['version' => 2, 'row' => array_diff_key($row, [
            ...$snapshot->toArray(), StateProtectedModeRuntime::maintenanceNodes => null,
        ])], json_decode($json, true));
        foreach (array_keys($snapshot->toArray()) as $key) {
            $this->assertStringNotContainsString($key, $json);
        }
        $this->assertStringNotContainsString('ops@example.test', $json);
        $this->assertStringNotContainsString('quiesce-overdue', $json);
        $this->assertStringNotContainsString('node-pending', $json);
    }

    public function testVersionTwoWithoutWatchdogKeysLoadsWithUnpublishedDefaults(): void
    {
        $row = $this->row(StateProtectedModeRuntime::PHASE_ACTIVE);
        unset(
            $row[StateProtectedModeRuntime::watchdogVerdict],
            $row[StateProtectedModeRuntime::watchdogPendingNodeIds],
            $row[StateProtectedModeRuntime::watchdogQuiesceTimeoutSeconds],
            $row[StateProtectedModeRuntime::watchdogSilenceTimeoutSeconds],
            $row[StateProtectedModeRuntime::watchdogAlertIntervalSeconds],
            $row[StateProtectedModeRuntime::watchdogAlertEmails],
            $row[StateProtectedModeRuntime::watchdogManualRestartIsNormal],
        );
        file_put_contents($this->freezeFile(), (string)json_encode(['version' => 2, 'row' => $row]));

        $loaded = new ProtectedModeFreezeStore()->load();

        $this->assertNotNull($loaded);
        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, $loaded->phase);
        $this->assertNull($loaded->watchdogVerdict);
        $this->assertSame([], $loaded->watchdogPendingNodeIds);
        $this->assertNull($loaded->watchdogQuiesceTimeoutSeconds);
        $this->assertNull($loaded->watchdogSilenceTimeoutSeconds);
        $this->assertNull($loaded->watchdogAlertIntervalSeconds);
        $this->assertNull($loaded->watchdogAlertEmails);
        $this->assertNull($loaded->watchdogManualRestartIsNormal);
    }

    public function testRestoreDiscardsAllWatchdogFieldsEvenIfAnOlderDescriptorContainsThem(): void
    {
        $this->mountNode();
        Hilos::$rt->hilosProtectedModeRuntime->actions->updateWatchdog($this->watchdogSnapshot());
        file_put_contents($this->freezeFile(), (string)json_encode([
            'version' => 2,
            'row' => [...$this->restoredRow()->toArray(), ...$this->watchdogSnapshot()->toArray()],
        ]));
        $loaded = new ProtectedModeFreezeStore()->load();
        $this->assertNotNull($loaded);
        $this->assertSame('quiesce-overdue', $loaded->watchdogVerdict);

        Hilos::$rt->hilosProtectedModeRuntime->actions->restoreFromDisk($loaded);

        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, Hilos::$rt->hilosProtectedModeRuntime->phase);
        $this->assertNull(Hilos::$rt->hilosProtectedModeRuntime->watchdogVerdict);
        $this->assertSame([], Hilos::$rt->hilosProtectedModeRuntime->watchdogPendingNodeIds);
        $this->assertNull(Hilos::$rt->hilosProtectedModeRuntime->watchdogQuiesceTimeoutSeconds);
        $this->assertNull(Hilos::$rt->hilosProtectedModeRuntime->watchdogSilenceTimeoutSeconds);
        $this->assertNull(Hilos::$rt->hilosProtectedModeRuntime->watchdogAlertIntervalSeconds);
        $this->assertNull(Hilos::$rt->hilosProtectedModeRuntime->watchdogAlertEmails);
        $this->assertNull(Hilos::$rt->hilosProtectedModeRuntime->watchdogManualRestartIsNormal);
    }

    public function testWatchdogPublicationDoesNotRewriteTheFreezeFileAndConfigurationSurvivesLift(): void
    {
        $executor = $this->executorOnAMountedNode();
        $executor->enterActivating($this->freeze(), 'accept-7');
        $before = file_get_contents($this->freezeFile());

        Hilos::$rt->hilosProtectedModeRuntime->actions->updateWatchdog($this->watchdogSnapshot());

        $this->assertSame($before, file_get_contents($this->freezeFile()));
        $executor->enterInactive();
        $this->assertFileDoesNotExist($this->freezeFile());
        $this->assertNull(Hilos::$rt->hilosProtectedModeRuntime->watchdogVerdict);
        $this->assertSame([], Hilos::$rt->hilosProtectedModeRuntime->watchdogPendingNodeIds);
        $this->assertSame(31, Hilos::$rt->hilosProtectedModeRuntime->watchdogQuiesceTimeoutSeconds);
        $this->assertSame(601, Hilos::$rt->hilosProtectedModeRuntime->watchdogSilenceTimeoutSeconds);
        $this->assertSame(901, Hilos::$rt->hilosProtectedModeRuntime->watchdogAlertIntervalSeconds);
        $this->assertSame(['ops@example.test'], Hilos::$rt->hilosProtectedModeRuntime->watchdogAlertEmails);
        $this->assertFalse(Hilos::$rt->hilosProtectedModeRuntime->watchdogManualRestartIsNormal);
    }

    public function testAForeignPreservedTypeListIsRefusedBeforeItIsPublished(): void
    {
        $this->mountNode();
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        $this->assertNotNull($view);
        $view->actions->updateAgentDiagnostics('node-a', 3, ['hilos_mail'], true);

        try {
            $view->actions->updateAgentDiagnostics('node-b', 9, ['first' => 'hilos_mail'], false);
            $this->fail('A preserved-type map must be refused');
        } catch (InvalidFormatException $e) {
            $this->assertStringContainsString(StateProtectedModeRuntime::preservedAgentTypes, $e->getMessage());
        }

        $this->assertSame('node-a', $view->diagnosticsNodeId);
        $this->assertSame(3, $view->stoppedAgentCount);
        $this->assertSame(['hilos_mail'], $view->preservedAgentTypes);
        $this->assertTrue($view->agentStartGateClosed);
        $this->assertFileDoesNotExist($this->freezeFile());
    }

    /**
     * The hand-over the restored row is only ever seen through (HIL-699).
     *
     * The restore runs before any server binds, so the master holds the row alone and every
     * worker that comes up afterwards is told about it by the snapshot answered to its declared
     * interest - not by a frame, because there was nobody to send one to. This is that pair
     * taken on its own: what the owner reads out, and what the joining side ends up holding.
     * No socket is raised; the two sides are two mounted contexts.
     *
     * @throws InvalidFormatException When the fixture row is not one the state can be built from
     * @throws RtActionsCollectionNameNullException When the mounted item has no collection name
     * @throws RtTruthSourceWriteNotAllowedException When this process is not the row's writer
     * @throws HilosException When the joining side refuses the row it was handed
     */
    public function testTheRestoredFreezeIsWhatANewWorkerIsHandedOver(): void
    {
        $this->mountNode();
        $master = Hilos::$rt?->hilosProtectedModeRuntime;
        $this->assertNotNull($master);
        $master->actions->restoreFromDisk($this->restoredRow());

        $handedOver = RtSnapshot::rows(StateProtectedModeRuntime::RT_ITEM);

        Hilos::$rt = new FreezeStoreTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::create());
        RtSnapshot::replace(StateProtectedModeRuntime::RT_ITEM, $handedOver);

        $worker = Hilos::$rt->hilosProtectedModeRuntime;
        $this->assertNotNull($worker);
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, $worker->phase);
        $this->assertSame(self::INITIATOR_TYPE, $worker->initiatorAgentType);
        $this->assertSame(self::INITIATOR_SESSION_HASH, $worker->initiatorSessionTokenHash);
    }

    /** @return ProtectedModeWatchdogSnapshot Current process's effective configuration and alarm */
    private function watchdogSnapshot(): ProtectedModeWatchdogSnapshot
    {
        return new ProtectedModeWatchdogSnapshot('quiesce-overdue', ['node-pending'], 31, 601, 901, ['ops@example.test'], false);
    }

    /**
     * A freeze row as a node under one carries it.
     *
     * @param string $phase Phase the freeze stands on
     * @return array<string, mixed> Row in the shape the store writes
     */
    private function row(string $phase): array
    {
        return StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => $phase,
            StateProtectedModeRuntime::operation => self::OPERATION,
            StateProtectedModeRuntime::initiatorAcceptKey => 'accept-7',
            StateProtectedModeRuntime::initiatorAgentType => self::INITIATOR_TYPE,
            StateProtectedModeRuntime::initiatorAgentIndex => 2,
            StateProtectedModeRuntime::initiatorNodeId => 'node-a',
            StateProtectedModeRuntime::startedAt => self::STARTED_AT,
            StateProtectedModeRuntime::activatedAt => self::STARTED_AT + 1,
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
            StateProtectedModeRuntime::circleSessionTokenHashes => [],
            StateProtectedModeRuntime::circleNamedCount => 0,
            StateProtectedModeRuntime::enteredFromBrowser => false,
        ])->toArray();
    }

    /**
     * The freeze a node is handed back at startup, in the shape the executor left on disk.
     *
     * Carries the initiator's session hash because the file does: the phase is written by
     * {@see DaemonProtectedModeExecutor} handing the whole row over, not a chosen subset of it.
     * What the restore keeps of it is the decision under test, not what the disk offers.
     *
     * @param string $operation Operation carried by the file
     * @param string $entryMode Entry mode carried by the file
     * @return StateProtectedModeRuntime Freeze row as the store reads it back
     * @throws InvalidFormatException When the fixture row is not one the state can be built from
     */
    private function restoredRow(
        string $operation = self::OPERATION,
        string $entryMode = StateProtectedModeRuntime::ENTRY_MODE_FREEZE,
    ): StateProtectedModeRuntime
    {
        return StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_VERIFYING,
            StateProtectedModeRuntime::entryMode => $entryMode,
            StateProtectedModeRuntime::operation => $operation,
            StateProtectedModeRuntime::initiatorAcceptKey => 'accept-7',
            StateProtectedModeRuntime::initiatorSessionTokenHash => self::INITIATOR_SESSION_HASH,
            StateProtectedModeRuntime::initiatorAgentType => self::INITIATOR_TYPE,
            StateProtectedModeRuntime::startedAt => self::STARTED_AT,
            StateProtectedModeRuntime::passHashes => ['hash-of-a-pass'],
            StateProtectedModeRuntime::admittedSessionTokenHashes => ['session-hash-9'],
            StateProtectedModeRuntime::circleSessionTokenHashes => [],
            StateProtectedModeRuntime::circleNamedCount => 0,
            StateProtectedModeRuntime::enteredFromBrowser => true,
        ]);
    }

    /**
     * @return ProtectedModeQuiesceData Freeze descriptor the executor is driven with
     */
    private function freeze(): ProtectedModeQuiesceData
    {
        return new ProtectedModeQuiesceData(
            operation: self::OPERATION,
            initiatorAgentType: self::INITIATOR_TYPE,
            initiatorAgentIndex: 2,
            initiatorNodeId: 'node-a',
            initiatorSessionTokenHash: null,
        );
    }

    /**
     * Mounts the freeze row and makes this process its writer, as a node's master is.
     */
    private function mountNode(): void
    {
        Hilos::$rt = new FreezeStoreTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::create());
        Hilos::$cluster = new ClusterContext();
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
    }

    /**
     * @return DaemonProtectedModeExecutor Executor writing the row of a mounted node
     */
    private function executorOnAMountedNode(): DaemonProtectedModeExecutor
    {
        $this->mountNode();

        return new DaemonProtectedModeExecutor();
    }

    /**
     * @return ?string Phase the file on disk carries, or null when there is no file
     */
    private function persistedPhase(): ?string
    {
        return new ProtectedModeFreezeStore()->load()?->phase;
    }

    /**
     * @return string Absolute path of the freeze file these cases read and write
     */
    private function freezeFile(): string
    {
        return $this->logDirectory . '/' . ProtectedModeFreezeStore::FILE_NAME;
    }
}

/**
 * Project context that registers no runtime state of its own: the framework mount supplies the
 * freeze row.
 *
 * Named apart from its neighbours because the protected-mode test files share one namespace, so one
 * context name could not carry two classes and the suite would not load.
 */
final class FreezeStoreTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}
