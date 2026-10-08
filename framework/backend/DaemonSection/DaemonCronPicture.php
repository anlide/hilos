<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Core\Exception\InvalidFormatException;

/** Complete cron section of one node's picture. */
final readonly class DaemonCronPicture
{
    public const string IDLE_NOT_LEADER = 'not_leader';

    /**
     * @param ?string $idleReason Why master's rules do not run here, or null when they do
     * @param list<DaemonCronRulePicture> $rules Master rules first, then agent ids and names in ascending order
     * @throws InvalidFormatException When the reason, rule order, or an idle master's next run is invalid
     */
    public function __construct(public ?string $idleReason, public array $rules)
    {
        if ($idleReason !== null && $idleReason !== self::IDLE_NOT_LEADER) {
            throw new InvalidFormatException('Daemon cron picture has an unknown idle reason');
        }
        if (!array_is_list($rules)) {
            throw new InvalidFormatException('Daemon cron picture rules must be a list');
        }

        $previousAgentId = null;
        $previousName = null;
        foreach ($rules as $rule) {
            if (!$rule instanceof DaemonCronRulePicture) {
                throw new InvalidFormatException('Daemon cron picture carries an invalid rule');
            }
            if ($idleReason !== null && $rule->agentId === null && $rule->nextRunAt !== null) {
                throw new InvalidFormatException('An idle master rule cannot have a next run on this node');
            }
            $agentId = $rule->agentId ?? '';
            if ($previousName !== null && (
                strcmp($previousAgentId ?? '', $agentId) > 0
                || ($previousAgentId === $agentId && strcmp($previousName, $rule->name) >= 0)
            )) {
                throw new InvalidFormatException('Daemon cron picture rules must be unique and sorted by owner and name');
            }
            $previousAgentId = $rule->agentId;
            $previousName = $rule->name;
        }
    }
}
