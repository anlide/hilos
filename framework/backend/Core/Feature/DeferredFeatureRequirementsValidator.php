<?php

declare(strict_types=1);

namespace Hilos\Core\Feature;

use Hilos\Core\CLI\CliApplication;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Hilos;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\View\Collection\HilosPresenceSource;
use Hilos\Runtime\View\Context\RtContext;

/**
 * Validates the half of a feature's requirements that startup cannot see.
 *
 * {@see FeatureActivationValidator} reads facade constants and therefore runs at startup. The
 * requirements collected here cannot: a migration is applied as a separate step, so gating boot
 * on it would fail every process that starts before the migration run; the CLI command set and
 * the runtime collections only exist once a manager or a context has been built, which is later
 * than the constants check and not on every process's path at all.
 *
 * So they are checked by each project's own unit test instead - the place where the whole
 * project layout is knowable at once and nothing is running. The three inputs the validator
 * takes are exactly the facts the facade does not own: where its migrations live, which CLI
 * manager its entry point passes to {@see CliApplication}, and which runtime context it builds.
 *
 * Missing here is as bad as missing at startup, only quieter: a project that declared
 * NOTIFICATIONS without migrating the preference table fails on the first send rather than at
 * boot, which is the half-activated state the whole registry exists to remove.
 *
 * One invariant checked here belongs to no feature (HIL-509): a project whose PAGES are not empty
 * accepts browser subscriptions, and therefore has WebSocket connections, and therefore must keep
 * them on the framework connection base. It is not in the registry because it is not optional and
 * has nothing to declare - the non-empty PAGES is the declaration. A headless project (demo
 * cluster, PAGES = []) legitimately has no connections and is not asked for any.
 */
final class DeferredFeatureRequirementsValidator
{
    /** @var string Migration file suffix marking the rollback half of a pair */
    private const string DOWN_SUFFIX = '_down.sql';

    /**
     * @param FeatureRegistry $registry Definitions to validate the declaration against
     */
    public function __construct(private readonly FeatureRegistry $registry)
    {
    }

    /**
     * Validates the deferred requirements of every feature a facade declares.
     *
     * @param class-string<Hilos> $hilosClass Project facade class
     * @param string $migrationsPath Directory holding the project's schema migrations
     * @param class-string<CliManager> $cliManagerClass CLI manager the project's entry point runs
     * @param ?class-string<RtContext> $rtContextClass Runtime context the project builds, or null when it builds none
     * @throws IncompleteFeatureActivationException When a declared feature misses a table, a command or a presence
     *     source, or when a project that serves pages keeps its connections off the framework base
     * @throws LogicException When the PCRE engine refuses to strip a migration file's comments
     * @throws StateCollectionNotFoundException When building the runtime context represents an unmounted collection
     */
    public function validate(
        string $hilosClass,
        string $migrationsPath,
        string $cliManagerClass,
        ?string $rtContextClass,
    ): void {
        $errors = [];
        $createdTables = null;
        $cliManager = null;
        $definitions = array_map(
            fn(HilosFeature $feature): FeatureDefinition => $this->registry->definition($feature),
            $this->declaredFeatures($hilosClass),
        );
        $context = $this->needsRuntimeContext($hilosClass, $definitions)
            ? $this->buildRuntimeContext($rtContextClass, $definitions)
            : null;

        foreach ($definitions as $definition) {
            $requirements = $definition->requirements();
            $name = 'HilosFeature::' . $definition->feature()->name;

            foreach ($requirements->requiredDbTables as $table) {
                $createdTables ??= $this->createdTables($migrationsPath);
                if (!in_array($table, $createdTables, true)) {
                    $errors[] = "{$name} is declared but no migration in {$migrationsPath} creates table {$table}";
                }
            }

            foreach ($requirements->requiredCliCommands as $command) {
                $cliManager ??= new $cliManagerClass([]);
                if (!$cliManager->hasCommand($command)) {
                    $errors[] = "{$name} is declared but {$cliManagerClass} registers no {$command} command";
                }
            }

            if ($requirements->requiresPresenceSource && $context?->presenceSource() === null) {
                $errors[] = "{$name} is declared but no runtime collection of "
                    . ($rtContextClass ?? 'a project without a runtime context')
                    . ' implements ' . HilosPresenceSource::class;
            }
        }

        if ($hilosClass::PAGES !== [] && $context?->connectionsSource() === null) {
            $errors[] = 'PAGES is not empty but no runtime state collection of '
                . ($rtContextClass ?? 'a project without a runtime context')
                . ' extends ' . HilosConnections::class;
        }

        if ($errors !== []) {
            throw IncompleteFeatureActivationException::forErrors($hilosClass, $errors);
        }
    }

    /**
     * Tells whether anything checked here needs the project's runtime context built.
     *
     * Building it runs the project's whole `configure()`, so it is done once and only when
     * something asks: a project that declares no pages and no feature wanting a presence source
     * has nothing here to answer with it.
     *
     * @param class-string<Hilos> $hilosClass Project facade class
     * @param list<FeatureDefinition> $definitions Definitions of the declared features
     * @return bool True when a check below reads the built context
     */
    private function needsRuntimeContext(string $hilosClass, array $definitions): bool
    {
        if ($hilosClass::PAGES !== []) {
            return true;
        }

        return array_filter(
            $definitions,
            static fn(FeatureDefinition $definition): bool => $definition->requirements()->requiresPresenceSource,
        ) !== [];
    }

    /**
     * Reads the features a facade declared, skipping anything malformed.
     *
     * Read through {@see Hilos::featuresOf()} for the same reason {@see FeatureActivationValidator}
     * does it: the declaration is a protected constant on a class that is not the running facade
     * here. An entry that is not a case is not this validator's error to report - the startup one
     * names it precisely, and repeating it would double every message a project sees for one
     * mistake.
     *
     * @param class-string<Hilos> $hilosClass Project facade class
     * @return list<HilosFeature> Declared features
     */
    private function declaredFeatures(string $hilosClass): array
    {
        return array_values(array_filter(
            Hilos::featuresOf($hilosClass),
            static fn(mixed $feature): bool => $feature instanceof HilosFeature,
        ));
    }

    /**
     * Reads the SQL tables a project's migrations create.
     *
     * The forward direction only: a table this project's schema brings into being. Rollback
     * halves are skipped so a `DROP TABLE` never reads as an activation, and the statement is
     * matched rather than the file name because what a migration is called says nothing about
     * what it creates.
     *
     * Line comments are cut before matching. Every migration in this repository opens with a
     * prose header explaining what it activates, so a sentence naming the statement - or a
     * statement commented out while it was being reworked - would otherwise satisfy the very
     * check whose whole worth is that it cannot be satisfied by an intention.
     *
     * @param string $migrationsPath Directory holding the project's schema migrations
     * @return list<string> Table names created by the migrations
     * @throws LogicException When the PCRE engine refuses to strip a migration file's comments
     */
    private function createdTables(string $migrationsPath): array
    {
        $tables = [];
        foreach (glob(rtrim($migrationsPath, '/') . '/*.sql') ?: [] as $file) {
            if (str_ends_with($file, self::DOWN_SUFFIX)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                continue;
            }

            // A null here is the PCRE engine refusing, not a file with nothing left after
            // its comments. Read as an empty statement list it would say "these migrations
            // create no table at all" — the one answer this validator must never invent,
            // since its whole worth is that an intention cannot satisfy it.
            $statements = preg_replace('/--[^\n]*/', '', $sql);
            if ($statements === null) {
                throw new LogicException(
                    "Cannot strip the comments of migration file {$file}: " . preg_last_error_msg(),
                );
            }

            preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $statements, $matches);
            foreach ($matches[1] as $table) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * Builds the project's runtime context the way the facade does.
     *
     * The context is built here rather than read off {@see Hilos::$rt} because the check runs in
     * a unit test, where no facade has been initialized; mounting the feature runtime first keeps
     * the built context the same shape init() gives it, so a collection a feature brought counts
     * exactly as a project's own does.
     *
     * @param ?class-string<RtContext> $rtContextClass Runtime context the project builds, or null when it builds none
     * @param list<FeatureDefinition> $definitions Definitions of the declared features
     * @return ?RtContext Built runtime context, or null when the project builds none
     * @throws StateCollectionNotFoundException When the context represents a collection it did not mount
     */
    private function buildRuntimeContext(?string $rtContextClass, array $definitions): ?RtContext
    {
        if ($rtContextClass === null) {
            return null;
        }

        $context = new $rtContextClass();
        $context->mountFeatureRuntime($definitions);
        $context->configure();

        return $context;
    }
}
