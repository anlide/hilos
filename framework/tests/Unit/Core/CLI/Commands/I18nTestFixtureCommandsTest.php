<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\CliManager;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\Core\CLI\Commands\AbstractCommandChannelTestCommand;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Commands\CommandExecution;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Core\CLI\Commands\I18nTestLanguageCreateCommand;
use Hilos\Core\CLI\Commands\I18nTestCountryCreateCommand;
use Hilos\Core\CLI\Commands\I18nTestLocaleCreateCommand;
use Hilos\Core\CLI\Commands\I18nTestLanguageClearCommand;
use Hilos\Core\CLI\Commands\I18nTestCountryClearCommand;
use Hilos\Core\CLI\Commands\I18nTestLocaleClearCommand;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Fixture CLI contracts, including refusal before the command channel is called. */
final class I18nTestFixtureCommandsTest extends TestCase
{
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

    /** @return iterable<string, array{string, array, array, array, array}> Literal wire contracts */
    public static function creations(): iterable
    {
        yield 'language defaults' => ['language', [], ['qx', 'Fixture'], ['rtl', 'enabled'],
            ['code' => 'qx', 'nativeName' => 'Fixture', 'rtl' => false, 'enabled' => false]];
        yield 'language flags' => ['language', ['rtl' => '1', 'enabled' => '1'], ['qx', 'Fixture'], ['rtl', 'enabled'],
            ['code' => 'qx', 'nativeName' => 'Fixture', 'rtl' => true, 'enabled' => true]];
        yield 'country defaults' => ['country', [], ['qz', 'Z', 'QZZ'], ['enabled'],
            ['code' => 'qz', 'currencySymbol' => 'Z', 'currencyCode' => 'QZZ', 'enabled' => false]];
        yield 'country enabled' => ['country', ['enabled' => '1'], ['qz', 'Z', 'QZZ'], ['enabled'],
            ['code' => 'qz', 'currencySymbol' => 'Z', 'currencyCode' => 'QZZ', 'enabled' => true]];
        yield 'countryless locale' => ['locale', [], ['42'], ['country-id', 'enabled'],
            ['languageId' => 42, 'countryId' => null, 'enabled' => false]];
        yield 'linked enabled locale' => ['locale', ['country-id' => '73', 'enabled' => '1'], ['42'], ['country-id', 'enabled'],
            ['languageId' => 42, 'countryId' => 73, 'enabled' => true]];
        yield 'explicit zero flags' => ['language', ['rtl' => '0', 'enabled' => '0'], ['qx', 'Fixture'], ['rtl', 'enabled'],
            ['code' => 'qx', 'nativeName' => 'Fixture', 'rtl' => false, 'enabled' => false]];
    }

    /**
     * @param string $kind Entity command
     * @param array<string, mixed> $options Terminal options
     * @param list<string> $args Terminal arguments
     * @param list<string> $declaredOptions Exact OPTIONS contract
     * @param array<string, mixed> $payload Exact channel payload
     */
    #[DataProvider('creations')]
    public function testLiteralWirePayloadAndExecutionBoundary(
        string $kind, array $options, array $args, array $declaredOptions, array $payload,
    ): void {
        $command = self::spy($kind);
        self::assertSame('test:i18n:' . $kind . ':create', $command->getName());
        self::assertTrue(TestOnlyCommandRegistry::isTestOnly($command->getName()));
        self::assertContains($command->getName(), I18nLibraryAgent::AGENT_COMMANDS);
        self::assertInstanceOf(DatabaseFreeCommand::class, $command);
        self::assertEquals(CommandExecution::daemon(), $command->execution());
        self::assertSame($declaredOptions, $command::OPTIONS);
        self::assertStringContainsString($command->getName(), $command->getHelp());
        $this->expectOutputString("{\"id\":17,\"code\":\"qx\",\"enabled\":true}\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute($options, $args));
        self::assertSame($command->getName(), $command->sentCommand);
        self::assertSame($payload, $command->sentPayload);
    }

    /** @return iterable<string, array{string, string}> Exact clear addresses */
    public static function clears(): iterable
    {
        yield 'language' => ['language', 'qx'];
        yield 'country' => ['country', 'qx'];
        yield 'countryless locale' => ['locale', 'fr'];
        yield 'linked locale' => ['locale', 'qx-GB'];
    }

    /**
     * @param string $kind Entity command
     * @param string $code Exact external address
     */
    #[DataProvider('clears')]
    public function testClearLiteralWirePayloadReplyAndExecutionBoundary(string $kind, string $code): void
    {
        $command = self::spy($kind . ':clear');
        $command->result = CommandChannelResult::replied(CommandReplyDTO::ok('cid', ['code' => $code]), '127.0.0.1:1');
        self::assertSame('test:i18n:' . $kind . ':clear', $command->getName());
        self::assertContains($command->getName(), I18nLibraryAgent::AGENT_COMMANDS);
        self::assertTrue(TestOnlyCommandRegistry::isTestOnly($command->getName()));
        self::assertInstanceOf(DatabaseFreeCommand::class, $command);
        self::assertEquals(CommandExecution::daemon(), $command->execution());
        self::assertSame([], $command::OPTIONS);
        self::assertStringContainsString($command->getName(), $command->getHelp());
        $this->expectOutputString(json_encode(['code' => $code], JSON_THROW_ON_ERROR) . "\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], [$code]));
        self::assertSame($command->getName(), $command->sentCommand);
        self::assertSame(['code' => $code], $command->sentPayload);
    }

    /** @return iterable<string, array{string, array, array}> Inputs that must never open the channel */
    public static function malformed(): iterable
    {
        foreach (['language', 'country', 'locale'] as $kind) {
            foreach ([[], ['qx', 'extra'], [42], [true], [null], [[]], [''], ['QX'], ['qxx'], ["qx\n"], ['qx-gb']] as $index => $args) {
                yield $kind . ' clear malformed ' . $index => [$kind . ':clear', [], $args];
            }
            if ($kind !== 'locale') {
                yield $kind . ' clear locale code' => [$kind . ':clear', [], ['qx-GB']];
            }
        }
        foreach (['language' => ['qx', 'Fixture'], 'country' => ['qz', 'Z', 'QZZ'], 'locale' => ['42']] as $kind => $args) {
            yield $kind . ' missing args' => [$kind, [], []];
            yield $kind . ' extra arg' => [$kind, [], [...$args, 'extra']];
            yield $kind . ' non-string arg' => [$kind, [], [42, ...array_slice($args, 1)]];
            foreach ([true, false, null, 1, 0, 'true', 'false', '', '2', '01', ' 1', '1.0', []] as $index => $flag) {
                yield $kind . ' enabled ' . $index => [$kind, ['enabled' => $flag], $args];
                if ($kind === 'language') {
                    yield 'rtl ' . $index => [$kind, ['rtl' => $flag], $args];
                }
            }
        }
        foreach (['', 'EN', 'eng', "en\n", 'e1'] as $code) {
            yield 'language code ' . bin2hex($code) => ['language', [], [$code, 'Fixture']];
            yield 'country code ' . bin2hex($code) => ['country', [], [$code, 'Z', 'QZZ']];
        }
        foreach (['', '   ', str_repeat('名', 65), false, []] as $index => $name) {
            yield 'native name ' . $index => ['language', [], ['qx', $name]];
        }
        foreach (['', ' ', str_repeat('Z', 9), 1] as $index => $symbol) {
            yield 'symbol ' . $index => ['country', [], ['qz', $symbol, 'QZZ']];
        }
        foreach (['zz', 'usd', "USD\n", '', 123] as $index => $currency) {
            yield 'currency ' . $index => ['country', [], ['qz', 'Z', $currency]];
        }
        foreach (['0', '-1', '1.0', '1e2', '01', ' 42', "42\n", 'abc', str_repeat('9', 30), true, null, []] as $index => $id) {
            yield 'language ID ' . $index => ['locale', [], [$id]];
            yield 'country ID ' . $index => ['locale', ['country-id' => $id], ['42']];
        }
    }

    /**
     * @param string $kind Entity command
     * @param array<string, mixed> $options Invalid options
     * @param array $args Invalid positional arguments
     */
    #[DataProvider('malformed')]
    public function testMalformedInputNeverReachesTheChannel(string $kind, array $options, array $args): void
    {
        $command = self::spy($kind);
        ob_start();
        try {
            self::assertSame(ExitCode::INVALID_ARGUMENT, $command->execute($options, $args));
        } finally {
            $output = ob_get_clean();
        }
        self::assertStringContainsString('Usage:', $output);
        self::assertNull($command->sentCommand);
    }

    public function testTheRegistryRefusesUndeclaredOptionsBeforeTheChannel(): void
    {
        foreach (['language', 'country', 'locale', 'language:clear', 'country:clear', 'locale:clear'] as $kind) {
            $command = self::spy($kind);
            $manager = new I18nFixtureCliManager(['cli.php', $command->getName(), '--unknown=secret'], $command);
            self::assertSame(ExitCode::INVALID_ARGUMENT, $manager->answerWithoutRunning());
            self::assertSame(
                'Unknown option --unknown for ' . $command->getName() . '; see php cli.php help ' . $command->getName(),
                $manager->standardError,
            );
            self::assertNull($command->sentCommand);
        }
    }

    public function testEveryCommandRefusesProductionAndUnsetEnvironmentBeforeSending(): void
    {
        foreach (['production', 'staging', null] as $environment) {
            $environment === null ? putenv('APP_ENV') : putenv('APP_ENV=' . $environment);
            Hilos::$env = new EnvAccessor();
            foreach (['language', 'country', 'locale', 'language:clear', 'country:clear', 'locale:clear'] as $kind) {
                $command = self::spy($kind);
                try {
                    $command->execute([], []);
                    self::fail('Production-like or missing environment must refuse');
                } catch (TestOnlyCommandOnProductionException) {
                    self::assertNull($command->sentCommand);
                }
            }
        }
    }

    public function testRefusalsChannelFailuresAndMalformedSuccessAreErrors(): void
    {
        foreach (['language' => ['qx', 'Fixture'], 'country' => ['qz', 'Z', 'QZZ'], 'locale' => ['42']] as $kind => $args) {
            foreach ([
                CommandChannelResult::replied(CommandReplyDTO::error('cid', 'Occupied address'), '127.0.0.1:1'),
                CommandChannelResult::unreachable('127.0.0.1:1'),
                CommandChannelResult::timedOut('127.0.0.1:1'),
            ] as $result) {
                $command = self::spy($kind);
                $command->result = $result;
                self::assertSame(ExitCode::ERROR, $command->execute([], $args));
                self::assertNotSame('', $command->standardError);
            }
            foreach ([[], ['id' => '17', 'code' => 'qx', 'enabled' => true],
                ['id' => 0, 'code' => 'qx', 'enabled' => true], ['id' => 17, 'code' => 1, 'enabled' => true],
                ['id' => 17, 'code' => 'qx', 'enabled' => 1]] as $payload) {
                $command = self::spy($kind);
                $command->result = CommandChannelResult::replied(CommandReplyDTO::ok('cid', $payload), '127.0.0.1:1');
                ob_start();
                try {
                    self::assertSame(ExitCode::ERROR, $command->execute([], $args));
                } finally {
                    ob_end_clean();
                }
            }
        }
    }

    public function testClearRefusalsAndBadRepliesNeverReportSuccess(): void
    {
        foreach (['language', 'country', 'locale'] as $kind) {
            foreach ([
                CommandChannelResult::replied(CommandReplyDTO::error('cid', 'Protected fixture'), '127.0.0.1:1'),
                CommandChannelResult::unreachable('127.0.0.1:1'),
                CommandChannelResult::timedOut('127.0.0.1:1'),
            ] as $result) {
                $command = self::spy($kind . ':clear');
                $command->result = $result;
                self::assertSame(ExitCode::ERROR, $command->execute([], ['qx']));
                self::assertNotSame('', $command->standardError);
            }
            foreach ([[], ['code' => 17], ['code' => true], ['code' => null], ['code' => 'qy']] as $payload) {
                $command = self::spy($kind . ':clear');
                $command->result = CommandChannelResult::replied(CommandReplyDTO::ok('cid', $payload), '127.0.0.1:1');
                ob_start();
                try {
                    self::assertSame(ExitCode::ERROR, $command->execute([], ['qx']));
                } finally {
                    ob_end_clean();
                }
            }
        }
    }

    /**
     * @param string $kind Entity command
     * @return AbstractCommandChannelTestCommand Command with a captured channel
     */
    private static function spy(string $kind): AbstractCommandChannelTestCommand
    {
        return match ($kind) {
            'language' => new I18nLanguageCreateSpy(),
            'country' => new I18nCountryCreateSpy(),
            'locale' => new I18nLocaleCreateSpy(),
            'language:clear' => new I18nLanguageClearSpy(),
            'country:clear' => new I18nCountryClearSpy(),
            'locale:clear' => new I18nLocaleClearSpy(),
        };
    }
}

/** Captures the single channel boundary shared by the fixture CLI spies. */
trait I18nCreateChannelSpy
{
    public ?string $sentCommand = null;
    public ?array $sentPayload = null;
    public ?CommandChannelResult $result = null;
    public string $standardError = '';

    /**
     * @param string $command Wire name
     * @param array<string, mixed> $payload Parsed payload
     * @param ?float $waitSeconds Unused wait budget
     * @return CommandChannelResult Canned command reply
     */
    protected function sendCommand(string $command, array $payload, ?float $waitSeconds = null): CommandChannelResult
    {
        $this->sentCommand = $command;
        $this->sentPayload = $payload;

        return $this->result ?? CommandChannelResult::replied(
            CommandReplyDTO::ok('cid', ['id' => 17, 'code' => 'qx', 'enabled' => true]),
            '127.0.0.1:1',
        );
    }

    /** @param string $text Refusal or channel failure */
    protected function writeToStandardError(string $text): void
    {
        $this->standardError .= $text;
    }
}

final class I18nLanguageCreateSpy extends I18nTestLanguageCreateCommand
{
    use I18nCreateChannelSpy;
}

final class I18nCountryCreateSpy extends I18nTestCountryCreateCommand
{
    use I18nCreateChannelSpy;
}

final class I18nLocaleCreateSpy extends I18nTestLocaleCreateCommand
{
    use I18nCreateChannelSpy;
}

final class I18nLanguageClearSpy extends I18nTestLanguageClearCommand
{
    use I18nCreateChannelSpy;
}

final class I18nCountryClearSpy extends I18nTestCountryClearCommand
{
    use I18nCreateChannelSpy;
}

final class I18nLocaleClearSpy extends I18nTestLocaleClearCommand
{
    use I18nCreateChannelSpy;
}

/** Uses the real option gate while capturing its stderr and channel. */
final class I18nFixtureCliManager extends CliManager
{
    public string $standardError = '';

    /**
     * @param list<string> $argv Terminal tokens
     * @param AbstractCommandChannelTestCommand $spy Channel spy registered under the real wire name
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
        $this->standardError = $text;
    }
}
