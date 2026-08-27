<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\CliApplication;
use Hilos\Core\CLI\Commands\CommandExecutionSite;
use Hilos\Core\CLI\DaemonPresence;
use Hilos\Core\CLI\DaemonPresenceProbe;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * The gate that keeps a second writer away from state the daemon owns.
 *
 * A command declaring {@see CommandExecutionSite::CLI_OFFLINE_WRITE} is admissible only while no
 * daemon is running: migrations, seeds, a database reset and the stand fixtures are all written
 * to run BEFORE the daemon exists, and running one beside a live one means two writers to state
 * that has a single owner.
 *
 * Fail-closed is the part worth pinning hardest. Not knowing whether a daemon is up refuses the
 * command, because the two mistakes cost differently: a wrong refusal costs an operator one
 * message, and a wrong admission costs a database nobody can explain afterwards.
 */
final class OfflineWriteGateTest extends TestCase
{
    private const string ADDRESS = '127.0.0.1:8094';

    public function testANamedCommandRunsWhileNoDaemonAnswers(): void
    {
        $this->expectOutputString('');
        self::assertNull(
            CliApplication::offlineWriteVerdict(CliCommands::MIGRATION_UP, DaemonPresence::DOWN, self::ADDRESS),
        );
    }

    public function testALiveDaemonRefusesTheCommandAndSaysWhatToDo(): void
    {
        $this->expectOutputString(
            'db:migration:up writes state the daemon owns; the daemon is answering on 127.0.0.1:8094.'
            . " Stop the daemon and run it again.\n",
        );
        self::assertSame(
            ExitCode::ERROR,
            CliApplication::offlineWriteVerdict(CliCommands::MIGRATION_UP, DaemonPresence::UP, self::ADDRESS),
        );
    }

    public function testAnUncheckablePresenceRefusesAsAConfigurationProblem(): void
    {
        $this->expectOutputRegex('/cannot check whether the daemon is running/');
        self::assertSame(
            ExitCode::CONFIG_ERROR,
            CliApplication::offlineWriteVerdict(CliCommands::DB_TEST_RESET, DaemonPresence::UNKNOWN, null),
        );
    }

    public function testAnUpVerdictWithoutAnAddressFallsBackToTheUncheckableRefusal(): void
    {
        // UP cannot arrive without an address, and if it somehow did the refusal would have no
        // machine to name. Refusing as "could not check" keeps the sentence true either way.
        $this->expectOutputRegex('/cannot check whether the daemon is running/');
        self::assertSame(
            ExitCode::CONFIG_ERROR,
            CliApplication::offlineWriteVerdict(CliCommands::DB_TEST_RESET, DaemonPresence::UP, null),
        );
    }

    public function testAnEnvironmentThatNamesNoChannelIsUnknownRatherThanDown(): void
    {
        $previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $previousHost = getenv('HILOS_DAEMON_HOST');
        $previousPort = getenv('COMMAND_PORT');

        putenv('HILOS_DAEMON_HOST');
        putenv('COMMAND_PORT');
        Hilos::$env = new EnvAccessor();

        try {
            self::assertNull(DaemonPresenceProbe::address());
            self::assertSame(DaemonPresence::UNKNOWN, DaemonPresenceProbe::probe());
        } finally {
            $previousHost === false ? putenv('HILOS_DAEMON_HOST') : putenv('HILOS_DAEMON_HOST=' . $previousHost);
            $previousPort === false ? putenv('COMMAND_PORT') : putenv('COMMAND_PORT=' . $previousPort);
            if ($previousEnv !== null) {
                Hilos::$env = $previousEnv;
            }
        }
    }

    public function testAPortNothingListensOnReadsAsDown(): void
    {
        $previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $previousHost = getenv('HILOS_DAEMON_HOST');
        $previousPort = getenv('COMMAND_PORT');

        // Port 1 on loopback: privileged, unbindable by this process, and refused at once - the
        // probe has to come back DOWN rather than sit out its budget.
        putenv('HILOS_DAEMON_HOST=127.0.0.1');
        putenv('COMMAND_PORT=1');
        Hilos::$env = new EnvAccessor();

        try {
            self::assertSame('127.0.0.1:1', DaemonPresenceProbe::address());
            self::assertSame(DaemonPresence::DOWN, DaemonPresenceProbe::probe());
        } finally {
            $previousHost === false ? putenv('HILOS_DAEMON_HOST') : putenv('HILOS_DAEMON_HOST=' . $previousHost);
            $previousPort === false ? putenv('COMMAND_PORT') : putenv('COMMAND_PORT=' . $previousPort);
            if ($previousEnv !== null) {
                Hilos::$env = $previousEnv;
            }
        }
    }

    public function testAListeningSocketReadsAsUp(): void
    {
        $previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $previousHost = getenv('HILOS_DAEMON_HOST');
        $previousPort = getenv('COMMAND_PORT');

        // A bare listening socket, which is all the probe asks for: it never speaks the command
        // protocol, because an accepted connection already answers the only question it has.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server, "could not open a listening socket: {$errstr}");
        $port = (int)explode(':', (string)stream_socket_get_name($server, false))[1];

        putenv('HILOS_DAEMON_HOST=127.0.0.1');
        putenv('COMMAND_PORT=' . $port);
        Hilos::$env = new EnvAccessor();

        try {
            self::assertSame(DaemonPresence::UP, DaemonPresenceProbe::probe());
        } finally {
            fclose($server);
            $previousHost === false ? putenv('HILOS_DAEMON_HOST') : putenv('HILOS_DAEMON_HOST=' . $previousHost);
            $previousPort === false ? putenv('COMMAND_PORT') : putenv('COMMAND_PORT=' . $previousPort);
            if ($previousEnv !== null) {
                Hilos::$env = $previousEnv;
            }
        }
    }
}
