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

    /**
     * @param string $acceptKey Accept key of the subscribed connection
     * @return string Consumer name of that page subscription
     */
    public static function page(string $acceptKey): string
    {
        return 'page:' . $acceptKey;
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
