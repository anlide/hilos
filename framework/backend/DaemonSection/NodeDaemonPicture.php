<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Cluster\NodeRole;

/** One node's complete Daemon picture, as measured by its own agent. */
final class NodeDaemonPicture
{
    /**
     * @param string $nodeId Origin node
     * @param NodeRole $role Node's declared role
     * @param int $sampledAt Measurement time
     * @param ?DaemonProcessRoster $processes Master's last roster, if received
     * @param ?DaemonCronPicture $cron Cron section, if the master has reported its rules
     * @param ?NodeEnvironmentSummary $environment Environment counts, null before the first read
     * @param ?DaemonNodeStanding $standing Master's standing, null before the first frame
     * @param ?DaemonHttpPicture $http Master's HTTP section, null before the first frame
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly NodeRole $role,
        public readonly int $sampledAt,
        public readonly ?DaemonProcessRoster $processes = null,
        public readonly ?DaemonCronPicture $cron = null,
        public readonly ?NodeEnvironmentSummary $environment = null,
        public readonly ?DaemonNodeStanding $standing = null,
        public readonly ?DaemonHttpPicture $http = null,
    ) {
    }

    /**
     * @param int $sampledAt New measurement time
     * @return self The same content measured at another instant
     */
    public function sampledAt(int $sampledAt): self
    {
        return new self($this->nodeId, $this->role, $sampledAt, $this->processes, $this->cron, $this->environment, $this->standing, $this->http);
    }

    /**
     * @param self $other Picture to compare
     * @return bool Whether the content, apart from its measurement time, matches
     */
    public function sameContent(self $other): bool
    {
        return $this->nodeId === $other->nodeId
            && $this->role === $other->role
            && $this->processes == $other->processes
            && $this->cron == $other->cron
            && $this->environment == $other->environment
            && $this->standing == $other->standing
            && $this->http == $other->http;
    }

    /**
     * @param ?DaemonProcessRoster $processes New master roster
     * @return self Picture preserving every other section
     */
    public function withProcesses(?DaemonProcessRoster $processes): self
    {
        return new self($this->nodeId, $this->role, $this->sampledAt, $processes, $this->cron, $this->environment, $this->standing, $this->http);
    }

    /**
     * @param ?DaemonCronPicture $cron New cron section
     * @return self Picture preserving every other section
     */
    public function withCron(?DaemonCronPicture $cron): self
    {
        return new self($this->nodeId, $this->role, $this->sampledAt, $this->processes, $cron, $this->environment, $this->standing, $this->http);
    }

    /**
     * @param ?NodeEnvironmentSummary $environment New environment counts
     * @return self Picture preserving every other section
     */
    public function withEnvironment(?NodeEnvironmentSummary $environment): self
    {
        return new self($this->nodeId, $this->role, $this->sampledAt, $this->processes, $this->cron, $environment, $this->standing, $this->http);
    }

    /**
     * @param ?DaemonNodeStanding $standing New master standing
     * @return self Picture preserving every other section
     */
    public function withStanding(?DaemonNodeStanding $standing): self
    {
        return new self($this->nodeId, $this->role, $this->sampledAt, $this->processes, $this->cron, $this->environment, $standing, $this->http);
    }

    /**
     * @param ?DaemonHttpPicture $http New master HTTP section
     * @return self Picture preserving every other section
     */
    public function withHttp(?DaemonHttpPicture $http): self
    {
        return new self($this->nodeId, $this->role, $this->sampledAt, $this->processes, $this->cron, $this->environment, $this->standing, $http);
    }
}
