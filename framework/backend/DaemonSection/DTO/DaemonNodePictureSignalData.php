<?php

declare(strict_types=1);

namespace Hilos\DaemonSection\DTO;

use Hilos\BaseDTO;
use Hilos\Cluster\NodeRole;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DaemonSection\DaemonCronPicture;
use Hilos\DaemonSection\DaemonCronRulePicture;
use Hilos\DaemonSection\NodeDaemonPicture;

/** Complete node picture sent from the node agent to the collector. */
final class DaemonNodePictureSignalData extends BaseDTO implements SignalDataInterface
{
    public const string nodeId = 'nodeId';
    public const string role = 'role';
    public const string sampledAt = 'sampledAt';
    public const string processes = 'processes';
    public const string cron = 'cron';
    public const string idleReason = 'idleReason';
    public const string rules = 'rules';
    public const string agentId = 'agentId';
    public const string name = 'name';
    public const string expression = 'expression';
    public const string lastRunAt = 'lastRunAt';
    public const string nextRunAt = 'nextRunAt';

    public function __construct(public readonly NodeDaemonPicture $picture)
    {
    }

    /** @return array<string, mixed> Wire payload */
    public function toArray(): array
    {
        return [
            self::nodeId => $this->picture->nodeId,
            self::role => $this->picture->role->value,
            self::sampledAt => $this->picture->sampledAt,
            self::processes => $this->picture->processes === null
                ? null
                : DaemonMasterProcessRosterSignalData::rosterToArray($this->picture->processes),
            self::cron => $this->picture->cron === null ? null : self::cronToArray($this->picture->cron),
        ];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Parsed picture
     * @throws InvalidFormatException When a field is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        $nodeId = self::requireString($data, self::nodeId);
        $role = NodeRole::tryFrom(self::requireString($data, self::role));
        if ($nodeId === '' || $role === null) {
            throw new InvalidFormatException('Daemon node picture carries an empty node id or invalid role');
        }

        $processes = self::requireNullableArray($data, self::processes);
        $cron = self::requireNullableArray($data, self::cron);

        return new static(new NodeDaemonPicture(
            $nodeId,
            $role,
            self::requireInt($data, self::sampledAt),
            $processes === null ? null : DaemonMasterProcessRosterSignalData::rosterFromArray($processes),
            $cron === null ? null : self::cronFromArray($cron),
        ));
    }

    /**
     * @param DaemonCronPicture $cron Cron section of the node picture
     * @return array<string, mixed> Shared wire shape
     */
    public static function cronToArray(DaemonCronPicture $cron): array
    {
        return [
            self::idleReason => $cron->idleReason,
            self::rules => array_map(static fn (DaemonCronRulePicture $rule): array => [
                self::agentId => $rule->agentId,
                self::name => $rule->name,
                self::expression => $rule->expression,
                self::lastRunAt => $rule->lastRunAt,
                self::nextRunAt => $rule->nextRunAt,
            ], $cron->rules),
        ];
    }

    /**
     * @param array<string, mixed> $data Shared wire shape
     * @return DaemonCronPicture Parsed cron section
     * @throws InvalidFormatException When a required field is absent or invalid
     */
    public static function cronFromArray(array $data): DaemonCronPicture
    {
        $rows = self::requireArray($data, self::rules);
        if (!array_is_list($rows)) {
            throw new InvalidFormatException('Daemon cron picture rules must be a list');
        }
        $rules = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidFormatException('Daemon cron picture rule must be an object');
            }
            $rules[] = new DaemonCronRulePicture(
                self::requiredNullableString($row, self::agentId),
                self::requireString($row, self::name),
                self::requireString($row, self::expression),
                self::requiredNullableInt($row, self::lastRunAt),
                self::requiredNullableInt($row, self::nextRunAt),
            );
        }
        return new DaemonCronPicture(self::requiredNullableString($data, self::idleReason), $rules);
    }

    /**
     * @param array<string, mixed> $data Wire object
     * @param string $key Required nullable string key
     * @return ?string String or explicit null
     * @throws InvalidFormatException When the key is absent or mistyped
     */
    private static function requiredNullableString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidFormatException('Payload carries no string or null under key ' . $key);
        }
        return self::optionalString($data, $key);
    }

    /**
     * @param array<string, mixed> $data Wire object
     * @param string $key Required nullable integer key
     * @return ?int Integer or explicit null
     * @throws InvalidFormatException When the key is absent or mistyped
     */
    private static function requiredNullableInt(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidFormatException('Payload carries no integer or null under key ' . $key);
        }
        return self::optionalInt($data, $key);
    }
}
