<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Environment;

use PHPUnit\Framework\TestCase;

/**
 * Holds every demo's compose stack to the environment norm the operator commands stand on.
 *
 * The commands `admin:create`, `admin:grant`, `admin:revoke` and `protected-mode:open` do not
 * run inside the daemon: they open the command channel and ask the daemon over a socket. Which
 * socket is decided by three values in the container's environment - the daemon's address and
 * the channel's host and port - and a stack that leaves any of them unsaid answers an operator
 * with a configuration error instead of doing the work. That failure is invisible until someone
 * installs the demo, which is why it is checked here rather than left to a person's memory.
 *
 * Read line by line on purpose: the repository has no YAML parser (neither ext-yaml nor
 * symfony/yaml is a dependency), and a guard over configuration is not worth a dependency that
 * every install would then carry. What the line reader must understand is the cluster stack's
 * merge keys - its five nodes declare almost nothing of their own and inherit the rest from two
 * anchors - so a service is judged by the union of its own lines and those of every anchor it
 * merges. Without that union the same five nodes would fail five times over values they do have.
 *
 * A demo arrives through the glob and a demo without a `docker/` directory is skipped in
 * silence, the way the code-style guard treats a root that is not there: the framework also
 * ships without the demos.
 */
final class DemoStackComposeGuardTest extends TestCase
{
    /** The command line that makes a service the daemon rather than one of its clients. */
    private const string DAEMON_COMMAND = 'backend/Bootstrap/docker.php';

    /** Name of the value holding the address the command channel is dialed at. */
    private const string DAEMON_HOST = 'HILOS_DAEMON_HOST';

    /** Name of the value holding the address the command channel binds to. */
    private const string COMMAND_HOST = 'COMMAND_HOST';

    /** Name of the value holding the port the command channel lives on. */
    private const string COMMAND_PORT = 'COMMAND_PORT';

    /** The address a service dials to reach the daemon inside its own container. */
    private const string LOOPBACK = '127.0.0.1';

    /**
     * A daemon service names its own address and its command channel in full.
     *
     * All three, not the port alone: the channel is dialed by address AND port, and a daemon
     * that binds the channel without saying where it is reachable is a daemon whose operator
     * commands work from a neighbouring container and fail inside its own.
     *
     * @return void
     */
    public function testDaemonServiceDeclaresItsAddressAndCommandChannel(): void
    {
        $daemons = 0;

        foreach ($this->composeFiles() as $relativePath => $path) {
            foreach ($this->servicesOf($path) as $service => $lines) {
                if (!$this->isDaemon($lines)) {
                    continue;
                }

                $daemons++;
                foreach ([self::DAEMON_HOST, self::COMMAND_HOST, self::COMMAND_PORT] as $name) {
                    $this->assertNotNull(
                        $this->valueOf($lines, $name),
                        "{$relativePath}: the daemon service {$service} does not declare {$name}"
                    );
                }
            }
        }

        $this->assertGreaterThan(0, $daemons, 'no daemon service was found at all - the reader stopped seeing them');
    }

    /**
     * A service dialing a daemon OTHER than itself names the port too.
     *
     * The loopback case is exempt because it is the daemon's own container, where the channel's
     * port is already declared beside the bind address; a cli, monitor or test runner reaching
     * across the network has no such neighbour and would fall back to a default that lives in
     * its own source, which is the value living in two places this guard exists to prevent.
     *
     * @return void
     */
    public function testServiceDialingAnotherDaemonDeclaresTheChannelPort(): void
    {
        foreach ($this->composeFiles() as $relativePath => $path) {
            foreach ($this->servicesOf($path) as $service => $lines) {
                $host = $this->valueOf($lines, self::DAEMON_HOST);
                if ($host === null || $this->dialsLoopback($host)) {
                    continue;
                }

                $this->assertNotNull(
                    $this->valueOf($lines, self::COMMAND_PORT),
                    "{$relativePath}: {$service} dials {$host} and does not declare " . self::COMMAND_PORT
                );
            }
        }
    }

    /**
     * Every compose file of every demo, keyed by its path from the repository root.
     *
     * @return array<string, string> Repository-relative path => absolute path
     */
    private function composeFiles(): array
    {
        $root = $this->repositoryRoot();
        $files = [];

        foreach (glob($root . '/demo/*', GLOB_ONLYDIR) ?: [] as $demo) {
            foreach (glob($demo . '/docker/docker-compose*.yml') ?: [] as $path) {
                $files[substr($path, strlen($root) + 1)] = $path;
            }
        }

        return $files;
    }

    /**
     * Services of one compose file, each already carrying the lines of the anchors it merges.
     *
     * @param string $path Absolute path of the compose file
     * @return array<string, array<int, string>> Service name => its effective lines
     */
    private function servicesOf(string $path): array
    {
        // external-boundary: a file the glob just listed, read as the empty document when unreadable
        $lines = explode("\n", (string)file_get_contents($path));
        $anchors = $this->anchorsOf($lines);

        $services = [];
        $current = null;
        $inServices = false;
        foreach ($lines as $line) {
            if (trim($line) === 'services:') {
                $inServices = true;
                continue;
            }

            if ($line !== '' && !str_starts_with($line, ' ')) {
                $inServices = false;
                $current = null;
                continue;
            }

            if (!$inServices) {
                continue;
            }

            if (preg_match('/^ {2}([A-Za-z0-9_.-]+):\s*$/', $line, $matches) === 1) {
                $current = $matches[1];
                $services[$current] = [];
                continue;
            }

            if ($current !== null) {
                $services[$current][] = $line;
            }
        }

        foreach ($services as $service => $body) {
            foreach ($body as $line) {
                if (preg_match('/^\s*<<:\s*\*([A-Za-z0-9_-]+)\s*$/', $line, $matches) === 1) {
                    $services[$service] = array_merge($services[$service], $anchors[$matches[1]] ?? []);
                }
            }
        }

        return $services;
    }

    /**
     * Top-level anchor blocks of one compose file, keyed by anchor name.
     *
     * @param array<int, string> $lines Lines of the compose file
     * @return array<string, array<int, string>> Anchor name => its lines
     */
    private function anchorsOf(array $lines): array
    {
        $anchors = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^[A-Za-z0-9_.-]+:\s*&([A-Za-z0-9_-]+)\s*$/', $line, $matches) === 1) {
                $current = $matches[1];
                $anchors[$current] = [];
                continue;
            }

            if ($line !== '' && !str_starts_with($line, ' ')) {
                $current = null;
                continue;
            }

            if ($current !== null) {
                $anchors[$current][] = $line;
            }
        }

        return $anchors;
    }

    /**
     * Whether a service runs the daemon bootstrap rather than a client of it.
     *
     * @param array<int, string> $lines Effective lines of the service
     * @return bool True when the service's command starts the daemon
     */
    private function isDaemon(array $lines): bool
    {
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, 'command:') && str_contains($trimmed, self::DAEMON_COMMAND)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Value a service declares for an environment name, in either compose spelling.
     *
     * A commented-out line declares nothing: the demos keep values in comments precisely to
     * show what a stack does NOT set, and reading those back would make the guard blind.
     *
     * @param array<int, string> $lines Effective lines of the service
     * @param string $name Environment value name
     * @return string|null The declared value, or null when the service does not declare it
     */
    private function valueOf(array $lines, string $name): ?string
    {
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^-\s*' . $name . '=(.*)$/', $trimmed, $matches) === 1) {
                return trim($matches[1], " \"'");
            }

            if (preg_match('/^' . $name . ':\s*(.*)$/', $trimmed, $matches) === 1) {
                return trim($matches[1], " \"'");
            }
        }

        return null;
    }

    /**
     * Whether a declared daemon address means "this container".
     *
     * @param string $host The declared address, possibly an interpolation with a default
     * @return bool True when the address is loopback
     */
    private function dialsLoopback(string $host): bool
    {
        return $host === self::LOOPBACK;
    }

    /**
     * @return string Absolute path of the repository root
     */
    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}
