<?php

declare(strict_types=1);

namespace Hilos\Core\Feature;

use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Topology\TopologyValidator;
use Hilos\Hilos;

/**
 * Validates that the features a project declared are the features it actually registered.
 *
 * The companion of {@see TopologyValidator}: that one asks whether the registries are
 * internally consistent, this one asks whether they agree with {@see Hilos::FEATURES}. It runs
 * at startup and refuses to boot on a gap, because the failure it is here to catch is a silent
 * one - a feature declared with its page never registered shows up as an empty admin screen or
 * a missing agent hours later, and reads as a bug rather than as a step somebody skipped.
 *
 * Both directions are checked. Forward, a declared feature must have everything it needs.
 * Backward, a feature's page or table registered while the feature is not declared is just as
 * broken: the registries then say the feature is on and the declaration says it is off, which
 * is exactly the half-flipped switch the declaration was introduced to remove.
 *
 * Constants are all it reads: the validator runs before `$db` and `$rt` exist. Migrations
 * deliberately stay out of it - they are applied as a separate step, and gating startup on them
 * would fail every process that starts before the migration run, so the SQL tables a feature
 * needs are checked by each project's own unit test instead.
 */
final class FeatureActivationValidator
{
    /**
     * @param FeatureRegistry $registry Definitions to validate the declaration against
     */
    public function __construct(private readonly FeatureRegistry $registry)
    {
    }

    /**
     * Validates the feature declaration of a Hilos facade subclass.
     *
     * @param class-string<Hilos> $hilosClass Project facade class
     * @throws IncompleteFeatureActivationException When a declared feature is incomplete or an undeclared one is registered
     */
    public function validate(string $hilosClass): void
    {
        $errors = [];
        $declared = $this->declaredFeatures($hilosClass, $errors);
        $pages = $this->constantArray($hilosClass, 'PAGES');
        $agents = $this->constantArray($hilosClass, 'AGENTS');
        $tables = $this->constantArray($hilosClass, 'TABLES');
        $pageTables = $this->constantArray($hilosClass, 'PAGE_TABLES');

        $described = [];
        foreach ($this->registry->all() as $definition) {
            $feature = $definition->feature();
            $described[] = $feature;

            if (in_array($feature, $declared, true)) {
                $this->validateDeclared(
                    $hilosClass,
                    $feature,
                    $definition->requirements(),
                    $declared,
                    $pages,
                    $agents,
                    $tables,
                    $pageTables,
                    $errors,
                );
                continue;
            }

            $this->validateUndeclared($hilosClass, $feature, $definition->requirements(), $pages, $agents, $tables, $errors);
        }

        foreach ($declared as $feature) {
            if (!in_array($feature, $described, true)) {
                $errors[] = $this->name($feature) . ' is declared but no feature definition describes it';
            }
        }

        if ($errors !== []) {
            throw IncompleteFeatureActivationException::forErrors($hilosClass, $errors);
        }
    }

    /**
     * Reads the features a facade declared.
     *
     * Read through {@see Hilos::featuresOf()} because the declaration is a protected constant:
     * it is a statement the project makes to the framework, not API for callers, and only the
     * facade itself can read it off a named class. The validator is the one place that must,
     * since it validates a class before that class is the running one.
     *
     * @param class-string<Hilos> $hilosClass Project facade class
     * @param list<string> $errors Activation error accumulator
     * @return list<HilosFeature> Declared features, empty when the declaration is unusable
     */
    private function declaredFeatures(string $hilosClass, array &$errors): array
    {
        $features = [];
        foreach (Hilos::featuresOf($hilosClass) as $feature) {
            if (!$feature instanceof HilosFeature) {
                $errors[] = 'FEATURES must contain only ' . HilosFeature::class . ' cases';
                continue;
            }

            $features[] = $feature;
        }

        return $features;
    }

    /**
     * Validates that a declared feature has everything the project owes it.
     *
     * @param class-string<Hilos> $hilosClass Project facade class
     * @param HilosFeature $feature Declared feature
     * @param FeatureRequirements $requirements What the feature obliges the project to register
     * @param list<HilosFeature> $declared Every declared feature
     * @param array $pages Page registry
     * @param array $agents Agent registry
     * @param array $tables Registered table registry
     * @param array $pageTables Page table binding registry
     * @param list<string> $errors Activation error accumulator
     */
    private function validateDeclared(
        string $hilosClass,
        HilosFeature $feature,
        FeatureRequirements $requirements,
        array $declared,
        array $pages,
        array $agents,
        array $tables,
        array $pageTables,
        array &$errors,
    ): void {
        $name = $this->name($feature);

        foreach ($requirements->requiredPages as $pageClass) {
            if ($this->registeredExtending($pages, $pageClass) === null) {
                $errors[] = "{$name} is declared but no page in PAGES extends {$pageClass}";
            }
        }

        // Both lists in one walk: what a feature needs of an agent is the same whether or not
        // it is the only one that needs it - the two differ in the check above, not here.
        foreach ([...$requirements->requiredAgents, ...$requirements->requiredSharedAgents] as $agentType) {
            $registryEntry = $agents[$agentType] ?? null;
            if ($registryEntry === null) {
                $errors[] = "{$name} is declared but agent {$agentType} is not registered in AGENTS";
                continue;
            }

            if (AgentRegistry::workerClass($registryEntry) === null || AgentRegistry::daemonClass($registryEntry) === null) {
                $errors[] = "{$name} is declared but AGENTS[{$agentType}] does not declare both a worker and a daemon class";
            }
        }

        foreach ($requirements->requiredTables as $tableClass) {
            if ($this->registeredExtending($tables, $tableClass) === null) {
                $errors[] = "{$name} is declared but no table in TABLES extends {$tableClass}";
            }
        }

        foreach ($requirements->requiredPageTables as $pageClass => $tableClass) {
            $this->validatePageTableBinding($name, $pageClass, $tableClass, $pages, $tables, $pageTables, $errors);
        }

        if ($requirements->requiredCatalogConstant !== null
            && !$this->pointsAtProjectCatalog($hilosClass, $requirements->requiredCatalogConstant)
        ) {
            $errors[] = "{$name} is declared but {$requirements->requiredCatalogConstant} still holds the framework default";
        }

        foreach ($requirements->requires as $required) {
            if (!in_array($required, $declared, true)) {
                $errors[] = "{$name} is declared but the {$this->name($required)} it is built on is not";
            }
        }
    }

    /**
     * Validates that an undeclared feature left no trace in the registries.
     *
     * Only the registries and catalog constants answer here. Migrations never do: the cluster
     * demo carries a historical `hilos_settings` migration without the settings feature, and
     * reading migrations would fail it for a table nobody uses.
     *
     * @param class-string<Hilos> $hilosClass Project facade class
     * @param HilosFeature $feature Undeclared feature
     * @param FeatureRequirements $requirements What the feature would oblige the project to register
     * @param array $pages Page registry
     * @param array $agents Agent registry
     * @param array $tables Registered table registry
     * @param list<string> $errors Activation error accumulator
     */
    private function validateUndeclared(
        string $hilosClass,
        HilosFeature $feature,
        FeatureRequirements $requirements,
        array $pages,
        array $agents,
        array $tables,
        array &$errors,
    ): void {
        $name = $this->name($feature);

        foreach ($requirements->requiredPages as $pageClass) {
            $registered = $this->registeredExtending($pages, $pageClass);
            if ($registered !== null) {
                $errors[] = "PAGES registers {$registered} but {$name} is not declared in FEATURES";
            }
        }

        // Only the agents this feature OWNS are read backwards: a shared one says nothing
        // about the feature, and a project entitled to run it would be refused for doing so.
        foreach ($requirements->requiredAgents as $agentType) {
            if (array_key_exists($agentType, $agents)) {
                $errors[] = "AGENTS registers {$agentType} but {$name} is not declared in FEATURES";
            }
        }

        foreach ($requirements->requiredTables as $tableClass) {
            $registered = $this->registeredExtending($tables, $tableClass);
            if ($registered !== null) {
                $errors[] = "TABLES registers {$registered} but {$name} is not declared in FEATURES";
            }
        }

        if ($requirements->requiredCatalogConstant !== null
            && $this->pointsAtProjectCatalog($hilosClass, $requirements->requiredCatalogConstant)
        ) {
            $errors[] = "{$requirements->requiredCatalogConstant} points at a project catalog but {$name} is not declared in FEATURES";
        }
    }

    /**
     * Validates that the page a feature owns is bound to the table it reads.
     *
     * A binding on any page extending the required base satisfies the requirement: a project
     * registers one such page, and which key it registered it under is its own business. A null
     * target means the page must be bound to something without the framework naming it - the
     * user detail page is bound to the project's own user table, and no framework class exists
     * to name there.
     *
     * @param string $name Feature name for error messages
     * @param class-string<AbstractPage> $pageClass Framework page base class the binding belongs to
     * @param ?class-string<TableDefinition> $tableClass Framework table class the page must be bound to, or null for any binding
     * @param array $pages Page registry
     * @param array $tables Registered table registry
     * @param array $pageTables Page table binding registry
     * @param list<string> $errors Activation error accumulator
     */
    private function validatePageTableBinding(
        string $name,
        string $pageClass,
        ?string $tableClass,
        array $pages,
        array $tables,
        array $pageTables,
        array &$errors,
    ): void {
        foreach ($pages as $page => $registeredClass) {
            if (!is_string($page) || !is_string($registeredClass) || !is_a($registeredClass, $pageClass, true)) {
                continue;
            }

            $bindings = $pageTables[$page] ?? null;
            if (!is_array($bindings) || $bindings === []) {
                continue;
            }

            if ($tableClass === null) {
                return;
            }

            foreach (array_keys($bindings) as $table) {
                $boundClass = is_string($table) ? $tables[$table] ?? null : null;
                if (is_string($boundClass) && is_a($boundClass, $tableClass, true)) {
                    return;
                }
            }
        }

        $target = $tableClass ?? 'a table';
        $errors[] = "{$name} is declared but PAGE_TABLES binds no page extending {$pageClass} to {$target}";
    }

    /**
     * Tells whether a catalog constant was pointed away from the framework default.
     *
     * The framework default is what "not activated" looks like for a catalog: an empty stub, an
     * empty registry base or null. Comparing against it rather than against emptiness keeps the
     * check honest for all three shapes at once, and a project that points the constant back at
     * the framework default really has switched the feature off.
     *
     * Both sides are read through {@see Hilos::catalogConstantOf()}: catalog constants are
     * protected, and a name no facade declares comes back null on both sides, so it compares
     * equal and reports as "the framework default" rather than throwing.
     *
     * @param class-string<Hilos> $hilosClass Project facade class
     * @param string $constant Catalog constant name
     * @return bool True when the project declared its own catalog
     */
    private function pointsAtProjectCatalog(string $hilosClass, string $constant): bool
    {
        return Hilos::catalogConstantOf($hilosClass, $constant)
            !== Hilos::catalogConstantOf(Hilos::class, $constant);
    }

    /**
     * Returns the first registered class that extends a framework base class.
     *
     * @param array $registry Registry whose values are class strings
     * @param string $baseClass Framework base class to look for
     * @return ?string Registered class extending the base, or null when none does
     */
    private function registeredExtending(array $registry, string $baseClass): ?string
    {
        foreach ($registry as $class) {
            if (is_string($class) && is_a($class, $baseClass, true)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Reads an array topology constant from a facade class.
     *
     * @param class-string<Hilos> $hilosClass Project facade class
     * @param string $constant Constant name
     * @return array Constant value, or an empty array when it is not an array
     */
    private function constantArray(string $hilosClass, string $constant): array
    {
        $value = constant("{$hilosClass}::{$constant}");

        return is_array($value) ? $value : [];
    }

    /**
     * Names a feature the way it is written in a project facade.
     *
     * @param HilosFeature $feature Feature to name
     * @return string Feature case as it appears in FEATURES
     */
    private function name(HilosFeature $feature): string
    {
        return 'HilosFeature::' . $feature->name;
    }
}
