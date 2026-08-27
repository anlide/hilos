<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Constants\TimeConstants;
use Hilos\Hilos;
use Hilos\LLM\ClientFactory;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\Exception\LLMConfigurationException;
use Hilos\LLM\Exception\LLMException;
use Hilos\LLM\Local\Chat\AsyncOllamaChatProvider;
use Hilos\LLM\Routing\LlmProfile;
use Hilos\LLM\Routing\LlmProfileCatalogConstants;
use Hilos\LLM\Routing\LlmRouter;

/**
 * LlmPingCommand - resolve an LLM profile and optionally probe its endpoint.
 *
 * An operator-facing command (not a {@see TestOnlyCommand}: it must work on a real
 * install) that answers two questions about a running install: what an LLM profile
 * resolves to, and whether that endpoint actually answers. Resolution goes through
 * {@see LlmRouter} so the answer is exactly what an agent would
 * get; the probe runs one minimal chat request inside the CLI process with its own
 * tick() loop, so it works even when the daemon is down — but it reports the CLI
 * process's environment, not the daemon container's.
 */
class LlmPingCommand implements CommandInterface
{
    /** @var int Column width the resolution labels are padded to */
    private const int LABEL_WIDTH = 10;

    /** @var int Reply snippet length printed after a successful probe */
    private const int REPLY_SNIPPET_LEN = 80;

    /** @var int Token cap for the probe request (a ping needs almost none) */
    private const int PROBE_MAX_TOKENS = 8;

    /** @var string Option flag: also send one minimal chat request */
    private const string OPTION_PROBE = 'probe';

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (llm:ping)
     */
    public function getName(): string
    {
        return CliCommands::LLM_PING;
    }

    /**
     * Declares the departure: this reading happens in the CLI process, for the reason it names.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliRead(
            'probes the provider of an installation whose daemon did not start, which is when the answer is wanted',
        );
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Resolve an LLM profile and optionally probe its endpoint';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: llm:ping [profile] [--probe]

Description:
  Answer two questions about a running install: what an LLM profile resolves to,
  and whether that endpoint actually answers. With no argument, lists the catalog
  profile keys. With a profile, prints its resolution (provider, url, model,
  timeout, placement, api key fingerprint); --probe also sends one minimal chat
  request and reports latency and the start of the reply. The api key itself is
  never printed. Runs inside the CLI process, so it works when the daemon is down,
  but it reports the CLI process's environment, not the daemon container's.

Arguments:
  profile    Catalog profile key to resolve (e.g. default)

Options:
  --probe    Also send one minimal chat request to the resolved endpoint

Usage:
  php cli.php llm:ping
  php cli.php llm:ping default
  php cli.php llm:ping default --probe
HELP;
    }

    /**
     * Execute llm:ping command.
     *
     * @param array<string, mixed> $options Parsed options (--probe flag)
     * @param list<string> $args Positional args; the first is the profile key
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        $router = Hilos::$llm;
        if ($router === null) {
            echo "Error: LLM router is not available\n";
            return ExitCode::CONFIG_ERROR;
        }

        $catalog = $router->catalog();

        if ($args === []) {
            $this->printProfileKeys($catalog);
            return ExitCode::SUCCESS;
        }

        $profileKey = $args[0];
        if (!array_key_exists($profileKey, $catalog)) {
            echo "Unknown profile '{$profileKey}'.\n";
            $this->printProfileKeys($catalog);
            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            $base = $router->resolveBase($profileKey);
            $profile = $router->resolve($profileKey);
            $this->printResolution($base, $profile, $catalog[$profileKey]);

            if (($options[self::OPTION_PROBE] ?? false) === true) {
                return $this->probe($profile);
            }
        } catch (LLMConfigurationException $e) {
            echo "Configuration error: {$e->getMessage()}\n";
            echo 'Responsible env vars: ' . $this->envVarNames($catalog[$profileKey]) . "\n";
            return ExitCode::CONFIG_ERROR;
        }

        return ExitCode::SUCCESS;
    }

    /**
     * Prints the catalog profile keys plus usage.
     *
     * @param array<string, array<string, mixed>> $catalog Profile catalog
     */
    private function printProfileKeys(array $catalog): void
    {
        echo "Available LLM profiles:\n";
        foreach (array_keys($catalog) as $key) {
            echo "  {$key}\n";
        }
        echo "\nUsage: php cli.php llm:ping <profile> [--probe]\n";
    }

    /**
     * Prints the resolution block: base values with per-field override markers.
     *
     * @param LlmProfile $base Env-backed profile, before any runtime override
     * @param LlmProfile $profile Effective profile, after any override
     * @param array<string, mixed> $entry Catalog entry (env-variable names)
     */
    private function printResolution(LlmProfile $base, LlmProfile $profile, array $entry): void
    {
        $this->line('profile', $profile->key);
        $this->line('provider', $this->marked($profile->provider->value, $base->provider->value));
        $this->line('url', $this->marked($profile->url, $base->url));
        $this->line('model', $this->marked($profile->model, $base->model));
        $this->line('timeout', $this->marked("{$profile->timeoutSec}s", "{$base->timeoutSec}s"));
        $this->line('placement', $this->marked($profile->placement ?? '-', $base->placement ?? '-'));
        $this->line('api key', $this->apiKeyValue($profile, $entry));

        echo "\nNote (HIL-331): this is what the profile resolves to right now. A running\n";
        echo "agent resolved its profile in its constructor and keeps that one until it is\n";
        echo "recreated, so a live agent may still use the previous resolution.\n";
    }

    /**
     * Formats a resolved value, marking it when the override changed it.
     *
     * @param string $final Effective value, after any override
     * @param string $base Env-backed value, before any override
     * @return string `final` unchanged, or `final (overridden, env was <base>)`
     */
    private function marked(string $final, string $base): string
    {
        return $final === $base ? $final : "{$final} (overridden, env was {$base})";
    }

    /**
     * Renders the api key line without exposing the secret.
     *
     * @param LlmProfile $profile Effective profile carrying the api key
     * @param array<string, mixed> $entry Catalog entry (for the api key env name)
     * @return string `present (fp <8 hex>, env <NAME>)` or `absent`
     */
    private function apiKeyValue(LlmProfile $profile, array $entry): string
    {
        $key = $profile->apiKey;
        if ($key === null || $key === '') {
            return 'absent';
        }

        $fingerprint = substr(hash('sha256', $key), 0, 8);
        $envName = (string) ($entry[LlmProfileCatalogConstants::API_KEY_ENV] ?? '?');

        return "present (fp {$fingerprint}, env {$envName})";
    }

    /**
     * Prints one aligned `label: value` resolution line.
     *
     * @param string $label Field label
     * @param string $value Field value
     */
    private function line(string $label, string $value): void
    {
        printf('%-' . self::LABEL_WIDTH . "s %s\n", $label . ':', $value);
    }

    /**
     * Collects the entry's non-null env-variable names for a config-error hint.
     *
     * @param array<string, mixed> $entry Catalog entry
     * @return string Comma-separated env-variable names
     */
    private function envVarNames(array $entry): string
    {
        $fieldKeys = [
            LlmProfileCatalogConstants::PROVIDER_ENV,
            LlmProfileCatalogConstants::LOCAL_URL_ENV,
            LlmProfileCatalogConstants::LOCAL_URL_FALLBACK_ENV,
            LlmProfileCatalogConstants::LOCAL_MODEL_ENV,
            LlmProfileCatalogConstants::EXTERNAL_URL_ENV,
            LlmProfileCatalogConstants::EXTERNAL_MODEL_ENV,
            LlmProfileCatalogConstants::API_KEY_ENV,
            LlmProfileCatalogConstants::TIMEOUT_ENV,
        ];

        $names = [];
        foreach ($fieldKeys as $fieldKey) {
            $name = $entry[$fieldKey] ?? null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names === [] ? '(none)' : implode(', ', $names);
    }

    /**
     * Sends one minimal chat request and reports outcome, latency and reply.
     *
     * Drives the async client with its own tick() loop bounded by the profile's
     * timeout — the same DTO pair {@see AsyncOllamaChatProvider}
     * receives from an agent, minus the daemon event loop.
     *
     * @param LlmProfile $profile Effective profile to probe
     * @return int Exit code (SUCCESS, TIMEOUT, or ERROR)
     * @throws LLMConfigurationException When an external profile carries no API key
     */
    private function probe(LlmProfile $profile): int
    {
        $client = ClientFactory::createChatClientForProfile($profile);
        $options = new ChatGenerateOptions(
            model: $profile->model,
            temperature: 0.0,
            timeoutSec: $profile->timeoutSec,
            maxTokens: self::PROBE_MAX_TOKENS,
        );

        echo "\n";

        try {
            $client->startGenerate([new Message(Message::ROLE_USER, 'ping')], $options);

            $startedAt = microtime(true);
            while (!$client->hasResult()) {
                if ((microtime(true) - $startedAt) > $profile->timeoutSec) {
                    echo "probe: timeout after {$profile->timeoutSec}s\n";
                    return ExitCode::TIMEOUT;
                }

                $client->tick(microtime(true) * TimeConstants::MS_PER_SECOND);
                usleep(CommandConstants::POLL_INTERVAL_US);
            }

            $reply = $client->consumeResult();
            $elapsedMs = (int) round((microtime(true) - $startedAt) * TimeConstants::MS_PER_SECOND);

            echo "probe: ok, {$elapsedMs} ms\n";
            echo 'reply: ' . $this->snippet($reply) . "\n";

            return ExitCode::SUCCESS;
        } catch (LLMException $e) {
            echo "probe: error, {$e->getMessage()}\n";
            return ExitCode::ERROR;
        }
    }

    /**
     * Collapses newlines and truncates a reply to a one-line snippet.
     *
     * @param string $reply Raw generated reply
     * @return string First REPLY_SNIPPET_LEN chars, newlines collapsed to spaces
     */
    private function snippet(string $reply): string
    {
        $oneLine = trim((string) preg_replace('/\s+/', ' ', $reply));

        return mb_substr($oneLine, 0, self::REPLY_SNIPPET_LEN);
    }
}
