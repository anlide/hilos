<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

/** One node line for the node pages of the Daemon section. */
final readonly class DaemonNodeHeading
{
    public const string clustered = 'clustered';
    public const string state = 'state';
    public const string silentSince = 'silentSince';

    /**
     * @param bool $clustered Whether the installation is clustered
     * @param ?DaemonNodeState $state State word from the cluster picture
     * @param ?int $silentSince Last report time only when the node is silent
     */
    public function __construct(
        public bool $clustered,
        public ?DaemonNodeState $state,
        public ?int $silentSince,
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

        return new self($clustered, $state, $silentSince);
    }

    /** @return array{clustered: bool, state: ?string, silentSince: ?int} Browser node line */
    public function toArray(): array
    {
        return [
            self::clustered => $this->clustered,
            self::state => $this->state?->value,
            self::silentSince => $this->silentSince,
        ];
    }
}
