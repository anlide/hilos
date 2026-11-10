<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/stand-instance.php';
require_once __DIR__ . '/../../../scripts/stand-teardown.php';

/**
 * The parts of the stand teardown that answer without docker: the stand list being readable at
 * all, the commands each mode builds, what is read back out of docker's answer, the wait for what
 * docker is still taking away, and the line a teardown is reported by.
 *
 * Actually removing a container is out of scope on purpose — proving it would mean standing a
 * demo up, which is the very thing the full run already does. What is worth pinning here is the
 * shape of the commands, because the mistakes they guard against are invisible at runtime: a
 * `profile` stand that quietly grew `--profile "*"` would take the owner's preview lane down with
 * it, a residue lookup by project label alone would hand the preview containers to `docker rm`,
 * and profiles matching no service take nothing down while exiting zero.
 *
 * Whether the registry agrees with the compose file — that `test` still names services — is not
 * answerable here and is not tried: it needs docker. The teardown answers it at the moment it
 * matters, by reporting a problem instead of a clean stand.
 *
 * The file under test is a plain script rather than a class, so it is required by path, the same
 * way `scripts/down-stands.php` requires it.
 */
final class StandTeardownTest extends TestCase
{
    /** A demo stand: the whole compose project is the stand, so all of it may go. */
    private const array DEMO_STAND = [
        'id' => 'chat',
        'cwd' => 'demo/chat',
        'composeFile' => 'docker/docker-compose.test.yml',
        'project' => 'hilos-chat-test',
        'mode' => 'project',
        'profiles' => [],
        'networks' => [],
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
    ];

    /**
     * The same stand with its networks forgotten — the shape a careless edit of the registry
     * leaves behind. A copy rather than an edit of the one above, which six other cases need
     * whole.
     */
    private const array PROFILE_STAND_WITHOUT_NETWORK = [
        'id' => 'framework',
        'cwd' => '.',
        'composeFile' => 'framework/docker/docker-compose.yml',
        'project' => 'hilos-framework',
        'mode' => 'profile',
        'profiles' => ['test'],
        'networks' => [],
    ];

    /** Docker's answer once the stand is gone. */
    private const array NOTHING_HELD = ['containers' => [], 'networks' => []];

    /** Docker's answer while a stand is still standing: a one-off container and the network it sits on. */
    private const array STAND_HELD = [
        'containers' => ['hilos-chat-test-app-run-abc123'],
        'networks' => ['hilos-chat-test_default'],
    ];

    /** Docker's answer once the container is gone and the network it held has not been asked for again. */
    private const array NETWORK_HELD = ['containers' => [], 'networks' => ['hilos-chat-test_default']];

    /** Every stand the run knows about, in the shape the teardown reads. */
    public function testEveryDeclaredStandCarriesWhatTheTeardownReads(): void
    {
        $stands = require __DIR__ . '/../../../scripts/test-stands.php';

        $this->assertNotSame([], $stands);
        foreach ($stands as $stand) {
            foreach (['id', 'cwd', 'composeFile', 'project', 'mode', 'profiles', 'networks'] as $key) {
                $this->assertArrayHasKey($key, $stand, 'stand ' . ($stand['id'] ?? '?') . ' is missing ' . $key);
            }
            $this->assertContains($stand['mode'], ['project', 'profile'], 'stand ' . $stand['id'] . ' has an unknown mode');
        }
    }

    /**
     * A `profile` stand names both what it drops and what it owns, and a `project` stand names
     * neither. A profile-less `profile` stand would drop nothing while exiting zero, and a
     * network-less one would ask `docker network ls` with no filter at all.
     */
    public function testAProfileStandDeclaresTheProfilesAndNetworksItOwns(): void
    {
        $stands = require __DIR__ . '/../../../scripts/test-stands.php';

        foreach ($stands as $stand) {
            if ($stand['mode'] !== 'profile') {
                $this->assertSame([], $stand['profiles'], 'stand ' . $stand['id'] . ' lists profiles it never uses');
                $this->assertSame([], $stand['networks'], 'stand ' . $stand['id'] . ' lists networks it never asks about');

                continue;
            }
            $this->assertNotSame([], $stand['profiles'], 'profile stand ' . $stand['id'] . ' drops nothing');
            $this->assertNotSame([], $stand['networks'], 'profile stand ' . $stand['id'] . ' owns no network to sweep');
        }
    }

    /**
     * The frontend CLI runner is a stand of its own, in its own compose project. Back inside the
     * framework project it was a service of the framework stand, whose teardown then killed the
     * container the frontend steps were running in — the step reported rc=137 and no reason.
     */
    public function testTheFrontendRunnerHasItsOwnStand(): void
    {
        $stands = require __DIR__ . '/../../../scripts/test-stands.php';

        $byId = array_column($stands, null, 'id');
        $this->assertArrayHasKey('framework', $byId);
        $this->assertNotContains('frontend', $byId['framework']['profiles']);
        $this->assertArrayHasKey('frontend', $byId);
        $this->assertSame('hilos-frontend', $byId['frontend']['project']);
        $this->assertSame('project', $byId['frontend']['mode']);
        $this->assertSame('framework/docker/docker-compose.frontend.yml', $byId['frontend']['composeFile']);
    }

    /** A demo stand goes down as a whole project, orphans included. */
    public function testDropsADemoStandAsAWholeProject(): void
    {
        $command = standDownCommand(self::DEMO_STAND);

        $this->assertStringContainsString('down --remove-orphans', $command);
        $this->assertStringContainsString("-f 'docker/docker-compose.test.yml'", $command);
    }

    /**
     * The framework stand goes down by its own profiles. `--profile "*"` here would reach the
     * owner's preview containers, since they sit behind profiles of the same compose file.
     */
    public function testDropsTheFrameworkStandByItsOwnProfiles(): void
    {
        $command = standDownCommand(self::PROFILE_STAND);

        $this->assertStringContainsString("--profile 'test'", $command);
        $this->assertStringNotContainsString("--profile 'frontend'", $command);
        $this->assertStringContainsString('down --remove-orphans', $command);
        $this->assertStringNotContainsString('--profile "*"', $command);
    }

    /** The services of a `profile` stand come out of the compose file, by the profiles it named. */
    public function testAsksComposeWhichServicesTheProfilesHold(): void
    {
        $command = standServicesCommand(self::PROFILE_STAND);

        $this->assertNotNull($command);
        $this->assertStringContainsString("-f 'framework/docker/docker-compose.yml'", $command);
        $this->assertStringContainsString("--profile 'test'", $command);
        $this->assertStringContainsString('config --services', $command);
    }

    /** A `project` stand owns every service in its file, so there is nothing to resolve. */
    public function testAsksComposeNothingAboutADemoStandsServices(): void
    {
        $this->assertNull(standServicesCommand(self::DEMO_STAND));
    }

    /** The one-off containers are found by project label, which is the only thing they share. */
    public function testLooksUpADemoStandsContainersByProjectLabel(): void
    {
        $command = standResidueContainersCommand(self::DEMO_STAND);

        $this->assertStringContainsString("--filter 'label=com.docker.compose.project=hilos-chat-test'", $command);
    }

    /**
     * One lookup serves both modes, and it prints the service label beside the name: the project
     * label is all docker can filter on, and php does the narrowing from there.
     */
    public function testLooksUpContainersByProjectLabelAndPrintsTheirService(): void
    {
        $command = standResidueContainersCommand(self::PROFILE_STAND);

        $this->assertStringContainsString('{{.Names}}\t{{.Label "com.docker.compose.service"}}', $command);
        $this->assertStringContainsString("--filter 'label=com.docker.compose.project=hilos-framework'", $command);
    }

    /** A demo stand owns its networks and is asked about them. */
    public function testAsksADemoStandAboutItsNetworks(): void
    {
        $command = standResidueNetworksCommand(self::DEMO_STAND);

        $this->assertStringContainsString("--filter 'label=com.docker.compose.project=hilos-chat-test'", $command);
    }

    /**
     * The framework stand is asked about the network it named, anchored: by project label it would
     * also get the default network the preview lane shares with it.
     */
    public function testAsksAProfileStandAboutTheNetworksItNamed(): void
    {
        $command = standResidueNetworksCommand(self::PROFILE_STAND);

        $this->assertStringContainsString("--filter 'name=^hilos-framework_hilos-framework-test-network$'", $command);
        $this->assertStringNotContainsString('label=com.docker.compose.project', $command);
    }

    /** What docker printed, read back as names. */
    public function testReadsTheNamesDockerPrinted(): void
    {
        $this->assertSame(
            ['hilos-chat-test-mysql-1', 'hilos-chat-test-app-run-abc123'],
            teardownNames("hilos-chat-test-mysql-1\nhilos-chat-test-app-run-abc123\n"),
        );
    }

    /** Docker holding nothing prints a blank line, which is not a container called "". */
    public function testReadsAnEmptyAnswerAsNothingHeld(): void
    {
        $this->assertSame([], teardownNames("\n"));
    }

    /** A stand that owns its whole project keeps every container docker named. */
    public function testKeepsEveryContainerOfAStandThatOwnsItsProject(): void
    {
        $this->assertSame(
            ['hilos-chat-test-mysql-1', 'hilos-chat-test-app-run-abc123'],
            teardownContainerNames("hilos-chat-test-mysql-1\tmysql\nhilos-chat-test-app-run-abc123\tapp\n", null),
        );
    }

    /** A stand that shares its project keeps only the containers of its own services. */
    public function testKeepsOnlyTheContainersOfTheStandsOwnServices(): void
    {
        $output = "hilos-mysql-framework-test\tmysql-framework-test\n"
            . "hilos-mysql-framework-remote-test\tmysql-framework-remote-test\n"
            . "hilos-preview-caddy\thilos-preview-caddy\n"
            . "hilos-framework-hilos-cli-test-run-9f2\thilos-cli-test\n";

        $this->assertSame(
            ['hilos-mysql-framework-test', 'hilos-mysql-framework-remote-test', 'hilos-framework-hilos-cli-test-run-9f2'],
            teardownContainerNames($output, ['mysql-framework-test', 'mysql-framework-remote-test', 'hilos-cli-test']),
        );
    }

    /** Docker holding nothing prints a blank line here too, and it is not a container either. */
    public function testReadsAnEmptyContainerAnswerAsNothingHeld(): void
    {
        $this->assertSame([], teardownContainerNames("\n", ['mysql-framework-test']));
    }

    /** A stand that had nothing to remove says so, so that the log covers every stand. */
    public function testDescribesAStandThatWasAlreadyClean(): void
    {
        $this->assertSame(
            'stands: chat — clean',
            describeTeardown(self::cleanResult('chat')),
        );
    }

    /** What was removed is counted, because the count is what says the stand was not empty. */
    public function testDescribesWhatWasRemoved(): void
    {
        $result = [
            'id' => 'chat',
            'problem' => '',
            'removedContainers' => ['hilos-chat-test-mysql-1', 'hilos-chat-test-app-run-abc123'],
            'removedNetworks' => ['hilos-chat-test_default'],
            'residue' => ['containers' => [], 'networks' => []],
        ];

        $this->assertSame('stands: chat — removed: 2 container(s), 1 network(s)', describeTeardown($result));
    }

    /** What survived is named rather than counted: the run is about to stop, and by what. */
    public function testDescribesWhatSurvivedTheTeardown(): void
    {
        $result = [
            'id' => 'chat',
            'problem' => '',
            'removedContainers' => ['hilos-chat-test-mysql-1'],
            'removedNetworks' => [],
            'residue' => ['containers' => ['hilos-chat-test-app-run-abc123'], 'networks' => ['hilos-chat-test_default']],
        ];

        $this->assertSame(
            'stands: chat — LEFT BEHIND: hilos-chat-test-app-run-abc123, hilos-chat-test_default',
            describeTeardown($result),
        );
    }

    /**
     * A stand nobody could ask about says that first. Its residue is empty for the same reason its
     * teardown did nothing, so reporting the residue would report the stand as clean.
     */
    public function testDescribesAStandItCouldNotAskAboutBeforeItsResidue(): void
    {
        $result = [
            'id' => 'framework',
            'problem' => 'profiles test match no service in framework/docker/docker-compose.yml',
            'removedContainers' => [],
            'removedNetworks' => [],
            'residue' => ['containers' => [], 'networks' => []],
        ];

        $this->assertSame(
            'stands: framework — CANNOT ASK: profiles test match no service'
                . ' in framework/docker/docker-compose.yml',
            describeTeardown($result),
        );
    }

    /** The problem is the stand's own declaration, so it is named by what was declared. */
    public function testNamesTheProfilesThatMatchedNoService(): void
    {
        $this->assertSame(
            'profiles test match no service in framework/docker/docker-compose.yml',
            standProblem(self::PROFILE_STAND, []),
        );
    }

    /**
     * A `profile` stand that named no network is a problem even though its profiles resolved: its
     * network lookup would carry no filter, and every network on the box would answer it.
     */
    public function testNamesTheProfileStandThatDeclaredNoNetwork(): void
    {
        $this->assertSame(
            'profiles test declare no network in framework/docker/docker-compose.yml',
            standProblem(self::PROFILE_STAND_WITHOUT_NETWORK, ['mysql-framework-test']),
        );
    }

    /**
     * Every stand's network lookup narrows to something. The registry is read rather than copied
     * into a constant here, because what this guards against is an edit to the registry, and a
     * copy would keep passing while the real list degenerated.
     */
    public function testEveryStandsNetworkLookupIsFiltered(): void
    {
        $stands = require __DIR__ . '/../../../scripts/test-stands.php';

        foreach ($stands as $stand) {
            $this->assertStringContainsString(
                '--filter',
                standResidueNetworksCommand($stand),
                'stand ' . $stand['id'] . ' asks docker for every network on the box',
            );
        }
    }

    /**
     * A teardown running in one instance reaches no name of another: the default instance and
     * each numbered one own disjoint projects and networks, and the residue lookup is closed by
     * its quote, so `hilos-chat-test` is never read as the start of `hilos-chat-test-s2`.
     */
    public function testEveryInstanceTearsDownOnlyItsOwnStands(): void
    {
        $suffixes = [false];
        for ($instance = STAND_INSTANCE_MIN; $instance <= STAND_INSTANCE_MAX; $instance++) {
            $suffixes[] = standInstanceEnvironment($instance)[STAND_INSTANCE_SUFFIX_VAR];
        }

        $taken = [];
        foreach ($suffixes as $suffix) {
            $stands = self::standsOfInstance($suffix);
            $names = array_merge(array_column($stands, 'project'), ...array_column($stands, 'networks'));
            $this->assertSame(
                [],
                array_values(array_intersect($names, $taken)),
                'instance ' . var_export($suffix, true) . ' tears down names of another instance',
            );
            $taken = array_merge($taken, $names);

            foreach ($stands as $stand) {
                $this->assertStringContainsString(
                    "--filter 'label=com.docker.compose.project=" . $stand['project'] . "'",
                    standResidueContainersCommand($stand),
                    'stand ' . $stand['id'] . ' looks its containers up by more than its exact project',
                );
            }
        }
    }

    /** A stand whose profiles resolved, and a stand with no profiles to resolve, both report none. */
    public function testReportsNoProblemWhenTheStandCouldBeAsked(): void
    {
        $this->assertSame('', standProblem(self::PROFILE_STAND, ['mysql-framework-test']));
        $this->assertSame('', standProblem(self::DEMO_STAND, null));
    }

    /** The usual teardown: `down` took everything, so nothing is removed and nothing is waited for. */
    public function testDrainingAnEmptyStandNeitherRemovesNorPauses(): void
    {
        $drain = self::drainAgainst([self::NOTHING_HELD]);

        $this->assertSame(self::NOTHING_HELD, $drain['left']);
        $this->assertSame([], $drain['removed']);
        $this->assertSame([], $drain['pauses']);
    }

    /** One removal that takes everything is the end of it: the pause is only for what is still there. */
    public function testDrainingStopsWithoutAPauseWhenTheFirstRemovalTookEverything(): void
    {
        $drain = self::drainAgainst([self::STAND_HELD, self::NOTHING_HELD]);

        $this->assertSame(self::NOTHING_HELD, $drain['left']);
        $this->assertSame([self::STAND_HELD], $drain['removed']);
        $this->assertSame([], $drain['pauses']);
    }

    /** Docker finishing while the teardown waits is a clean teardown, however long it took. */
    public function testDrainingReportsNothingLeftWhenTheResidueGoesDuringTheWait(): void
    {
        $drain = self::drainAgainst([self::STAND_HELD, self::STAND_HELD, self::STAND_HELD, self::NOTHING_HELD]);

        $this->assertSame(self::NOTHING_HELD, $drain['left']);
        $this->assertSame([self::STAND_HELD, self::STAND_HELD, self::STAND_HELD], $drain['removed']);
        $this->assertSame([2_000_000, 2_000_000], $drain['pauses']);
        $this->assertStringNotContainsString(
            'LEFT BEHIND',
            describeTeardown([...self::cleanResult('chat'), 'residue' => $drain['left']]),
        );
    }

    /**
     * A container that really hangs is still red, and it is red at five minutes: 150 pauses of two
     * seconds bring the clock onto the ceiling, and the look made there is the last one.
     */
    public function testDrainingGivesUpAtTheCeilingAndReportsWhatIsLeft(): void
    {
        $drain = self::drainAgainst([self::STAND_HELD]);

        $this->assertSame(self::STAND_HELD, $drain['left']);
        $this->assertCount(151, $drain['removed']);
        $this->assertSame(array_fill(0, 150, 2_000_000), $drain['pauses']);
        $this->assertSame(
            'stands: chat — LEFT BEHIND: hilos-chat-test-app-run-abc123, hilos-chat-test_default',
            describeTeardown([...self::cleanResult('chat'), 'residue' => $drain['left']]),
        );
    }

    /**
     * A killed `down` leaves the network to the removal, and docker refuses it while the container
     * is attached. The second pass has to ask for it again, which is why waiting alone is not enough.
     */
    public function testDrainingRemovesOnTheSecondPassTheNetworkRefusedOnTheFirst(): void
    {
        $drain = self::drainAgainst([self::STAND_HELD, self::NETWORK_HELD, self::NOTHING_HELD]);

        $this->assertSame(self::NOTHING_HELD, $drain['left']);
        $this->assertSame([self::STAND_HELD, self::NETWORK_HELD], $drain['removed']);
        $this->assertSame([2_000_000], $drain['pauses']);
    }

    /**
     * Drain a stand against a docker answering from a list, on a clock that only the pauses move.
     *
     * Docker gives the answers in order and goes on repeating the last one, which is what a
     * container that never goes away looks like.
     *
     * @param array<int, array{containers: array<int, string>, networks: array<int, string>}> $answers
     *     What each look at docker finds.
     * @return array{left: array{containers: array<int, string>, networks: array<int, string>},
     *     removed: array<int, array{containers: array<int, string>, networks: array<int, string>}>,
     *     pauses: array<int, int>} What is left, what each removal was handed, and each pause asked for.
     */
    private static function drainAgainst(array $answers): array
    {
        $moment = 0.0;
        $removed = [];
        $pauses = [];

        $left = drainStandResidue(
            static function () use (&$answers): array {
                return count($answers) > 1 ? array_shift($answers) : $answers[0];
            },
            static function (array $held) use (&$removed): void {
                $removed[] = $held;
            },
            static function () use (&$moment): float {
                return $moment;
            },
            static function (int $microseconds) use (&$moment, &$pauses): void {
                $pauses[] = $microseconds;
                $moment += $microseconds / 1_000_000;
            },
        );

        return ['left' => $left, 'removed' => $removed, 'pauses' => $pauses];
    }

    /**
     * A teardown result that removed nothing and left nothing.
     *
     * @return array{id: string, problem: string, removedContainers: array<int, string>,
     *     removedNetworks: array<int, string>,
     *     residue: array{containers: array<int, string>, networks: array<int, string>}}
     */
    private static function cleanResult(string $id): array
    {
        return [
            'id' => $id,
            'problem' => '',
            'removedContainers' => [],
            'removedNetworks' => [],
            'residue' => ['containers' => [], 'networks' => []],
        ];
    }

    /**
     * The registry as a process of the given instance reads it. The caller's own suffix comes
     * back afterwards, because these tests may themselves run inside an instance.
     *
     * @param string|false $suffix The instance suffix, or false for the default instance
     * @return array<int, array<string, mixed>> The stands of that instance
     */
    private static function standsOfInstance(string|false $suffix): array
    {
        $caller = getenv(STAND_INSTANCE_SUFFIX_VAR);

        self::putInstanceSuffix($suffix);
        try {
            return require __DIR__ . '/../../../scripts/test-stands.php';
        } finally {
            self::putInstanceSuffix($caller);
        }
    }

    /**
     * @param string|false $suffix The value to set, or false to unset the variable
     */
    private static function putInstanceSuffix(string|false $suffix): void
    {
        putenv($suffix === false ? STAND_INSTANCE_SUFFIX_VAR : STAND_INSTANCE_SUFFIX_VAR . '=' . $suffix);
    }
}
