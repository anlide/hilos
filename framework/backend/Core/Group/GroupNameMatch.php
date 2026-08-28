<?php

declare(strict_types=1);

namespace Hilos\Core\Group;

use Hilos\Hilos;

/**
 * GroupNameMatch - the registered class behind a group name on the wire, and the param it carried.
 *
 * A group class declares its name WITHOUT a param (`hilos_notifications`), because the
 * registry ({@see Hilos::GROUPS}) is keyed by exact name and the name of a per-entity group
 * is not known until there is an entity. On the wire the param travels after a colon
 * (`hilos_notifications:42`), so resolution is: exact name first, then the head of the name
 * up to the first colon. Both the master, resolving which agent owns the join, and the
 * worker, resolving which class judges it, read the name through here - one rule in one
 * place, because two copies of it would eventually disagree about who serves what.
 */
final class GroupNameMatch
{
    /**
     * @param class-string<AbstractGroup> $groupClass Registered class answering the name
     * @param ?string $param Param the name carried after the colon, or null when it carried none
     */
    private function __construct(
        public readonly string $groupClass,
        public readonly ?string $param,
    ) {
    }

    /**
     * Resolves a name off the wire against a group registry.
     *
     * @param string $group Group name as the client sent it
     * @param array<string, class-string<AbstractGroup>> $groupClasses Registered group classes keyed by declared name
     * @return ?self Match, or null when no registered class answers this name
     */
    public static function resolve(string $group, array $groupClasses): ?self
    {
        $exact = $groupClasses[$group] ?? null;
        if ($exact !== null) {
            return new self($exact, null);
        }

        $colon = strpos($group, ':');
        if ($colon === false) {
            return null;
        }

        $head = substr($group, 0, $colon);
        $byHead = $groupClasses[$head] ?? null;
        if ($byHead === null) {
            return null;
        }

        return new self($byHead, substr($group, $colon + 1));
    }
}
