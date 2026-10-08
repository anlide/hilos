<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Core\Exception\InvalidFormatException;

/** One rule reported by its execution owner, before the node computes its next run. */
final readonly class DaemonCronRuleReport
{
    /**
     * @param string $name Non-empty rule name
     * @param string $expression Cron expression, including an expression the runner cannot use
     * @param ?int $lastRunAt Unix time of the last actual firing
     * @throws InvalidFormatException When the name is empty
     */
    public function __construct(
        public string $name,
        public string $expression,
        public ?int $lastRunAt,
    ) {
        if ($name === '') {
            throw new InvalidFormatException('Daemon cron rule has an empty name');
        }
    }

    /**
     * @param list<self> $rules Complete rule set from one owner
     * @throws InvalidFormatException When the rules are not a unique sorted list
     */
    public static function assertSorted(array $rules): void
    {
        if (!array_is_list($rules)) {
            throw new InvalidFormatException('Daemon cron rules must be a list');
        }
        $previousName = null;
        foreach ($rules as $rule) {
            if (!$rule instanceof self || ($previousName !== null && strcmp($previousName, $rule->name) >= 0)) {
                throw new InvalidFormatException('Daemon cron rules must have unique sorted names');
            }
            $previousName = $rule->name;
        }
    }
}
