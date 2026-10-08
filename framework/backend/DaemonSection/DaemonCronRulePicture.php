<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Core\Exception\InvalidFormatException;

/** A cron row in the node picture, with the node's next-run calculation. */
final readonly class DaemonCronRulePicture
{
    /**
     * @param ?string $agentId Null for a master's rule, otherwise a non-empty agent id
     * @param string $name Non-empty rule name
     * @param string $expression Cron expression
     * @param ?int $lastRunAt Unix time of the last actual firing
     * @param ?int $nextRunAt Unix time of the next matching minute, if one is known
     * @throws InvalidFormatException When an identity is empty
     */
    public function __construct(
        public ?string $agentId,
        public string $name,
        public string $expression,
        public ?int $lastRunAt,
        public ?int $nextRunAt,
    ) {
        if ($agentId === '' || $name === '') {
            throw new InvalidFormatException('Daemon cron picture rule has an empty identity');
        }
    }
}
