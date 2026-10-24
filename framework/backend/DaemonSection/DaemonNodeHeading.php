<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Cluster\NodeRole;
use Hilos\Constants\TimeConstants;

/** One node line for the node pages of the Daemon section. */
final readonly class DaemonNodeHeading
{
    public const string clustered = 'clustered';
    public const string state = 'state';
    public const string silentSince = 'silentSince';
    public const string role = 'role';

    /**
     * @param bool $clustered Whether the installation is clustered
     * @param ?DaemonNodeState $state State word from the cluster picture
     * @param ?int $silentSince Last report time in epoch milliseconds, only when the node is silent
     * @param ?NodeRole $role Declared role from the last node report
     */
    public function __construct(
        public bool $clustered,
        public ?DaemonNodeState $state,
        public ?int $silentSince,
        public ?NodeRole $role,
    ) {
    }

    /**
     * The state comes from the picture; a live node's frequent reports must not resend its page.
     *
     * @param ?ClusterDaemonPicture $picture Current page-agent mirror
     * @param string $nodeId Node to describe
     * @param bool $clustered Whether the installation is clustered
     * @return self Node line with the last report time only for silence
     */
    public static function of(?ClusterDaemonPicture $picture, string $nodeId, bool $clustered): self
    {
        $state = $picture?->stateOf($nodeId);
        $silentSince = $state === DaemonNodeState::Silent ? $picture?->node($nodeId)?->slot?->receivedAt : null;

        return new self(
            $clustered,
            $state,
            $silentSince === null ? null : $silentSince * TimeConstants::MS_PER_SECOND,
            $picture?->node($nodeId)?->slot?->picture->role,
        );
    }

    /** @return array{clustered: bool, state: ?string, silentSince: ?int, role: ?string} Browser node line */
    public function toArray(): array
    {
        return [
            self::clustered => $this->clustered,
            self::state => $this->state?->value,
            self::silentSince => $this->silentSince,
            self::role => $this->role?->value,
        ];
    }
}
