<?php

declare(strict_types=1);

namespace Hilos\Core\Source\Interest;

use Hilos\Core\Agent\AbstractAgent;

/**
 * What a reader of a collection is called inside one process.
 *
 * Interest belongs to a consumer and not to the process holding it, because a worker runs
 * several at once and the last one leaving is what ends the interest. So the three kinds that
 * can hold one need names that never collide, and the names are fixed here rather than at each
 * call site: they never reach the wire, but a refused read prints them, and a log that spells
 * the same agent two ways is a log nobody can grep.
 */
final class SourceConsumer
{
    /**
     * @param string $agentId Agent id as {@see AbstractAgent::getId()} builds it
     * @return string Consumer name of that agent
     */
    public static function agent(string $agentId): string
    {
        return 'agent:' . $agentId;
    }

    /** @var string What a page subscription's consumer name starts with */
    public const string PAGE_PREFIX = 'page:';

    /**
     * @param string $acceptKey Accept key of the subscribed connection
     * @return string Consumer name of that page subscription
     */
    public static function page(string $acceptKey): string
    {
        return self::PAGE_PREFIX . $acceptKey;
    }

    /**
     * Reads back the connection a page consumer name was built from.
     *
     * The other direction of {@see page()}, and here for the reason the names themselves are:
     * a caller holding a consumer name has to be able to reach the connection behind it without
     * knowing how the name was spelled. Anything that is not a page answers null — an agent and
     * a feature have no connection to address (HIL-711).
     *
     * @param string $consumerId Consumer name to read
     * @return ?string Accept key it names, or null when it does not name a page subscription
     */
    public static function acceptKeyOf(string $consumerId): ?string
    {
        if (!str_starts_with($consumerId, self::PAGE_PREFIX)) {
            return null;
        }

        return substr($consumerId, strlen(self::PAGE_PREFIX));
    }

    /**
     * @param string $name Feature or library name that mounted the reader
     * @return string Consumer name of that feature
     */
    public static function feature(string $name): string
    {
        return 'feature:' . $name;
    }
}
