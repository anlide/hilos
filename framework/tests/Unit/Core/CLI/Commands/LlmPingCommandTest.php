<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\ExitCode;
use Hilos\Constants\LLMConstants;
use Hilos\Core\CLI\Commands\LlmPingCommand;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\LLM\Routing\LlmRouter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the llm:ping CLI, covering only the non-network branches.
 *
 * The resolution and listing paths need no daemon and no endpoint: they read the
 * catalog and env and print. The --probe path is a live network round-trip and is
 * exercised by the nova-lt acceptance run, not here (the convention from
 * {@see VerificationTestExpireCommandTest}).
 */
final class LlmPingCommandTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;
    private ?LlmRouter $previousLlm = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousLlm = Hilos::$llm;
        Hilos::$env = new EnvAccessor();
        Hilos::$llm = new LlmRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        Hilos::$llm = $this->previousLlm;
        foreach (['LLM_CHAT_PROVIDER', 'LLM_EXTERNAL_API_KEY'] as $key) {
            putenv($key);
        }
    }

    public function testNoArgumentListsProfilesAndSucceeds(): void
    {
        $output = $this->invoke([], []);

        self::assertSame(ExitCode::SUCCESS, $output['exit']);
        self::assertStringContainsString('default', $output['text']);
    }

    public function testUnknownProfileIsInvalidArgument(): void
    {
        $output = $this->invoke([], ['does-not-exist']);

        self::assertSame(ExitCode::INVALID_ARGUMENT, $output['exit']);
        self::assertStringContainsString('Unknown profile', $output['text']);
    }

    public function testSuccessfulResolutionPrintsFieldsWithoutTheKey(): void
    {
        putenv('LLM_CHAT_PROVIDER=' . LLMConstants::PROVIDER_EXTERNAL);
        putenv('LLM_EXTERNAL_API_KEY=sk-super-secret-value');

        $output = $this->invoke([], ['default']);

        self::assertSame(ExitCode::SUCCESS, $output['exit']);
        self::assertStringContainsString('provider:', $output['text']);
        self::assertStringContainsString(LLMConstants::PROVIDER_EXTERNAL, $output['text']);
        self::assertStringContainsString('present (fp ', $output['text']);
        self::assertStringNotContainsString('sk-super-secret-value', $output['text']);
    }

    public function testExternalWithoutKeyIsConfigError(): void
    {
        putenv('LLM_CHAT_PROVIDER=' . LLMConstants::PROVIDER_EXTERNAL);
        putenv('LLM_EXTERNAL_API_KEY=');

        $output = $this->invoke([], ['default']);

        self::assertSame(ExitCode::CONFIG_ERROR, $output['exit']);
        self::assertStringContainsString('Configuration error', $output['text']);
    }

    /**
     * Runs the command capturing its stdout.
     *
     * @param array<string, mixed> $options Parsed options
     * @param list<string> $args Positional args
     * @return array{exit: int, text: string} Exit code and captured output
     */
    private function invoke(array $options, array $args): array
    {
        ob_start();
        $exit = new LlmPingCommand()->execute($options, $args);
        $text = (string) ob_get_clean();

        return ['exit' => $exit, 'text' => $text];
    }
}
