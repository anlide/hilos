<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Pages\Daemon\AbstractHilosDaemonAgentsPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonCronPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonHttpServerPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonWebsocketsPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonWorkersPage;

/** The Daemon section's six pages and three agents form one independent feature. */
final class DaemonFeature extends FeatureDefinition
{
    /** @return HilosFeature Daemon feature case */
    public function feature(): HilosFeature
    {
        return HilosFeature::DAEMON;
    }

    /** @return FeatureRequirements Pages and agent pairs required at startup */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredPages: [
                AbstractHilosDaemonPage::class,
                AbstractHilosDaemonWorkersPage::class,
                AbstractHilosDaemonAgentsPage::class,
                AbstractHilosDaemonCronPage::class,
                AbstractHilosDaemonWebsocketsPage::class,
                AbstractHilosDaemonHttpServerPage::class,
            ],
            requiredAgents: [
                HilosAgentType::HILOS_DAEMON,
                HilosAgentType::HILOS_DAEMON_NODE,
                HilosAgentType::HILOS_DAEMON_COLLECTOR,
            ],
        );
    }
}
