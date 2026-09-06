<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceOwner;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Environment\NonProductionGate;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\TruthSource\RtTruthSourceRegistry;
use JsonException;

/**
 * Base for CLI commands that may only run outside production — state-manipulation
 * commands used by tests (create/delete an orphan row, fast-forward a deletion timer).
 *
 * Subclassing is the marker: a command that extends this is test-only by contract, so a
 * reader sees the parent and knows it must never run on prod. execute() is final so the
 * guard cannot be skipped; subclasses implement run().
 *
 * The verdict itself is not computed here: it belongs to {@see NonProductionGate}, which
 * the command socket asks the same question of, so a command refused in one process is
 * refused in the other for the same reason.
 *
 * It is also the runner of the ownership these commands declare. A command writes rows from a
 * process where no agent runs, so the collection it mutates has to be claimed by somebody:
 * {@see TruthSourceOwner::OWNS_DB} on the command class says which, and execute() lays the claim
 * down for the length of the body and takes it back after.
 */
abstract class TestOnlyCommand implements CommandInterface, TruthSourceOwner
{
    /**
     * @var string Truth-source id every test-only command claims under. One id and not one per
     *     command, because it names a single claimant - the process running a command body with
     *     no agent in it - and the same string takes the claim back, so two copies of it would
     *     have to be one anyway.
     */
    public const string TRUTH_SOURCE_ID = 'test-cli';

    /**
     * Operations the claims of a test-only command carry.
     *
     * The kind of a fixture writer: a row it seeds is a row it may edit and take away again, so
     * the answer is every operation there is - the same right {@see TruthSourceRegistry::register()}
     * gave these claims when they were made from inside the bodies.
     *
     * @return TruthSourceOperations Operations every claim of a test-only command gets
     */
    public static function defaultTruthSourceOperations(): TruthSourceOperations
    {
        return TruthSourceOperations::all();
    }

    /**
     * Refuses on a production-like or unset APP_ENV, claims what the command owns, then runs it.
     *
     * The claim is laid here and not in {@see CliManager::run()} for two reasons. The tests of
     * these commands construct one and call execute() directly, so a claim raised further out
     * never reaches them and the command is refused its own write inside its own test. And
     * CliManager runs commands whose process lives on afterwards, which would carry a claim
     * named for a command body into somebody else's tick.
     *
     * Two classes are claimed under one id: the command itself, and {@see Hilos::appClass()} for
     * the collection whose name only the project knows - the users table a fixture seeds. Both
     * halves of both, because the declaration has two and raising one would make a rule that
     * lies about its own reach.
     *
     * Taking the claim back is a finally and not a line after the body, because the body throws:
     * a command refused by its own input must not leave an owner behind in a process where the
     * next test runs. It is taken back the way a stopping agent takes its own back
     * ({@see WorkerManager}).
     *
     * @param array<string, mixed> $options Parsed options (--key=value or --flag)
     * @param list<string> $args Positional arguments
     * @return int Exit code (0 = success)
     * @throws TestOnlyCommandOnProductionException When APP_ENV is production-like or unset
     * @throws HilosException When the command body refuses its input or its work fails
     * @throws JsonException When the command cannot encode its output
     */
    final public function execute(array $options, array $args): int
    {
        if (!NonProductionGate::admitted()) {
            throw new TestOnlyCommandOnProductionException($this->getName());
        }

        OwnershipDeclaration::claimDb(static::class, self::TRUTH_SOURCE_ID);
        OwnershipDeclaration::claimRt(static::class, self::TRUTH_SOURCE_ID);
        OwnershipDeclaration::claimDb(Hilos::appClass(), self::TRUTH_SOURCE_ID);
        OwnershipDeclaration::claimRt(Hilos::appClass(), self::TRUTH_SOURCE_ID);

        try {
            return $this->run($options, $args);
        } finally {
            TruthSourceRegistry::unregisterAgent(self::TRUTH_SOURCE_ID);
            RtTruthSourceRegistry::unregisterAgent(self::TRUTH_SOURCE_ID);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::TRUTH_SOURCE_ID));
        }
    }

    /**
     * Command body, run only after the non-production guard passes.
     *
     * @param array<string, mixed> $options Parsed options (--key=value or --flag)
     * @param list<string> $args Positional arguments
     * @return int Exit code (0 = success)
     * @throws HilosException When the command body refuses its input or its work fails
     * @throws JsonException When the command cannot encode its output
     */
    abstract protected function run(array $options, array $args): int;
}
