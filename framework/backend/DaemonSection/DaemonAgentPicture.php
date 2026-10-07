<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Core\Exception\InvalidFormatException;

/** One started agent in a live worker's process roster. */
final readonly class DaemonAgentPicture
{
    public const string SCOPE_NODE = 'node';
    public const string SCOPE_CLUSTER = 'cluster';
    public const string PLACEMENT_NODE = 'node';
    public const string PLACEMENT_LEADER = 'leader';
    public const string PLACEMENT_POLICY = 'policy';

    /**
     * @param string $id Non-empty agent id
     * @param string $scope Node or cluster scope
     * @param string $placement Placement consistent with scope
     * @throws InvalidFormatException When identity, scope, or placement is invalid
     */
    public function __construct(
        public string $id,
        public string $scope,
        public string $placement,
    ) {
        if ($id === '' || !in_array($scope, [self::SCOPE_NODE, self::SCOPE_CLUSTER], true)) {
            throw new InvalidFormatException('Daemon agent picture carries an invalid id or scope');
        }
        if (
            ($scope === self::SCOPE_NODE && $placement !== self::PLACEMENT_NODE)
            || ($scope === self::SCOPE_CLUSTER && !in_array($placement, [self::PLACEMENT_LEADER, self::PLACEMENT_POLICY], true))
        ) {
            throw new InvalidFormatException('Daemon agent picture carries a placement inconsistent with its scope');
        }
    }
}
