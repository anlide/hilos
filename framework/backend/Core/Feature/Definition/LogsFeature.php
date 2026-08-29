<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Log\LogRotationTriggerPolicy;
use Hilos\Pages\Logs\AbstractHilosLogsKeysPage;
use Hilos\Pages\Logs\AbstractHilosLogsPage;
use Hilos\Pages\Logs\AbstractHilosLogsRotationsPage;
use Hilos\Pages\Logs\AbstractHilosLogsViewPage;
use Hilos\Pages\Logs\AbstractHilosLogsWorkersPage;

/**
 * Log archive admin pages with the log overview and rotation agents.
 *
 * All five pages are required together: they are one navigation - archive, keys, workers,
 * rotations and the viewer - and any one of them missing lands the operator on a dead link
 * rather than on a feature he can tell is absent.
 *
 * Whether rotation actually runs stays an env switch inside the feature
 * ({@see LogRotationTriggerPolicy}) and is not required here: the registry answers what the
 * project is built with, deployment answers what is turned on at this installation.
 */
final class LogsFeature extends FeatureDefinition
{
    /**
     * @return HilosFeature Logs feature case
     */
    public function feature(): HilosFeature
    {
        return HilosFeature::LOGS;
    }

    /**
     * @return FeatureRequirements The five log pages and the overview, rotation, store and aggregator agents
     */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredPages: [
                AbstractHilosLogsPage::class,
                AbstractHilosLogsKeysPage::class,
                AbstractHilosLogsWorkersPage::class,
                AbstractHilosLogsRotationsPage::class,
                AbstractHilosLogsViewPage::class,
            ],
            requiredAgents: [
                HilosAgentType::HILOS_LOGS,
                HilosAgentType::HILOS_LOG_ROTATION,
                HilosAgentType::HILOS_LOG_STORE,
                HilosAgentType::HILOS_LOG_AGGREGATOR,
            ],
        );
    }
}
