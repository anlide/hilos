<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Files\Upload\DTO\UploadMemoryTestRequest;
use JsonException;

/** Initiates a small fixture on the running master and prints that master's live pool. */
final class ClusterTestUploadPoolCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    public const array OPTIONS = ['memory-pool-bytes', 'target-node-id', 'agent-type', 'agent-index', 'bytes'];

    /** @return string Exact test command route */
    public function getName(): string
    {
        return CliCommands::CLUSTER_TEST_UPLOAD_POOL;
    }

    /** @return string CLI listing description */
    public function getDescription(): string
    {
        return 'Inspect, limit or enqueue a signed upload pool fixture (test-only)';
    }

    /** @return string Usage of the test-only driver */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:cluster:upload-pool <inspect|limit|enqueue>
  inspect
  limit --memory-pool-bytes=<nonnegative integer>
  enqueue --target-node-id=<node> --agent-type=<live probe> [--agent-index=<index>] --bytes=<1..65536>

Prints limitBytes, usedBytes, waitingUploads and pendingPeerReservations as JSON.
Both CLI and daemon refuse test:* commands in production.
HELP;
    }

    /**
     * @param array<string, mixed> $options Declared command options
     * @param list<string> $args Operation name
     * @return int Standard CLI exit code
     * @throws CommandException When the wire name is not test-only
     * @throws JsonException When a reply cannot be printed as JSON
     */
    protected function run(array $options, array $args): int
    {
        try {
            $request = UploadMemoryTestRequest::fromArray([
                'operation' => $args[0] ?? null,
                'memoryPoolBytes' => self::readInteger($options['memory-pool-bytes'] ?? null),
                'targetNodeId' => $options['target-node-id'] ?? null,
                'agentType' => $options['agent-type'] ?? null,
                'agentIndex' => $options['agent-index'] ?? null,
                'bytes' => self::readInteger($options['bytes'] ?? null),
            ]);
        } catch (InvalidFormatException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return ExitCode::ERROR;
        }
        try {
            $result = $this->sendCommand(CliCommands::CLUSTER_TEST_UPLOAD_POOL, $request->toArray());
        } catch (EnvException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return ExitCode::CONFIG_ERROR;
        }
        if ($result->reply === null) {
            return $this->printChannelFailure($result, CliCommands::CLUSTER_TEST_UPLOAD_POOL);
        }
        if (!$result->reply->isOk()) {
            return $this->printRefusal($result->reply);
        }
        echo json_encode($result->reply->payload, JSON_THROW_ON_ERROR) . "\n";

        return ExitCode::SUCCESS;
    }

    /**
     * @param mixed $value Optional CLI integer text
     * @return ?int Exact integer, or null when omitted
     * @throws InvalidFormatException When a present option is not an integer
     */
    private static function readInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if ((!is_string($value) && !is_int($value)) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidFormatException('Pool sizes must be integer byte counts');
        }

        return (int)$value;
    }
}
