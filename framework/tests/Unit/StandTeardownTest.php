<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/stand-teardown.php';

/**
 * The parts of the stand teardown that answer without docker: the stand list being readable at
 * all, the commands each mode builds, what is read back out of docker's answer, and the line a
 * teardown is reported by.
 *
 * Actually removing a container is out of scope on purpose — proving it would mean standing a
 * demo up, which is the very thing the full run already does. What is worth pinning here is the
 * shape of the commands, because the two mistakes this ticket was written about are both
 * invisible at runtime: a `named` stand that quietly grows `--remove-orphans` would take the
 * owner's preview stand down with it, and a service list addressed by container name answers
 * `no such service` and exits zero.
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
        'services' => [],
        'containers' => [],
    ];

    /** The framework stand: four containers inside a project that also holds the preview lane. */
    private const array NAMED_STAND = [
        'id' => 'framework',
        'cwd' => '.',
        'composeFile' => 'framework/docker/docker-compose.yml',
        'project' => 'hilos-framework',
        'mode' => 'named',
        'services' => ['mysql-framework-test', 'hilos-cli-test'],
        'containers' => ['hilos-mysql-framework-test', 'hilos-cli-framework-test'],
    ];

    /** Every stand the run knows about, in the shape the teardown reads. */
    public function testEveryDeclaredStandCarriesWhatTheTeardownReads(): void
    {
        $stands = require __DIR__ . '/../../../scripts/test-stands.php';

        $this->assertNotSame([], $stands);
        foreach ($stands as $stand) {
            foreach (['id', 'cwd', 'composeFile', 'project', 'mode', 'services', 'containers'] as $key) {
                $this->assertArrayHasKey($key, $stand, 'stand ' . ($stand['id'] ?? '?') . ' is missing ' . $key);
            }
            $this->assertContains($stand['mode'], ['project', 'named'], 'stand ' . $stand['id'] . ' has an unknown mode');
        }
    }

    /** A `named` stand names the services it drops, and nothing outside that list. */
    public function testANamedStandDeclaresTheServicesAndContainersItOwns(): void
    {
        $stands = require __DIR__ . '/../../../scripts/test-stands.php';

        foreach ($stands as $stand) {
            if ($stand['mode'] !== 'named') {
                $this->assertSame([], $stand['services'], 'stand ' . $stand['id'] . ' lists services it never uses');

                continue;
            }
            $this->assertNotSame([], $stand['services'], 'named stand ' . $stand['id'] . ' drops nothing');
            $this->assertCount(
                count($stand['services']),
                $stand['containers'],
                'named stand ' . $stand['id'] . ' cannot look up what it drops',
            );
        }
    }

    /** A demo stand goes down as a whole project, orphans included. */
    public function testDropsADemoStandAsAWholeProject(): void
    {
        $command = standDownCommand(self::DEMO_STAND);

        $this->assertStringContainsString('down --remove-orphans', $command);
        $this->assertStringContainsString("-f 'docker/docker-compose.test.yml'", $command);
    }

    /**
     * The framework stand goes down by service name. `--remove-orphans` here would take the
     * owner's preview containers with it, since they share this compose project.
     */
    public function testDropsTheFrameworkStandByNameWithoutTouchingItsProject(): void
    {
        $command = standDownCommand(self::NAMED_STAND);

        $this->assertStringContainsString("rm -sf 'mysql-framework-test' 'hilos-cli-test'", $command);
        $this->assertStringNotContainsString('--remove-orphans', $command);
        $this->assertStringNotContainsString(' down', $command);
    }

    /** The one-off containers are found by project label, which is the only thing they share. */
    public function testLooksUpADemoStandsContainersByProjectLabel(): void
    {
        $command = standResidueContainersCommand(self::DEMO_STAND);

        $this->assertStringContainsString("--filter 'label=com.docker.compose.project=hilos-chat-test'", $command);
    }

    /** The framework stand is looked up by its own container names, anchored so no prefix matches. */
    public function testLooksUpTheFrameworkStandByItsOwnContainerNames(): void
    {
        $command = standResidueContainersCommand(self::NAMED_STAND);

        $this->assertStringContainsString("--filter 'name=^hilos-mysql-framework-test$'", $command);
        $this->assertStringContainsString("--filter 'name=^hilos-cli-framework-test$'", $command);
        $this->assertStringNotContainsString('label=com.docker.compose.project', $command);
    }

    /** A demo stand owns its networks and is asked about them. */
    public function testAsksADemoStandAboutItsNetworks(): void
    {
        $command = standResidueNetworksCommand(self::DEMO_STAND);

        $this->assertNotNull($command);
        $this->assertStringContainsString("--filter 'label=com.docker.compose.project=hilos-chat-test'", $command);
    }

    /** The framework stand owns no networks: the project's are the preview lane's too. */
    public function testAsksANamedStandAboutNoNetworksAtAll(): void
    {
        $this->assertNull(standResidueNetworksCommand(self::NAMED_STAND));
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

    /** A stand that had nothing to remove says so, so that the log covers every stand. */
    public function testDescribesAStandThatWasAlreadyClean(): void
    {
        $this->assertSame(
            'stands: chat — чисто',
            describeTeardown(self::cleanResult('chat')),
        );
    }

    /** What was removed is counted, because the count is what says the stand was not empty. */
    public function testDescribesWhatWasRemoved(): void
    {
        $result = [
            'id' => 'chat',
            'removedContainers' => ['hilos-chat-test-mysql-1', 'hilos-chat-test-app-run-abc123'],
            'removedNetworks' => ['hilos-chat-test_default'],
            'residue' => ['containers' => [], 'networks' => []],
        ];

        $this->assertSame('stands: chat — снесено: контейнеров 2, сетей 1', describeTeardown($result));
    }

    /** What survived is named rather than counted: the run is about to stop, and by what. */
    public function testDescribesWhatSurvivedTheTeardown(): void
    {
        $result = [
            'id' => 'chat',
            'removedContainers' => ['hilos-chat-test-mysql-1'],
            'removedNetworks' => [],
            'residue' => ['containers' => ['hilos-chat-test-app-run-abc123'], 'networks' => ['hilos-chat-test_default']],
        ];

        $this->assertSame(
            'stands: chat — ОСТАЛОСЬ: hilos-chat-test-app-run-abc123, hilos-chat-test_default',
            describeTeardown($result),
        );
    }

    /**
     * A teardown result that removed nothing and left nothing.
     *
     * @return array{id: string, removedContainers: array<int, string>, removedNetworks: array<int, string>,
     *     residue: array{containers: array<int, string>, networks: array<int, string>}}
     */
    private static function cleanResult(string $id): array
    {
        return [
            'id' => $id,
            'removedContainers' => [],
            'removedNetworks' => [],
            'residue' => ['containers' => [], 'networks' => []],
        ];
    }
}
