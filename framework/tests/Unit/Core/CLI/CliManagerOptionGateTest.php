<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI;

use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Commands\CommandExecution;
use Hilos\Core\CLI\Commands\CommandInterface;
use Hilos\Core\CLI\Commands\HelpCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the gate that answers help and refuses an option a command did not declare,
 * before {@see CliManager::run()} and before the daemon or the database.
 *
 * The spy is registered from {@see CliManager::registerProjectCommands()}, which runs
 * before help is built, so `help <spy>` can see it. Stderr is kept on the manager the
 * way {@see CliManagerTestOnlyRefusalTest} keeps a refusal.
 */
final class CliManagerOptionGateTest extends TestCase
{
    /**
     * @return array<string, array{0: string}> Help token placed after the spy's name
     */
    public static function helpTokens(): array
    {
        return [
            'long flag' => ['--help'],
            'short flag' => ['-h'],
            'long flag with a value' => ['--help=secret'],
        ];
    }

    /**
     * @return array<string, array{0: list<string>}> Argv after the script name, with no command
     */
    public static function generalHelpArgv(): array
    {
        return [
            'no command' => [[]],
            'short help and no command' => [['-h']],
        ];
    }

    /**
     * @param string $token `--help`, `--help=<value>`, or `-h`
     */
    #[DataProvider('helpTokens')]
    public function testHelpAnswersTheCommandHelpWithoutRunningTheSpy(string $token): void
    {
        $manager = $this->manager([OptionGateSpyCommand::NAME, $token]);
        [$answered, $stdout] = $this->capture(fn (): ?int => $manager->answerWithoutRunning());

        self::assertSame(ExitCode::SUCCESS, $answered);
        self::assertSame("\n" . $manager->spy->getHelp() . "\n", $stdout);
        self::assertSame([], $manager->standardError);
        self::assertSame(0, $manager->spy->executeCalls);
    }

    public function testHelpWinsOverAnUnknownOption(): void
    {
        $manager = $this->manager([OptionGateSpyCommand::NAME, '--help', '--forc']);
        [$answered, $stdout] = $this->capture(fn (): ?int => $manager->answerWithoutRunning());

        self::assertSame(ExitCode::SUCCESS, $answered);
        self::assertSame("\n" . $manager->spy->getHelp() . "\n", $stdout);
        self::assertSame([], $manager->standardError);
        self::assertSame(0, $manager->spy->executeCalls);
    }

    public function testAnUnknownOptionIsRefusedWithoutRunningTheSpy(): void
    {
        $manager = $this->manager([OptionGateSpyCommand::NAME, '--forc']);
        [$answered, $stdout] = $this->capture(fn (): ?int => $manager->answerWithoutRunning());

        self::assertSame(ExitCode::INVALID_ARGUMENT, $answered);
        self::assertSame('', $stdout);
        self::assertSame(
            ['Unknown option --forc for option:gate:spy; see php cli.php help option:gate:spy'],
            $manager->standardError,
        );
        self::assertSame(0, $manager->spy->executeCalls);
    }

    public function testSeveralUnknownOptionsAreNamedInArgvOrder(): void
    {
        $forward = $this->manager([OptionGateSpyCommand::NAME, '--forc', '--tp']);
        [$answered, $stdout] = $this->capture(fn (): ?int => $forward->answerWithoutRunning());
        self::assertSame(ExitCode::INVALID_ARGUMENT, $answered);
        self::assertSame('', $stdout);
        self::assertSame(
            ['Unknown options --forc, --tp for option:gate:spy; see php cli.php help option:gate:spy'],
            $forward->standardError,
        );
        self::assertSame(0, $forward->spy->executeCalls);

        $reversed = $this->manager([OptionGateSpyCommand::NAME, '--tp', '--forc']);
        [$reversedAnswered, $reversedStdout] = $this->capture(fn (): ?int => $reversed->answerWithoutRunning());
        self::assertSame(ExitCode::INVALID_ARGUMENT, $reversedAnswered);
        self::assertSame('', $reversedStdout);
        self::assertSame(
            ['Unknown options --tp, --forc for option:gate:spy; see php cli.php help option:gate:spy'],
            $reversed->standardError,
        );
    }

    public function testAnUnknownOptionValueIsNotRepeated(): void
    {
        $manager = $this->manager([OptionGateSpyCommand::NAME, '--forc=secret']);
        [$answered, $stdout] = $this->capture(fn (): ?int => $manager->answerWithoutRunning());

        self::assertSame(ExitCode::INVALID_ARGUMENT, $answered);
        self::assertSame('', $stdout);
        self::assertSame(
            ['Unknown option --forc for option:gate:spy; see php cli.php help option:gate:spy'],
            $manager->standardError,
        );
        self::assertStringNotContainsString('secret', $manager->standardError[0]);
        self::assertSame(0, $manager->spy->executeCalls);
    }

    public function testADeclaredOptionReachesTheSpy(): void
    {
        $manager = $this->manager([OptionGateSpyCommand::NAME, '--force']);
        [$answered, $gateStdout] = $this->capture(fn (): ?int => $manager->answerWithoutRunning());
        self::assertNull($answered);
        self::assertSame('', $gateStdout);

        [$exitCode, $stdout] = $this->capture(fn (): int => $manager->run());
        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame('', $stdout);
        self::assertSame(1, $manager->spy->executeCalls);
        self::assertSame(['force' => true], $manager->spy->acceptedOptions);
        self::assertSame([], $manager->standardError);
    }

    public function testHelpOnAnUnknownCommandIsLeftToRun(): void
    {
        $manager = $this->manager(['no:such', '--help']);
        [$answered, $gateStdout] = $this->capture(fn (): ?int => $manager->answerWithoutRunning());
        self::assertNull($answered);
        self::assertSame('', $gateStdout);

        [$exitCode, $stdout] = $this->capture(fn (): int => $manager->run());
        self::assertSame(ExitCode::ERROR, $exitCode);
        self::assertStringContainsString('Unknown command: no:such', $stdout);
        self::assertNotSame('', $stdout);
    }

    /**
     * @param list<string> $argv Tokens after the script name
     */
    #[DataProvider('generalHelpArgv')]
    public function testNoNamedCommandPrintsGeneralHelp(array $argv): void
    {
        $manager = $this->manager($argv);
        [$answered, $gateStdout] = $this->capture(fn (): ?int => $manager->answerWithoutRunning());
        self::assertNull($answered);
        self::assertSame('', $gateStdout);

        [$exitCode, $stdout] = $this->capture(fn (): int => $manager->run());
        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertStringContainsString('Hilos CLI', $stdout);
        self::assertStringContainsString('php cli.php [command] -h', $stdout);
        self::assertStringNotContainsString('Unknown command', $stdout);
    }

    public function testTheHelpCommandNamesTheShortFlag(): void
    {
        self::assertStringContainsString("  php cli.php [command] -h\n", (new HelpCommand())->getHelp());
    }

    /**
     * @param list<string> $argv Tokens after the script name
     */
    private function manager(array $argv): OptionGateCliManager
    {
        return new OptionGateCliManager(['cli.php', ...$argv]);
    }

    /**
     * @param callable(): mixed $body
     * @return array{0: mixed, 1: string} The callback's return and the stdout it wrote
     */
    private function capture(callable $body): array
    {
        ob_start();
        try {
            $value = $body();
            $stdout = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return [$value, $stdout];
    }
}

/**
 * Spy registered as a project command. It declares one option and counts execute().
 */
final class OptionGateSpyCommand implements CommandInterface
{
    public const array OPTIONS = ['force'];

    public const string NAME = 'option:gate:spy';

    public int $executeCalls = 0;

    /** @var ?array<string, mixed> Options the last execute() received */
    public ?array $acceptedOptions = null;

    /**
     * @param array<string, mixed> $options Declared options the gate let through
     * @param list<string> $args Positional arguments
     * @return int Exit code
     */
    public function execute(array $options, array $args): int
    {
        $this->executeCalls++;
        $this->acceptedOptions = $options;

        return ExitCode::SUCCESS;
    }

    /**
     * @return string Command name the gate tests address
     */
    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @return CommandExecution Reading site; the spy records the call and writes nothing
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliRead('the option-gate spy only records that execute() was called');
    }

    /**
     * @return string Help-list description
     */
    public function getDescription(): string
    {
        return 'Option gate spy';
    }

    /**
     * @return string Detailed help the gate's help answer prints
     */
    public function getHelp(): string
    {
        return 'Option gate spy help';
    }
}

/**
 * Manager double whose project hook registers the spy and whose stderr is kept.
 */
final class OptionGateCliManager extends CliManager
{
    /** @var list<string> Sentences the manager wrote to stderr */
    public array $standardError = [];

    public OptionGateSpyCommand $spy;

    /**
     * @param list<string> $argv Command line, script name first
     */
    public function __construct(array $argv)
    {
        // registerProjectCommands() runs from the parent constructor, before help is built.
        $this->spy = new OptionGateSpyCommand();
        parent::__construct($argv);
    }

    /**
     * Registers the spy where help's constructor can see it.
     */
    protected function registerProjectCommands(): void
    {
        $this->addCommand($this->spy);
    }

    /**
     * Keeps the refusal sentence for the test instead of writing it to stderr.
     *
     * @param string $text Sentence the manager would have written, without its line break
     */
    protected function writeToStandardError(string $text): void
    {
        $this->standardError[] = $text;
    }
}
