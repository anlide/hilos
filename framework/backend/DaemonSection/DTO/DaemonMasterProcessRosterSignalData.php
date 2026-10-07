<?php

declare(strict_types=1);

namespace Hilos\DaemonSection\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonWorkerPicture;

/** Whole process roster sent by the master to its own node agent. */
final class DaemonMasterProcessRosterSignalData extends BaseDTO implements SignalDataInterface
{
    public const string nodeId = 'nodeId';
    public const string workers = 'workers';
    public const string index = 'index';
    public const string kind = 'kind';
    public const string pid = 'pid';
    public const string memoryBytes = 'memoryBytes';
    public const string agents = 'agents';
    public const string id = 'id';
    public const string scope = 'scope';
    public const string placement = 'placement';
    public const string unplacedAgentIds = 'unplacedAgentIds';
    public const string workerRestarts24h = 'workerRestarts24h';

    /**
     * @param string $nodeId Source node id
     * @param DaemonProcessRoster $roster Complete process roster
     * @throws InvalidFormatException When the node id is empty
     */
    public function __construct(public readonly string $nodeId, public readonly DaemonProcessRoster $roster)
    {
        if ($nodeId === '') {
            throw new InvalidFormatException('Daemon master process roster carries an empty node id');
        }
    }

    /** @return array<string, mixed> Wire payload */
    public function toArray(): array
    {
        return [self::nodeId => $this->nodeId] + self::rosterToArray($this->roster);
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Parsed master roster
     * @throws InvalidFormatException When a field is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, self::nodeId), self::rosterFromArray($data));
    }

    /**
     * @param DaemonProcessRoster $roster Process section of a whole node picture
     * @return array<string, mixed> Shared wire shape without node id
     */
    public static function rosterToArray(DaemonProcessRoster $roster): array
    {
        return [
            self::workers => array_map(
                static fn (DaemonWorkerPicture $worker): array => [
                    self::index => $worker->index,
                    self::kind => $worker->kind,
                    self::pid => $worker->pid,
                    self::memoryBytes => $worker->memoryBytes,
                    self::agents => array_map(
                        static fn (DaemonAgentPicture $agent): array => [
                            self::id => $agent->id,
                            self::scope => $agent->scope,
                            self::placement => $agent->placement,
                        ],
                        $worker->agents,
                    ),
                ],
                $roster->workers,
            ),
            self::unplacedAgentIds => $roster->unplacedAgentIds,
            self::workerRestarts24h => $roster->workerRestarts24h,
        ];
    }

    /**
     * @param array<string, mixed> $data Shared wire shape without node id
     * @return DaemonProcessRoster Parsed process section
     * @throws InvalidFormatException When a field is absent or invalid
     */
    public static function rosterFromArray(array $data): DaemonProcessRoster
    {
        $rows = self::requireArray($data, self::workers);
        if (!array_is_list($rows)) {
            throw new InvalidFormatException('Daemon process workers must be a list');
        }
        $workers = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidFormatException('Daemon process worker must be an object');
            }
            $agentRows = self::requireArray($row, self::agents);
            if (!array_is_list($agentRows)) {
                throw new InvalidFormatException('Daemon worker agents must be a list');
            }
            $agents = [];
            foreach ($agentRows as $agentRow) {
                if (!is_array($agentRow)) {
                    throw new InvalidFormatException('Daemon worker agent must be an object');
                }
                $agents[] = new DaemonAgentPicture(
                    self::requireString($agentRow, self::id),
                    self::requireString($agentRow, self::scope),
                    self::requireString($agentRow, self::placement),
                );
            }
            $workers[] = new DaemonWorkerPicture(
                self::requireInt($row, self::index),
                self::requireString($row, self::kind),
                self::requiredNullableInt($row, self::pid),
                self::requiredNullableInt($row, self::memoryBytes),
                $agents,
            );
        }
        $unplacedRows = self::requireNullableArray($data, self::unplacedAgentIds);
        if ($unplacedRows !== null) {
            if (!array_is_list($unplacedRows)) {
                throw new InvalidFormatException('Daemon unplaced agents must be a list');
            }
            foreach ($unplacedRows as $id) {
                if (!is_string($id)) {
                    throw new InvalidFormatException('Daemon unplaced agent id must be a string');
                }
            }
        }

        return new DaemonProcessRoster($workers, $unplacedRows, self::requireInt($data, self::workerRestarts24h));
    }

    /**
     * @param array<string, mixed> $data Wire object
     * @param string $key Required nullable integer key
     * @return ?int Integer or explicit null
     * @throws InvalidFormatException When the field is absent or holds another type
     */
    private static function requiredNullableInt(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidFormatException('Payload carries no integer or null under key ' . $key);
        }

        return self::optionalInt($data, $key);
    }
}
