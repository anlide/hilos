<?php

declare(strict_types=1);

namespace Hilos\Core\Feature;

use Hilos\Core\Feature\Definition\AuthThrottleFeature;
use Hilos\Core\Feature\Definition\CodeChannelsFeature;
use Hilos\Core\Feature\Definition\BackupFeature;
use Hilos\Core\Feature\Definition\HilosUsersFeature;
use Hilos\Core\Feature\Definition\LogsFeature;
use Hilos\Core\Feature\Definition\NotificationDeliveryFeature;
use Hilos\Core\Feature\Definition\NotificationsFeature;
use Hilos\Core\Feature\Definition\SettingsFeature;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Hilos;

/**
 * Every framework feature and the definition that describes it.
 *
 * Built through {@see Hilos::createFeatureRegistry()} rather than reached statically, so a
 * test can hand the validator and the runtime context a registry of synthetic features and
 * exercise them without touching the real six.
 *
 * The map is exhaustive by construction: a case added to {@see HilosFeature} without an entry
 * here has no requirements to check and no state to mount, and would activate silently - the
 * failure this registry exists to prevent.
 */
class FeatureRegistry
{
    /** @var array<string, FeatureDefinition> Definitions keyed by feature value */
    private array $definitions;

    /**
     * Indexes the definitions by feature so lookups do not walk the list.
     */
    public function __construct()
    {
        $this->definitions = [];
        foreach ($this->buildDefinitions() as $definition) {
            $this->definitions[$definition->feature()->value] = $definition;
        }
    }

    /**
     * Returns the definition of one feature.
     *
     * @param HilosFeature $feature Feature to describe
     * @return FeatureDefinition Definition of that feature
     * @throws IncompleteFeatureActivationException When the registry carries no definition for the feature
     */
    public function definition(HilosFeature $feature): FeatureDefinition
    {
        return $this->definitions[$feature->value]
            ?? throw IncompleteFeatureActivationException::forErrors(
                Hilos::appClass(),
                ["feature {$feature->value} is declared but " . static::class . ' defines it nowhere'],
            );
    }

    /**
     * Returns every definition the registry carries.
     *
     * The activation validator walks all of them, not only the declared ones: the check that
     * catches a half-flipped switch is the reverse one - a feature's page or table registered
     * while the feature itself is not declared - and that question can only be asked of the
     * features a project did not take.
     *
     * @return list<FeatureDefinition> Definitions in build order
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /**
     * Creates the definition of every framework feature.
     *
     * Override to replace the set in a test; a subclass that returns fewer definitions
     * than {@see HilosFeature} has cases must also declare fewer features on its facade.
     *
     * @return list<FeatureDefinition> One definition per feature case
     */
    protected function buildDefinitions(): array
    {
        return [
            new SettingsFeature(),
            new HilosUsersFeature(),
            new BackupFeature(),
            new LogsFeature(),
            new NotificationsFeature(),
            new NotificationDeliveryFeature(),
            new AuthThrottleFeature(),
            new CodeChannelsFeature(),
        ];
    }
}
