<?php

declare(strict_types=1);

namespace Hilos\DaemonSection\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DaemonSection\DaemonCronPicture;
use Hilos\DaemonSection\DaemonCronRuleReport;

/** Whole master cron rule set sent to the Daemon agent on the same node. */
final class DaemonMasterCronSignalData extends BaseDTO implements SignalDataInterface
{
    public const string nodeId = 'nodeId';
    public const string idleReason = 'idleReason';
    public const string rules = 'rules';
    public const string name = 'name';
    public const string expression = 'expression';
    public const string lastRunAt = 'lastRunAt';

    /**
     * @param string $nodeId Non-empty source node id
     * @param ?string $idleReason Null when the master executes cron, otherwise not_leader
     * @param list<DaemonCronRuleReport> $rules Complete sorted rule set
     * @throws InvalidFormatException When the identity, reason, or rule set is invalid
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly ?string $idleReason,
        public readonly array $rules,
    ) {
        if ($nodeId === '' || ($idleReason !== null && $idleReason !== DaemonCronPicture::IDLE_NOT_LEADER)) {
            throw new InvalidFormatException('Daemon master cron carries an invalid node id or idle reason');
        }
        DaemonCronRuleReport::assertSorted($rules);
    }

    /** @return array<string, mixed> Wire payload */
    public function toArray(): array
    {
        return [
            self::nodeId => $this->nodeId,
            self::idleReason => $this->idleReason,
            self::rules => self::rulesToArray($this->rules),
        ];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Parsed master cron frame
     * @throws InvalidFormatException When a required field is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::nodeId),
            self::requiredNullableString($data, self::idleReason),
            self::rulesFromArray($data),
        );
    }

    /**
     * @param list<DaemonCronRuleReport> $rules Reported rules
     * @return list<array<string, mixed>> Shared wire rows
     */
    public static function rulesToArray(array $rules): array
    {
        return array_map(static fn (DaemonCronRuleReport $rule): array => [
            self::name => $rule->name,
            self::expression => $rule->expression,
            self::lastRunAt => $rule->lastRunAt,
        ], $rules);
    }

    /**
     * @param array<string, mixed> $data Wire object holding rules
     * @return list<DaemonCronRuleReport> Parsed rules
     * @throws InvalidFormatException When a row is absent, mistyped, or unsorted
     */
    public static function rulesFromArray(array $data): array
    {
        $rows = self::requireArray($data, self::rules);
        if (!array_is_list($rows)) {
            throw new InvalidFormatException('Daemon cron rules must be a list');
        }
        $rules = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidFormatException('Daemon cron rule must be an object');
            }
            $rules[] = new DaemonCronRuleReport(
                self::requireString($row, self::name),
                self::requireString($row, self::expression),
                self::requiredNullableInt($row, self::lastRunAt),
            );
        }
        DaemonCronRuleReport::assertSorted($rules);
        return $rules;
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
