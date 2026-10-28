<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Commands\AbstractCommandChannelTestCommand;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Commands\CommandExecution;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Core\CLI\Commands\SessionTestBindCommand;
use Hilos\Core\CLI\Commands\UserTestCreateCommand;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Terminal contracts of the two daemon-owned fixture commands. */
final class UserTestFixtureCommandsTest extends TestCase
{
    public const string PASSWORD = 'correct horse battery';
    public const string FIXTURE_PASSWORD_HASH = '$2y$10$abcdefghijklmnopqrstuuCj7fyLDJytXL.TfOiOeiv0fQvSOK1/2';
    public const string TOKEN = '18250000000000000000000000000001';

    private ?EnvAccessor $previousEnv = null;
    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->previousAppEnv = getenv('APP_ENV');
        $this->previousEnv = Hilos::$env;
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);
    }

    /** @return iterable<string, array{bool, array, array, array, array}> Literal request contracts */
    public static function requests(): iterable
    {
        yield 'password defaults' => [true, [], [' Fixture@Example.test ', self::FIXTURE_PASSWORD_HASH], ['phone', 'theme'],
            ['email' => 'fixture@example.test', 'passwordHash' => self::FIXTURE_PASSWORD_HASH, 'themePick' => null, 'phone' => null]];
        yield 'magic link and SMS' => [true, ['phone' => '+48123456789', 'theme' => 'dark'], ['fixture@example.test', 'none'],
            ['phone', 'theme'], ['email' => 'fixture@example.test', 'passwordHash' => null, 'themePick' => 'dark', 'phone' => '+48123456789']];
        foreach (['light', 'system'] as $theme) {
            yield $theme => [true, ['theme' => $theme], ['fixture@example.test', 'none'], ['phone', 'theme'],
                ['email' => 'fixture@example.test', 'passwordHash' => null, 'themePick' => $theme, 'phone' => null]];
        }
        yield 'session bind' => [false, [], [self::TOKEN, '42'], [], ['sessionToken' => self::TOKEN, 'userId' => 42]];
    }

    /**
     * @param bool $create Whether this is account creation
     * @param array $options Terminal options
     * @param array $args Terminal arguments
     * @param array $declared Exact option declaration
     * @param array $payload Exact wire payload
     */
    #[DataProvider('requests')]
    public function testRequestAndDatabaseFreeDaemonBoundary(bool $create, array $options, array $args, array $declared, array $payload): void
    {
        $command = self::spy($create);
        self::assertSame($create ? 'test:user:create' : 'test:session:bind', $command->getName());
        self::assertInstanceOf(DatabaseFreeCommand::class, $command);
        self::assertEquals(CommandExecution::daemon(), $command->execution());
        self::assertSame($declared, $command::OPTIONS);
        self::assertStringContainsString($command->getName(), $command->getHelp());
        ob_start();
        try {
            self::assertSame(ExitCode::SUCCESS, $command->execute($options, $args));
        } finally {
            $output = ob_get_clean();
        }
        self::assertSame(json_encode(self::success($create), JSON_THROW_ON_ERROR) . "\n", $output);
        self::assertStringNotContainsString(self::FIXTURE_PASSWORD_HASH, $output);
        self::assertStringNotContainsString(self::TOKEN, $output);
        self::assertSame([[$command->getName(), $payload]], $command->sent);
    }

    /** @return iterable<string, array{bool, array, array}> Malformed terminal inputs */
    public static function malformed(): iterable
    {
        foreach ([[], ['fixture@example.test'], ['fixture@example.test', 'none', 'extra'],
            [false, 'none'], ['bad-email', 'none'], ['fixture@example.test', null],
            ['fixture@example.test', 'plaintext'], ['fixture@example.test', '$2y$broken']] as $i => $args) {
            yield 'create args ' . $i => [true, [], $args];
        }
        foreach ([null, false, 42, '', 'blue', 'Dark'] as $i => $theme) {
            yield 'theme ' . $i => [true, ['theme' => $theme], ['fixture@example.test', 'none']];
        }
        foreach ([null, false, 42, '', '+01234567890', '48123456789', '+48 123456789', '+123', '+1234567890123456'] as $i => $phone) {
            yield 'phone ' . $i => [true, ['phone' => $phone], ['fixture@example.test', 'none']];
        }
        foreach ([0, 42, true, false, null, [], '0', '-1', '01', '1.0', '1e2', ' 42', "42\n", str_repeat('9', 30)] as $i => $id) {
            yield 'user id ' . $i => [false, [], [self::TOKEN, $id]];
        }
        foreach ([null, false, 42, [], '', 'bad', strtoupper(self::TOKEN . 'a'), self::TOKEN . '1'] as $i => $token) {
            yield 'token ' . $i => [false, [], [$token, '42']];
        }
        yield 'missing bind' => [false, [], []];
        yield 'extra bind' => [false, [], [self::TOKEN, '42', 'extra']];
    }

    /**
     * @param bool $create Command kind
     * @param array $options Invalid options
     * @param array $args Invalid arguments
     */
    #[DataProvider('malformed')]
    public function testBadArgumentsNeverSend(bool $create, array $options, array $args): void
    {
        $command = self::spy($create);
        ob_start();
        try {
            self::assertSame(ExitCode::INVALID_ARGUMENT, $command->execute($options, $args));
        } finally {
            $output = ob_get_clean();
        }
        self::assertStringContainsString('Usage:', $output);
        self::assertSame([], $command->sent);
    }

    public function testRegistryRefusesUndeclaredOptions(): void
    {
        foreach ([true, false] as $create) {
            $spy = self::spy($create);
            $manager = new UserFixtureCliManager(['cli.php', $spy->getName(), '--unknown=secret'], $spy);
            self::assertSame(ExitCode::INVALID_ARGUMENT, $manager->answerWithoutRunning());
            self::assertSame('Unknown option --unknown for ' . $spy->getName() . '; see php cli.php help ' . $spy->getName(), $manager->error);
            self::assertSame([], $spy->sent);
        }
    }

    public function testProductionAndMissingEnvironmentRefuseBeforeSend(): void
    {
        foreach (['production', 'staging', null] as $environment) {
            $environment === null ? putenv('APP_ENV') : putenv('APP_ENV=' . $environment);
            Hilos::$env = new EnvAccessor();
            foreach ([true, false] as $create) {
                $command = self::spy($create);
                try {
                    $command->execute([], self::args($create));
                    self::fail('Expected fail-closed test-only gate');
                } catch (TestOnlyCommandOnProductionException) {
                    self::assertSame([], $command->sent);
                }
            }
        }
    }

    public function testOneSendAndCommonErrorPrintersForRefusalOrNoAnswer(): void
    {
        foreach ([true, false] as $create) {
            foreach ([
                CommandChannelResult::replied(CommandReplyDTO::error('cid', 'Fixture refused'), '127.0.0.1:1'),
                CommandChannelResult::unreachable('127.0.0.1:1'),
                CommandChannelResult::timedOut('127.0.0.1:1'),
            ] as $result) {
                $command = self::spy($create);
                $command->result = $result;
                $this->expectOutputString('');
                self::assertSame(ExitCode::ERROR, $command->execute([], self::args($create)));
                self::assertCount(1, $command->sent);
                self::assertNotSame('', $command->error);
            }
        }
    }

    public function testMalformedReplyIdsAreNeverCoercedOrPrintedAsSuccess(): void
    {
        foreach ([true, false] as $create) {
            foreach ([null, '42', true, 42.0, 0, -1] as $id) {
                $payload = self::success($create);
                $payload[$create ? 'userId' : 'sessionId'] = $id;
                $command = self::spy($create);
                $command->result = CommandChannelResult::replied(CommandReplyDTO::ok('cid', $payload), '127.0.0.1:1');
                ob_start();
                try {
                    self::assertSame(ExitCode::ERROR, $command->execute([], self::args($create)));
                } finally {
                    ob_end_clean();
                }
                self::assertCount(1, $command->sent);
            }
        }
    }

    /**
     * @param bool $create Command kind
     * @return array<string, mixed> Literal success contract
     */
    private static function success(bool $create): array
    {
        return $create ? ['userId' => 42, 'email' => 'fixture@example.test', 'name' => 'fixture'] : ['sessionId' => 17, 'userId' => 42];
    }

    /**
     * @param bool $create Command kind
     * @return list<string> Valid arguments
     */
    private static function args(bool $create): array
    {
        return $create ? ['fixture@example.test', 'none'] : [self::TOKEN, '42'];
    }

    /**
     * @param bool $create Command kind
     * @return UserCreateChannelSpy|SessionBindChannelSpy Captured channel
     */
    private static function spy(bool $create): UserCreateChannelSpy|SessionBindChannelSpy
    {
        $spy = $create ? new UserCreateChannelSpy() : new SessionBindChannelSpy();
        $spy->result = CommandChannelResult::replied(CommandReplyDTO::ok('cid', self::success($create)), '127.0.0.1:1');

        return $spy;
    }
}

/** Captures the actual command's shared channel and stderr seams. */
trait UserFixtureChannelSpy
{
    public array $sent = [];
    public CommandChannelResult $result;
    public string $error = '';

    /**
     * @param string $command Wire name
     * @param array<string, mixed> $payload Wire payload
     * @param ?float $waitSeconds Unused wait budget
     * @return CommandChannelResult Prepared reply
     */
    protected function sendCommand(string $command, array $payload, ?float $waitSeconds = null): CommandChannelResult
    {
        $this->sent[] = [$command, $payload];

        return $this->result;
    }

    /** @param string $text Common error text */
    protected function writeToStandardError(string $text): void
    {
        $this->error .= $text;
    }
}

final class UserCreateChannelSpy extends UserTestCreateCommand
{
    use UserFixtureChannelSpy;
}

final class SessionBindChannelSpy extends SessionTestBindCommand
{
    use UserFixtureChannelSpy;
}

final class UserFixtureCliManager extends CliManager
{
    public string $error = '';

    /**
     * @param list<string> $argv Terminal tokens
     * @param AbstractCommandChannelTestCommand $spy Command with captured channel
     */
    public function __construct(array $argv, public readonly AbstractCommandChannelTestCommand $spy)
    {
        parent::__construct($argv);
    }

    protected function registerProjectCommands(): void
    {
        $this->addCommand($this->spy);
    }

    /** @param string $text Option refusal */
    protected function writeToStandardError(string $text): void
    {
        $this->error = $text;
    }
}
