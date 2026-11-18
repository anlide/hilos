<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Auth\AccountOperation\Testing\AccountErasureProbeRequest;
use Hilos\Auth\AccountOperation\Testing\AccountErasureProbeState;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Environment\Exception\EnvException;
use JsonException;

/** Thin CLI half of the local worker's bounded erasure probe. */
class AccountErasureProbeCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    public const array OPTIONS = [
        self::OPTION_FRAME_KIND, self::OPTION_OPERATION_ID, self::OPTION_STAGE,
        self::OPTION_ASSIGNMENT_ID, self::OPTION_FRAME_JSON, self::OPTION_COPIES,
    ];

    private const string OPTION_FRAME_KIND = 'frame-kind';
    private const string OPTION_OPERATION_ID = 'operation-id';
    private const string OPTION_STAGE = 'stage';
    private const string OPTION_ASSIGNMENT_ID = 'assignment-id';
    private const string OPTION_FRAME_JSON = 'frame-json';
    private const string OPTION_COPIES = 'copies';

    /** @return string Test-only command name */
    public function getName(): string
    {
        return CliCommands::ACCOUNT_TEST_ERASURE_PROBE;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return 'Control one local worker erasure probe (test-only)';
    }

    /** @return string Supported arguments, options and output */
    public function getHelp(): string
    {
        return <<<HELP
Usage: {$this->getName()} <agentId> <action>
  Actions: arm, inspect, release, discard, replay, expire-idle, clear.
  arm requires --frame-kind=ask|answer; optional selectors:
    --operation-id=<positive id> --stage=<stage> --assignment-id=<positive id>
  replay requires --frame-json=<captured full envelope> --copies=1|2.
  Other actions accept no options. Only an existing local source is addressed.
  expire-idle ages a personal agent's declared idle window; the ordinary tick
  still applies subscriber, work and freeze guards.
  Success prints one JSON state: armed, matched, held (the full envelope or null).
  Refuses a production-like environment. Exit codes: 0 success, 1 refusal or
  channel failure, 2 invalid arguments, 3 invalid channel environment.
HELP;
    }

    /**
     * @param array<string, mixed> $options Explicitly declared CLI options
     * @param list<string> $args Source agent id and action
     * @return int Standard CLI exit code
     * @throws CommandException When the command is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        try {
            if (count($args) !== 2) {
                throw new InvalidFormatException('Expected agentId and action');
            }
            $payload = [AgentConstants::FIELD_AGENT_ID => $args[0], AccountErasureProbeRequest::ACTION => $args[1]];
            if (array_key_exists(self::OPTION_FRAME_KIND, $options)) {
                $payload[AccountErasureProbeRequest::FRAME_KIND] = $options[self::OPTION_FRAME_KIND];
            }
            if (array_key_exists(self::OPTION_OPERATION_ID, $options)) {
                $payload[AccountErasureProbeRequest::OPERATION_ID] = $this->positiveInteger($options[self::OPTION_OPERATION_ID]);
            }
            if (array_key_exists(self::OPTION_STAGE, $options)) {
                $payload[AccountErasureProbeRequest::STAGE] = $options[self::OPTION_STAGE];
            }
            if (array_key_exists(self::OPTION_ASSIGNMENT_ID, $options)) {
                $payload[AccountErasureProbeRequest::ASSIGNMENT_ID] = $this->positiveInteger($options[self::OPTION_ASSIGNMENT_ID]);
            }
            if (array_key_exists(self::OPTION_FRAME_JSON, $options)) {
                if (!is_string($options[self::OPTION_FRAME_JSON])) {
                    throw new InvalidFormatException('frame-json requires a JSON envelope');
                }
                $frame = json_decode($options[self::OPTION_FRAME_JSON], true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($frame)) {
                    throw new InvalidFormatException('frame-json requires a JSON envelope');
                }
                $payload[AccountErasureProbeRequest::FRAME] = $frame;
            }
            if (array_key_exists(self::OPTION_COPIES, $options)) {
                $payload[AccountErasureProbeRequest::COPIES] = $this->positiveInteger($options[self::OPTION_COPIES]);
            }
            $request = AccountErasureProbeRequest::fromArray($payload);
        } catch (InvalidFormatException|JsonException $failure) {
            echo "Usage: {$this->getName()} <agentId> <action>: {$failure->getMessage()}\n";
            return ExitCode::INVALID_ARGUMENT;
        }
        try {
            $result = $this->sendCommand($this->getName(), $request->toArray());
        } catch (EnvException $failure) {
            echo "Error: {$failure->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }
        if ($result->reply === null) {
            return $this->printChannelFailure($result, $this->getName());
        }
        if (!$result->reply->isOk()) {
            return $this->printRefusal($result->reply);
        }
        try {
            echo AccountErasureProbeState::fromArray($result->reply->payload)->toJson() . "\n";
        } catch (InvalidFormatException) {
            echo "Command failed: the reply carries no valid erasure probe state\n";
            return ExitCode::ERROR;
        }
        return ExitCode::SUCCESS;
    }

    /**
     * @param mixed $value Integer option as supplied by the operator
     * @return int Positive integer without truncation or overflow
     * @throws InvalidFormatException When the option is absent, mistyped or out of range
     */
    private function positiveInteger(mixed $value): int
    {
        if (!is_string($value) || !ctype_digit($value)) {
            throw new InvalidFormatException('Expected a positive integer option');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer <= 0) {
            throw new InvalidFormatException('Expected a positive integer option');
        }
        return $integer;
    }
}
