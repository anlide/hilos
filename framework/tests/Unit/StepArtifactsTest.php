<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/step-artifacts.php';

/**
 * The parts of the step-artifact collector that answer without a stand: the word a
 * snapshot is headed with, the docker commands a stand's record turns into, the two
 * places the run reports a snapshot from, and the hypotheses the triage draws from
 * what was collected.
 *
 * Everything that talks to docker or copies a live stand's files is deliberately
 * out of scope — proving it would mean standing a demo up, which is the very thing
 * the full run already does. The file under test is a plain script rather than a
 * class, so it is required by path, the same way `scripts/run-test-suite.php`
 * requires it; the runner itself is not loaded here, because requiring it would
 * start a whole test run.
 */
final class StepArtifactsTest extends TestCase
{
    /** A step that reported no flaky tests, in the shape the runner reads out of its log. */
    private const array CLEAN = ['count' => 0, 'tests' => []];

    /** A step that reported one, which is enough to change the word. */
    private const array FLICKERED = ['count' => 1, 'tests' => ['tests/chat.spec.ts:42']];

    /** A demo stand: the whole compose project is the stand, so all of it may be asked about. */
    private const array DEMO_STAND = [
        'id' => 'chat',
        'cwd' => 'demo/chat',
        'composeFile' => 'docker/docker-compose.test.yml',
        'project' => 'hilos-chat-test',
        'mode' => 'project',
        'profiles' => [],
        'networks' => [],
        'holdsDatabase' => true,
    ];

    /** The framework stand: one profile inside a compose file that also holds the preview lane. */
    private const array PROFILE_STAND = [
        'id' => 'framework',
        'cwd' => '.',
        'composeFile' => 'framework/docker/docker-compose.yml',
        'project' => 'hilos-framework',
        'mode' => 'profile',
        'profiles' => ['test'],
        'networks' => ['hilos-framework_hilos-framework-test-network'],
        'holdsDatabase' => true,
    ];

    /**
     * Steps that name no stand on purpose. framework-image only builds an image;
     * cluster-unit runs in a disposable runtime without a stand. A new step without
     * a key must be added here rather than hide as "this one has none".
     */
    private const array STEPS_WITHOUT_A_STAND = ['framework-image', 'cluster-unit'];

    /** Temporary repository and snapshot trees used by the file-copy cases. */
    private ?string $fixtureRoot = null;

    /** Removes the fixture even if a copy assertion fails. */
    protected function tearDown(): void
    {
        if ($this->fixtureRoot !== null) {
            $this->removeFixtureTree($this->fixtureRoot);
        }

        parent::tearDown();
    }

    /** @return array<string, array{string, string}> Browser steps and their demo working directories. */
    public static function clusterBrowserSteps(): array
    {
        return [
            'binance-btc-tracker' => ['binance-btc-tracker-cluster-e2e', 'demo/binance-btc-tracker'],
            'online-testing' => ['online-testing-cluster-e2e', 'demo/online-testing'],
        ];
    }

    /**
     * Every node keeps its relative paths, including rotations from this fresh run.
     *
     * @param string $stepId Browser graph step.
     * @param string $cwd Demo working directory relative to the repository.
     */
    #[DataProvider('clusterBrowserSteps')]
    public function testClusterBrowserCopiesNodeTreesInsteadOfSingleNodeLogs(string $stepId, string $cwd): void
    {
        $root = $this->fixtureDirectory();
        $source = $root . '/' . $cwd . '/data/logs-cluster';
        mkdir($source . '/m1/archive/batch', 0o755, true);
        mkdir($source . '/s1/staging', 0o755, true);
        mkdir($root . '/' . $cwd . '/data/logs-test', 0o755, true);
        file_put_contents($source . '/m1/daemon.log', "master journal\n");
        file_put_contents($source . '/m1/archive/batch/worker.log.gz', 'rotated journal');
        file_put_contents($source . '/s1/agent-hilos_node_logs.log', "slave journal\n");
        file_put_contents($source . '/s1/staging/worker.log', "staged journal\n");
        file_put_contents($root . '/' . $cwd . '/data/logs-test/daemon.log', 'unrelated stand');

        $missing = copyStepLogs($root, $stepId, ['cwd' => $cwd], $root . '/snapshot', date('c', time() - 60));

        $this->assertSame([], $missing);
        $this->assertSame("master journal\n", file_get_contents($root . '/snapshot/nodes/m1/daemon.log'));
        $this->assertSame('rotated journal', file_get_contents($root . '/snapshot/nodes/m1/archive/batch/worker.log.gz'));
        $this->assertSame("slave journal\n", file_get_contents($root . '/snapshot/nodes/s1/agent-hilos_node_logs.log'));
        $this->assertSame("staged journal\n", file_get_contents($root . '/snapshot/nodes/s1/staging/worker.log'));
        $this->assertDirectoryDoesNotExist($root . '/snapshot/stand');
    }

    /**
     * A missing mandatory cluster root is recorded, even when the other stand has logs.
     *
     * @param string $stepId Browser graph step.
     * @param string $cwd Demo working directory relative to the repository.
     */
    #[DataProvider('clusterBrowserSteps')]
    public function testMissingClusterLogsAreReportedWithoutSubstitution(string $stepId, string $cwd): void
    {
        $root = $this->fixtureDirectory();
        mkdir($root . '/' . $cwd . '/data/logs-test', 0o755, true);
        file_put_contents($root . '/' . $cwd . '/data/logs-test/daemon.log', 'unrelated stand');
        $missing = copyStepLogs($root, $stepId, ['cwd' => $cwd], $root . '/snapshot', date('c', time() - 60));

        $this->assertSame(['nodes: missing ' . $root . '/' . $cwd . '/data/logs-cluster'], $missing);
        writeArtifactSnapshot($root . '/SNAPSHOT.txt', ['missing' => implode(ARTIFACT_VALUE_SEPARATOR, $missing)]);
        $this->assertStringContainsString('missing: nodes: missing ', file_get_contents($root . '/SNAPSHOT.txt'));
        $this->assertDirectoryDoesNotExist($root . '/snapshot/stand');
    }

    /**
     * A dangling file cannot be read even as root; the other node's evidence survives.
     *
     * @param string $stepId Browser graph step.
     * @param string $cwd Demo working directory relative to the repository.
     */
    #[DataProvider('clusterBrowserSteps')]
    public function testUnreadableClusterFileIsReportedAndOtherFilesAreCopied(string $stepId, string $cwd): void
    {
        $root = $this->fixtureDirectory();
        $source = $root . '/' . $cwd . '/data/logs-cluster';
        mkdir($source . '/m1', 0o755, true);
        mkdir($source . '/s1', 0o755, true);
        symlink($root . '/gone.log', $source . '/m1/daemon.log');
        file_put_contents($source . '/s1/daemon.log', 'still readable');

        $this->assertSame(
            ['nodes: could not copy ' . $source . '/m1/daemon.log'],
            copyStepLogs($root, $stepId, ['cwd' => $cwd], $root . '/snapshot', date('c', time() - 60)),
        );
        $this->assertSame('still readable', file_get_contents($root . '/snapshot/nodes/s1/daemon.log'));
    }

    /** Ordinary demo steps still collect their own stand's live logs. */
    public function testSingleNodeStepKeepsItsOriginalLogSource(): void
    {
        $root = $this->fixtureDirectory();
        mkdir($root . '/demo/chat/data/logs-test', 0o755, true);
        file_put_contents($root . '/demo/chat/data/logs-test/daemon.log', 'single node');

        $this->assertSame([], copyStepLogs($root, 'chat-e2e', self::DEMO_STAND, $root . '/snapshot', date('c', time() - 60)));
        $this->assertSame('single node', file_get_contents($root . '/snapshot/stand/daemon.log'));
        $this->assertDirectoryDoesNotExist($root . '/snapshot/nodes');
    }

    /**
     * A stand log last changed before the step started is an earlier stand's, and its
     * triage would otherwise quote that stand's error as this step's first clue.
     */
    public function testCopiesOnlyTheStandLogsTheStepWrote(): void
    {
        $root = $this->fixtureDirectory();
        mkdir($root . '/demo/chat/data/logs-test', 0o755, true);
        file_put_contents($root . '/demo/chat/data/logs-test/daemon.log', 'this step');
        file_put_contents($root . '/demo/chat/data/logs-test/worker-regular-1.error.log', 'ERROR: an earlier stand');
        touch($root . '/demo/chat/data/logs-test/worker-regular-1.error.log', time() - 3600);

        $this->assertSame([], copyStepLogs($root, 'chat-e2e', self::DEMO_STAND, $root . '/snapshot', date('c', time() - 60)));
        $this->assertSame('this step', file_get_contents($root . '/snapshot/stand/daemon.log'));
        $this->assertFileDoesNotExist($root . '/snapshot/stand/worker-regular-1.error.log');
    }

    /** A step takes the Playwright output it wrote; what an earlier run left in the demo stays out. */
    public function testCopiesOnlyThePlaywrightOutputTheStepWrote(): void
    {
        $root = $this->fixtureDirectory();
        mkdir($root . '/test-results/main/a-test', 0o755, true);
        mkdir($root . '/test-results/old-layout-test', 0o755, true);
        file_put_contents($root . '/test-results/main/a-test/trace.zip', 'this run');
        file_put_contents($root . '/test-results/old-layout-test/trace.zip', 'an earlier run');
        touch($root . '/test-results/old-layout-test/trace.zip', time() - 3600);

        $this->assertSame([], copyPlaywrightOutput($root . '/test-results', $root . '/snapshot', date('c', time() - 60)));
        $this->assertSame('this run', file_get_contents($root . '/snapshot/main/a-test/trace.zip'));
        $this->assertDirectoryDoesNotExist($root . '/snapshot/old-layout-test');
    }

    /** A step whose start cannot be read takes every file, as the collector did before it looked at times. */
    public function testCopiesEveryPlaywrightFileWhenTheStartCannotBeRead(): void
    {
        $root = $this->fixtureDirectory();
        mkdir($root . '/test-results/old-layout-test', 0o755, true);
        file_put_contents($root . '/test-results/old-layout-test/trace.zip', 'an earlier run');
        touch($root . '/test-results/old-layout-test/trace.zip', time() - 3600);

        $this->assertSame([], copyPlaywrightOutput($root . '/test-results', $root . '/snapshot', 'not a moment'));
        $this->assertSame('an earlier run', file_get_contents($root . '/snapshot/old-layout-test/trace.zip'));
    }

    /** A demo without Playwright output gives the snapshot nothing, not even an empty directory. */
    public function testCopiesNothingFromADemoWithoutPlaywrightOutput(): void
    {
        $root = $this->fixtureDirectory();

        $this->assertSame([], copyPlaywrightOutput($root . '/test-results', $root . '/snapshot', date('c')));
        $this->assertDirectoryDoesNotExist($root . '/snapshot');
    }

    /** A step without an override uses the stand's database; an override must stay shell quoted. */
    public function testDatabaseQuerySelectsTheStepDatabase(): void
    {
        $query = "set -e\necho \"\$MYSQL_DATABASE\"";

        $this->assertSame($query, databaseQuery($query, null));
        $this->assertSame("MYSQL_DATABASE='hilos-framework-test-2'\n" . $query, databaseQuery($query, 'hilos-framework-test-2'));
        $this->assertSame("MYSQL_DATABASE='a'\\''b'\n" . $query, databaseQuery($query, "a'b"));
    }

    /** A green step is named green, so that the directory being full says nothing on its own. */
    public function testNamesAGreenStepGreen(): void
    {
        $this->assertSame('green', artifactReason(0, self::CLEAN));
    }

    /** A step that passed on a retry is worth reading even though it passed. */
    public function testNamesAPassingStepThatFlickeredFlaky(): void
    {
        $this->assertSame('flaky', artifactReason(0, self::FLICKERED));
    }

    /** A failed step with nothing retried is plain red. */
    public function testNamesAFailedStepRed(): void
    {
        $this->assertSame('red', artifactReason(1, self::CLEAN));
    }

    /**
     * Red and flaky are not exclusive, and the compound word is why: a reader
     * sweeping for the flicker still finds the step that also failed outright.
     */
    public function testNamesAFailedStepThatAlsoFlickeredBoth(): void
    {
        $this->assertSame('red+flaky', artifactReason(1, self::FLICKERED));
    }

    /**
     * A stand owning its whole compose project is asked about as a whole, and the wildcard
     * profile is what reaches the services of it that sit behind one.
     */
    public function testAsksAProjectStandForItsWholeProject(): void
    {
        $this->assertSame(
            'docker compose -f \'docker/docker-compose.test.yml\' --profile "*" ps',
            standPsCommand(self::DEMO_STAND, []),
        );
    }

    /**
     * A stand sharing its compose file with the owner's preview lane is narrowed by naming
     * its services. `ps` ignores `--profile` and answers for the whole project, so the flag
     * would leave three of his containers in the evidence and report the stand standing
     * whenever his lane is up.
     */
    public function testNarrowsAProfileStandToItsOwnServices(): void
    {
        $command = standPsCommand(self::PROFILE_STAND, ['mysql-framework-test', 'mysql-framework-remote-test', 'hilos-cli-test']);

        $this->assertSame(
            'docker compose -f \'framework/docker/docker-compose.yml\''
                . ' ps \'mysql-framework-test\' \'mysql-framework-remote-test\' \'hilos-cli-test\'',
            $command,
        );
        $this->assertStringNotContainsString('--profile', $command);
    }

    /** A stand named by a step is the record the registry holds under that id. */
    public function testFindsTheRecordOfAStandTheRunKnows(): void
    {
        $stand = standById($this->repositoryRoot(), 'chat');

        $this->assertNotNull($stand);
        $this->assertSame('chat', $stand['id']);
        $this->assertSame('demo/chat', $stand['cwd']);
    }

    /**
     * An id nothing answers to comes back as null rather than as an error: the collector has
     * a line to write about it, and the run has no reason to stop over a snapshot.
     */
    public function testFindsNoRecordForAStandTheRunDoesNotKnow(): void
    {
        $this->assertNull(standById($this->repositoryRoot(), 'no-such-stand'));
    }

    /**
     * Every stand a step names is a stand that exists, and a step that drops one names it.
     * A typo in the manifest is otherwise found by a snapshot that came out empty, three
     * quarters of an hour into a run and only if someone reads the `missing` line.
     */
    public function testEveryStepNamesAStandTheRegistryHolds(): void
    {
        $stands = array_column(require __DIR__ . '/../../../scripts/test-stands.php', 'id');
        $steps = require __DIR__ . '/../../../scripts/test-suite.php';

        $this->assertNotSame([], $steps);
        foreach ($steps as $step) {
            if (($step['downsStand'] ?? false) === true) {
                $this->assertArrayHasKey('stand', $step, 'step ' . $step['id'] . ' drops a stand it never names');
            }
            if (!array_key_exists('stand', $step)) {
                continue;
            }
            $this->assertContains($step['stand'], $stands, 'step ' . $step['id'] . ' names an unknown stand');
        }
    }

    /**
     * A step that runs inside a stand names it. A step that does not is listed in
     * STEPS_WITHOUT_A_STAND, so a forgotten key cannot hide as "this one has none".
     */
    public function testEveryStepEitherNamesAStandOrIsListedAsHoldingNone(): void
    {
        $steps = require __DIR__ . '/../../../scripts/test-suite.php';

        $this->assertNotSame([], $steps);
        foreach ($steps as $step) {
            if (array_key_exists('stand', $step)) {
                $this->assertNotContains(
                    $step['id'],
                    self::STEPS_WITHOUT_A_STAND,
                    'step ' . $step['id'] . ' names a stand yet is listed as holding none',
                );
                continue;
            }
            $this->assertContains(
                $step['id'],
                self::STEPS_WITHOUT_A_STAND,
                'step ' . $step['id'] . ' names no stand and is not listed as holding none',
            );
        }
    }

    /** Every stand in the registry says whether it holds a database. */
    public function testEveryStandSaysWhetherItHoldsADatabase(): void
    {
        $stands = require __DIR__ . '/../../../scripts/test-stands.php';

        $this->assertNotSame([], $stands);
        foreach ($stands as $stand) {
            $this->assertArrayHasKey(
                'holdsDatabase',
                $stand,
                'stand ' . $stand['id'] . ' does not say whether it holds a database',
            );
        }
    }

    /** A stand that holds no database is not asked for one. */
    public function testAsksNoDatabaseOfAStandThatHoldsNone(): void
    {
        $frontend = standById($this->repositoryRoot(), 'frontend');

        $this->assertNotNull($frontend);
        $this->assertFalse(standHoldsDatabase($frontend));
    }

    /** A demo stand that holds a database is asked for it. */
    public function testAsksTheDatabaseOfAStandThatHoldsOne(): void
    {
        $this->assertTrue(standHoldsDatabase(self::DEMO_STAND));
    }

    /** A mixed stand is up: one running container is enough. */
    public function testCallsAStandUpWhileOneOfItsContainersRuns(): void
    {
        $containers = [
            ['Service' => 'chat-test', 'State' => 'exited', 'Health' => ''],
            ['Service' => 'mysql-test', 'State' => 'running', 'Health' => ''],
        ];

        $this->assertSame('up', standStateOfContainers($containers));
    }

    /** Docker holding only stopped containers is stopped, not down. */
    public function testCallsAStandWhoseEveryContainerStoppedStopped(): void
    {
        $containers = [
            ['Service' => 'chat-test', 'State' => 'exited', 'Health' => ''],
            ['Service' => 'mysql-test', 'State' => 'exited', 'Health' => ''],
        ];

        $this->assertSame('stopped', standStateOfContainers($containers));
    }

    /** An empty list is down: docker holds nothing of this stand. */
    public function testCallsAStandDockerHoldsNothingDown(): void
    {
        $this->assertSame('down', standStateOfContainers([]));
    }

    /** Docker artifacts are taken for a stand that is up or stopped, and for no other word. */
    public function testKeepsTheContainersOfAStandThatIsUpOrStopped(): void
    {
        $this->assertTrue(standKeepsContainers('up'));
        $this->assertTrue(standKeepsContainers('stopped'));
        $this->assertFalse(standKeepsContainers('down'));
        $this->assertFalse(standKeepsContainers('none'));
    }

    /** A stopped stand is not asked for its database; an up demo stand still is. */
    public function testAsksNoDatabaseOfAStoppedStand(): void
    {
        $this->assertFalse(standAnswersDatabase('stopped', self::DEMO_STAND));
        $this->assertTrue(standAnswersDatabase('up', self::DEMO_STAND));
    }

    /** A service that appears once keeps the log name the collector has always used. */
    public function testNamesAContainerLogByItsServiceWhenItIsTheOnlyOne(): void
    {
        $containers = [
            ['ID' => 'abc123', 'Service' => 'hilos-frontend-cli'],
        ];

        $this->assertSame(
            'hilos-frontend-cli.log',
            containerLogFileName($containers, $containers[0]),
        );
    }

    /**
     * Two containers of one service each get the id in the file name, so neither
     * overwrites the other and neither name depends on the order `ps` listed them.
     */
    public function testTellsTwoContainersOfOneServiceApartInTheirLogNames(): void
    {
        $containers = [
            ['ID' => 'aaa111', 'Service' => 'hilos-frontend-cli'],
            ['ID' => 'bbb222', 'Service' => 'hilos-frontend-cli'],
        ];

        $this->assertSame(
            'hilos-frontend-cli-aaa111.log',
            containerLogFileName($containers, $containers[0]),
        );
        $this->assertSame(
            'hilos-frontend-cli-bbb222.log',
            containerLogFileName($containers, $containers[1]),
        );
    }

    /**
     * The pointer names the step, the word and the path, so the reader who has just
     * read the verdict does not have to work out where to look.
     */
    public function testPointsAtTheSnapshotOfAStepWhoseStandIsGone(): void
    {
        $result = ['path' => '/var/test-suite/artifacts/framework', 'reason' => 'red', 'standCommand' => null];

        $this->assertSame(
            '--- artifacts framework (red): /var/test-suite/artifacts/framework',
            artifactPointerLine('framework', $result),
        );
    }

    /**
     * A stand still standing gets the command that opens it, because it is about to
     * be torn down by whatever the runner starts next.
     */
    public function testAddsTheWayInWhenTheStandIsStillUp(): void
    {
        $result = [
            'path' => '/var/test-suite/artifacts/chat-e2e',
            'reason' => 'red',
            'standCommand' => 'docker compose -f \'docker/docker-compose.test.yml\' --profile "*" ps (from demo/chat)',
        ];

        $this->assertSame(
            '--- artifacts chat-e2e (red): /var/test-suite/artifacts/chat-e2e'
                . ' — stand is UP: docker compose -f \'docker/docker-compose.test.yml\''
                . ' --profile "*" ps (from demo/chat)',
            artifactPointerLine('chat-e2e', $result),
        );
    }

    /** A stopped stand has no way in; an up one still does. */
    public function testOffersNoWayIntoAStoppedStand(): void
    {
        $command = 'docker compose -f \'docker/docker-compose.test.yml\' --profile "*" ps';
        $probe = ['state' => 'stopped', 'command' => $command];

        $this->assertNull(standEntryCommand($probe, self::DEMO_STAND));
        $this->assertSame(
            $command . ' (from demo/chat)',
            standEntryCommand(['state' => 'up', 'command' => $command], self::DEMO_STAND),
        );
    }

    /**
     * A run that collected nothing prints no section, the same silence at zero the
     * flaky section keeps: a heading that is always there stops being read.
     */
    public function testPrintsNoSummarySectionWhenNothingWasCollected(): void
    {
        $this->assertSame('', artifactSummarySection([]));
    }

    /** The section lists one snapshot per line, in the order the summary listed the steps. */
    public function testListsEverySnapshotItWasGiven(): void
    {
        $byStep = [
            'framework' => ['path' => '/var/test-suite/artifacts/framework', 'reason' => 'green'],
            'chat-e2e' => ['path' => '/var/test-suite/artifacts/chat-e2e', 'reason' => 'red+flaky'],
        ];

        $this->assertSame(
            "=== artifacts ===\n"
                . "  framework            green     /var/test-suite/artifacts/framework\n"
                . "  chat-e2e             red+flaky /var/test-suite/artifacts/chat-e2e\n",
            artifactSummarySection($byStep),
        );
    }

    /**
     * The freeze this node never left is read off the daemon's own log, and the
     * hypothesis says so rather than declaring the step innocent.
     */
    public function testRecognisesAStandThatCameUpFrozen(): void
    {
        $matched = matchTriageSignatures($this->collected([
            'daemonLog' => "[11:02:03] WARNING: Protected mode: this node came up still frozen for 'backup'\n",
        ]));

        $this->assertCount(1, $matched);
        $this->assertStringContainsString('came up already frozen', $matched[0]);
        $this->assertStringContainsString('protected-mode.state.json', $matched[0]);
    }

    /**
     * A step that gave up on its stand while a container was down is the stack
     * never coming up, and the reader is sent to that container rather than to the
     * Playwright report, which only recorded the waiting.
     */
    public function testRecognisesAStackThatNeverCameUp(): void
    {
        $matched = matchTriageSignatures($this->collected([
            'stepLog' => "Error: the daemon did not become ready within 60000ms\n",
            'containers' => [
                ['Service' => 'chat-test', 'State' => 'exited', 'Health' => ''],
                ['Service' => 'mysql-test', 'State' => 'running', 'Health' => 'healthy'],
            ],
        ]));

        $this->assertCount(1, $matched);
        $this->assertStringContainsString('never came up', $matched[0]);
        $this->assertStringContainsString('chat-test', $matched[0]);
        $this->assertStringNotContainsString('mysql-test', $matched[0]);
    }

    /**
     * The same waiting message with every container alive is the other hypothesis
     * entirely — the one HIL-717's third run was misread as a freeze, which could
     * never have printed this message at all.
     */
    public function testTellsADaemonThatStoppedAnsweringFromAStackThatNeverCameUp(): void
    {
        $matched = matchTriageSignatures($this->collected([
            'stepLog' => "Error: the daemon did not become ready within 60000ms\n",
            'containers' => [['Service' => 'chat-test', 'State' => 'running', 'Health' => 'healthy']],
        ]));

        $this->assertCount(1, $matched);
        $this->assertStringContainsString('stopped answering', $matched[0]);
        $this->assertStringContainsString('migration-status.txt', $matched[0]);
    }

    /**
     * Docker holding nothing is not docker holding a healthy stack. The collector has
     * no picture at all in that case, and saying every container was fine would be
     * the false comfort this whole file exists to end.
     */
    public function testDoesNotCallAnEmptyContainerListAHealthyStand(): void
    {
        $matched = matchTriageSignatures($this->collected([
            'stepLog' => 'gave up: did not become ready within 60000ms',
            'containers' => [],
        ]));

        $this->assertCount(1, $matched);
        $this->assertStringContainsString('a stand that was not there', $matched[0]);
        $this->assertStringNotContainsString('stopped answering', $matched[0]);
    }

    /**
     * A container that runs while failing its own health check is as unusable as a
     * stopped one, so it belongs on the same side of the fork.
     */
    public function testCountsAnUnhealthyContainerAsOneThatNeverCameUp(): void
    {
        $matched = matchTriageSignatures($this->collected([
            'stepLog' => 'gave up: did not become ready within 60000ms',
            'containers' => [['Service' => 'mysql-test', 'State' => 'running', 'Health' => 'unhealthy']],
        ]));

        $this->assertStringContainsString('never came up', $matched[0]);
        $this->assertStringContainsString('mysql-test', $matched[0]);
    }

    /**
     * The triage of a waited-for stand whose every container has exited sends the
     * reader to docker/logs/, which the collector now keeps under stopped.
     */
    public function testSendsTheReaderOfAStoppedStandToLogsTheSnapshotKeeps(): void
    {
        $containers = [
            ['Service' => 'chat-test', 'State' => 'exited', 'Health' => ''],
            ['Service' => 'mysql-test', 'State' => 'exited', 'Health' => ''],
        ];

        $this->assertSame('stopped', standStateOfContainers($containers));
        $this->assertTrue(standKeepsContainers('stopped'));
        $matched = matchTriageSignatures($this->collected([
            'stepLog' => 'gave up: did not become ready within 60000ms',
            'containers' => $containers,
        ]));

        $this->assertStringContainsString('docker/logs/', $matched[0]);
    }

    /** A worker's error is quoted into the triage, because quoting it is what found HIL-717. */
    public function testLiftsAWorkerErrorIntoTheTriage(): void
    {
        $matched = matchTriageSignatures($this->collected([
            'workerErrors' => [
                'worker-regular-1.error.log' => "ERROR: Failed to start per-node agent presence\n  at Daemon.php:12\n",
            ],
        ]));

        $this->assertCount(1, $matched);
        $this->assertStringContainsString('worker-regular-1.error.log', $matched[0]);
        $this->assertStringContainsString('Failed to start per-node agent presence', $matched[0]);
    }

    /**
     * The quote starts where the error is reported, not at the top of the file.
     * Deciding by one line and quoting another is how a triage ends up announcing an
     * error above five lines in which nothing is wrong.
     */
    public function testQuotesFromTheLineThatReportsTheErrorRatherThanTheTop(): void
    {
        $log = "worker started\nlistening\nregistered 3 agents\nheartbeat\n"
            . "waiting\nException: the roster does not have this agent\n  at Roster.php:88\n";

        $matched = matchTriageSignatures($this->collected([
            'workerErrors' => ['worker-regular-2.error.log' => $log],
        ]));

        $this->assertStringContainsString('Exception: the roster does not have this agent', $matched[0]);
        $this->assertStringNotContainsString('worker started', $matched[0]);
    }

    /** An error log that holds no error is not an error, and says nothing. */
    public function testSaysNothingAboutAWorkerErrorLogThatIsQuiet(): void
    {
        $matched = matchTriageSignatures($this->collected([
            'workerErrors' => ['worker-regular-1.error.log' => "started\nstopped\n"],
        ]));

        $this->assertSame(1, count($matched));
        $this->assertStringContainsString('No signature matched', $matched[0]);
    }

    /** A daemon that logged while no worker did is a master that forked none. */
    public function testRecognisesAMasterThatForkedNoWorkers(): void
    {
        $matched = matchTriageSignatures($this->collected([
            'daemonLog' => "Master started\n",
            'workerLogs' => [],
        ]));

        $this->assertCount(1, $matched);
        $this->assertStringContainsString('forked no workers', $matched[0]);
    }

    /**
     * A step whose daemon never wrote a line has no missing workers either. Every
     * `<demo>-check` owns its demo's log directory and starts no daemon at all, so
     * the master has to be heard from before its silence about workers is news.
     */
    public function testDoesNotBlameAStepWhoseDaemonNeverRanForHavingNoWorkers(): void
    {
        $matched = matchTriageSignatures($this->collected(['daemonLog' => '', 'workerLogs' => []]));

        $this->assertCount(1, $matched);
        $this->assertStringContainsString('No signature matched', $matched[0]);
    }

    /**
     * The reading order names what this snapshot holds and nothing else: a red
     * framework step has no stand log, no ps and no report, and sending its reader
     * to all three would say the triage does not know what it is looking at.
     */
    public function testNamesOnlyThePlacesThisSnapshotHolds(): void
    {
        $matched = matchTriageSignatures($this->collected(['holds' => []]));

        $this->assertStringContainsString("Check, in this order: SNAPSHOT.txt, and the step's own log", $matched[0]);
        $this->assertStringNotContainsString('daemon.log', $matched[0]);
    }

    /** A snapshot that does hold them lists them, in the order worth trying. */
    public function testNamesThePlacesInTheOrderWorthTrying(): void
    {
        $matched = matchTriageSignatures($this->collected([
            'holds' => ['stand/daemon.log', 'playwright/report'],
        ]));

        $this->assertStringContainsString(
            'SNAPSHOT.txt, stand/daemon.log, playwright/report',
            $matched[0],
        );
    }

    /**
     * Nothing matching is stated rather than left silent, with the order to read the
     * snapshot in: silence would be read as a clean bill of health, which is the one
     * thing a step with a triage file is not.
     */
    /**
     * Compose refusing to start a service over an unhealthy dependency ran no test, and
     * says why only in the dependency's own log, so the triage carries the end of it.
     */
    public function testRecognisesADependencyThatFailedItsHealthCheck(): void
    {
        $refusal = "[Warning] Access denied for user 'healthcheck'@'localhost' (using password: YES)";
        $matched = matchTriageSignatures($this->collected([
            'stepLog' => "dependency failed to start: container chat-mysql-test-s3 is unhealthy\n",
            'containers' => [[
                'ID' => '82b5f07523f5',
                'Name' => 'chat-mysql-test-s3',
                'Service' => 'mysql-test',
                'State' => 'running',
                'Health' => 'unhealthy',
            ]],
            'dockerLogs' => ['mysql-test.log' => "ready for connections.\n" . $refusal . "\n"],
        ]));

        $this->assertCount(1, $matched);
        $this->assertStringContainsString('chat-mysql-test-s3', $matched[0]);
        $this->assertStringContainsString('no test ran', $matched[0]);
        $this->assertStringContainsString($refusal, $matched[0]);
        $this->assertStringContainsString('docker/logs/mysql-test.log', $matched[0]);
    }

    /** Without the dependency's log in the snapshot the triage still names it, and says where to ask instead. */
    public function testNamesAnUnhealthyDependencyWhoseLogTheSnapshotLacks(): void
    {
        $matched = matchTriageSignatures($this->collected([
            'stepLog' => "dependency failed to start: container chat-mysql-test-s3 is unhealthy\n",
        ]));

        $this->assertCount(1, $matched);
        $this->assertStringContainsString('chat-mysql-test-s3', $matched[0]);
        $this->assertStringContainsString('holds no log of', $matched[0]);
    }

    public function testSaysSoAndWhereToLookWhenNoSignatureMatched(): void
    {
        $matched = matchTriageSignatures($this->collected([]));

        $this->assertCount(1, $matched);
        $this->assertStringContainsString('not a clean bill of health', $matched[0]);
        $this->assertStringContainsString('Check, in this order: SNAPSHOT.txt', $matched[0]);
    }

    /** Two hypotheses can fit at once, and both are reported rather than the first winning. */
    public function testReportsEveryHypothesisThatFits(): void
    {
        $matched = matchTriageSignatures($this->collected([
            'daemonLog' => 'Protected mode: this node came up still frozen for ' . "'backup'",
            'workerErrors' => ['worker-regular-1.error.log' => "Exception: nothing answered\n"],
        ]));

        $this->assertCount(2, $matched);
    }

    /**
     * A snapshot of a step that had nothing wrong with it, which every signature is
     * then measured against one field at a time.
     *
     * @param array<string, mixed> $collected The fields this case is about.
     * @return array<string, mixed>
     */
    private function collected(array $collected): array
    {
        return [
            'daemonLog' => '',
            'workerErrors' => [],
            'workerLogs' => ['worker-regular-1.log'],
            'stepLog' => '',
            'holds' => ['stand/daemon.log'],
            'containers' => [],
            'dockerLogs' => [],
            ...$collected,
        ];
    }

    /** @return string Isolated fixture root, remembered for teardown. */
    private function fixtureDirectory(): string
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/hilos-step-artifacts-' . uniqid('', true);
        mkdir($this->fixtureRoot, 0o755, true);

        return $this->fixtureRoot;
    }

    /** @param string $path Fixture directory to remove without following symlinks. */
    private function removeFixtureTree(string $path): void
    {
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($path . '/' . $entry) && !is_link($path . '/' . $entry)) {
                $this->removeFixtureTree($path . '/' . $entry);
            } else {
                unlink($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    /**
     * The repository root, from this file's own place in it, so the compose-file
     * checks read the real tree rather than a fixture that could drift from it.
     *
     * @return string
     */
    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
