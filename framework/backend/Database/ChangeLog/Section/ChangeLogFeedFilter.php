<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

use DateTimeImmutable;
use Hilos\Core\Exception\InvalidArgumentException;

/** Optional filters shared by the receipt feed and its counts. */
final readonly class ChangeLogFeedFilter
{
    private const array CHANNELS = ['web', 'migration', 'agent', 'cli', 'cron', 'mcp'];

    public ?string $who;

    /**
     * @param ?string $who Current name substring or numeric person id
     * @param ?string $channel Accepted receipt channel
     * @param ?string $table Live or formerly journaled table name
     * @param ?DateTimeImmutable $since Inclusive UTC lower bound
     * @param ?DateTimeImmutable $until Exclusive UTC upper bound
     * @throws InvalidArgumentException When the channel is not one of the receipt channels
     */
    public function __construct(
        ?string $who = null,
        public ?string $channel = null,
        public ?string $table = null,
        public ?DateTimeImmutable $since = null,
        public ?DateTimeImmutable $until = null,
    ) {
        if ($channel !== null && !in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException("Unknown change log channel {$channel}");
        }
        $trimmed = $who === null ? null : trim($who);
        $this->who = $trimmed === '' ? null : $trimmed;
    }
}
