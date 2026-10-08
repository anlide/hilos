<?php

declare(strict_types=1);

namespace Hilos\DaemonSection\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DaemonSection\DaemonCronRuleReport;

/** Whole cron rule set reported by one agent to its node's Daemon agent. */
final class DaemonAgentCronSignalData extends BaseDTO implements SignalDataInterface
{
    public const string agentId = 'agentId';
    public const string rules = 'rules';

    /**
     * @param string $agentId Non-empty source agent id
     * @param list<DaemonCronRuleReport> $rules Complete sorted rule set, or empty when withdrawn
     * @throws InvalidFormatException When the identity or rule set is invalid
     */
    public function __construct(public readonly string $agentId, public readonly array $rules)
    {
        if ($agentId === '') {
            throw new InvalidFormatException('Daemon agent cron carries an empty agent id');
        }
        DaemonCronRuleReport::assertSorted($rules);
    }

    /** @return array<string, mixed> Wire payload */
    public function toArray(): array
    {
        return [
            self::agentId => $this->agentId,
            self::rules => DaemonMasterCronSignalData::rulesToArray($this->rules),
        ];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Parsed agent cron report
     * @throws InvalidFormatException When a required field is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, self::agentId), DaemonMasterCronSignalData::rulesFromArray($data));
    }
}
