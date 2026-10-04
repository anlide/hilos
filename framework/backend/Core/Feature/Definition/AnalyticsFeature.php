<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Analytics\AnalyticsSettingsCatalog;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Fs\Context\FsContext;

/**
 * Analytics through node journals (HIL-1154): the collector in every process, the journal agent of
 * each node and the cluster writer.
 *
 * The three parts come together or not at all. A collector without the journal agent hands its
 * batches to nobody; a journal without the writer fills the disk and never reaches a table. The
 * framework starts the collector itself in every process of a project that declares the feature
 * ({@see AnalyticsCollector}), and the start refuses the project that registers no
 * {@see FsContext::ANALYTICS_JOURNAL} directory and a catalog with
 * {@see AnalyticsSettingsCatalog}, so the administrator can set the journal ceiling.
 */
final class AnalyticsFeature extends FeatureDefinition
{
    /**
     * @return HilosFeature Analytics feature case
     */
    public function feature(): HilosFeature
    {
        return HilosFeature::ANALYTICS;
    }

    /**
     * @return FeatureRequirements Both journal agents and the ceiling setting fragment
     */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredAgents: [
                HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosAgentType::HILOS_ANALYTICS_WRITER,
            ],
            requiredCatalogFragments: [AnalyticsSettingsCatalog::class],
        );
    }
}
