<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Core\Daemon\Cron\CronRule;

/** @internal One agent's observed rule identity and scalar values, private to its report loop. */
final readonly class AgentCronRuleSnapshot
{
    public string $name;
    public string $expression;
    public ?int $lastFiredAt;

    /** @param CronRule $rule Rule whose current values are captured */
    public function __construct(public CronRule $rule)
    {
        $this->name = $rule->name;
        $this->expression = $rule->expression;
        $this->lastFiredAt = $rule->lastFiredAt;
    }
}
