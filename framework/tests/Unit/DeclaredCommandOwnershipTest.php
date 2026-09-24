<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandExecution;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\HilosException;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ownership declared by a claimant that is not an agent.
 *
 * The runner of a test-only command lays the claim its class declares and takes it back after,
 * so a command writes rows in a process where no agent runs. The sibling of
 * {@see DeclaredDbOwnershipTest} and {@see DeclaredRtOwnershipTest} for that claimant.
 *
 * The claim standing DURING the body is the half a test outside the body cannot see, so the
 * fixture commands ask the registries themselves and leave the answers behind.
 */
final class DeclaredCommandOwnershipTest extends TestCase
{
    /** @var string|false APP_ENV the suite runs under, put back so this file does not decide what the next one reads */
    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->previousAppEnv = getenv('APP_ENV');
        putenv('APP_ENV=test');
        // Readiness answers truthfully only for a process that waits for what it is sent; a
        // process that is its own source calls every collection ready.
        SourceInterestRegistry::readsWhatIsDelivered();
        DeclaredCommandOwnershipTestCommand::$seenInsideBody = [];
    }

    protected function tearDown(): void
    {
        SourceInterestRegistry::readsWhatItMounts();
        TruthSourceRegistry::unregisterAgent(TestOnlyCommand::TRUTH_SOURCE_ID);
        RtTruthSourceRegistry::unregisterAgent(TestOnlyCommand::TRUTH_SOURCE_ID);
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(TestOnlyCommand::TRUTH_SOURCE_ID));
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);

        parent::tearDown();
    }

    /**
     * The claim stands before the body runs, over both halves, and the interest it raises is
     * ready - the body's first write is what it exists for, and its first read comes with it.
     *
     * @throws HilosException When the fixture body refuses
     */
    public function testTheClaimStandsInsideTheBodyOverBothHalves(): void
    {
        new DeclaredCommandOwnershipTestCommand()->execute([], []);

        $this->assertSame(
            [
                'db-write-allowed' => true,
                'db-ready' => true,
                'rt-owned' => true,
                'rt-ready' => true,
            ],
            DeclaredCommandOwnershipTestCommand::$seenInsideBody,
        );
    }

    /**
     * The claim is gone once the body has returned: both registries and the reader interest.
     *
     * @throws HilosException When the fixture body refuses
     */
    public function testTheClaimIsTakenBackWhenTheBodyReturns(): void
    {
        new DeclaredCommandOwnershipTestCommand()->execute([], []);

        $this->assertNothingIsHeldUnderTheCommandId();
    }

    /**
     * And gone when the body throws, which is why it is taken back in a finally: a command
     * refused by its own input must not leave an owner behind for the next test in this process.
     */
    public function testTheClaimIsTakenBackWhenTheBodyThrows(): void
    {
        $this->expectException(LogicException::class);

        try {
            new DeclaredCommandOwnershipTestThrowingCommand()->execute([], []);
        } finally {
            $this->assertNothingIsHeldUnderTheCommandId();
        }
    }

    /**
     * A command that declares nothing claims nothing: the empty map never reaches a registry,
     * so the runner does not invent an owner for a command that only reads.
     *
     * @throws HilosException When the fixture body refuses
     */
    public function testACommandThatDeclaresNothingClaimsNothing(): void
    {
        new DeclaredCommandOwnershipTestSilentCommand()->execute([], []);

        $this->assertSame(
            ['db-collections' => [], 'rt-collections' => []],
            DeclaredCommandOwnershipTestSilentCommand::$seenInsideBody,
        );
        $this->assertNothingIsHeldUnderTheCommandId();
    }

    /**
     * Asserts that nothing at all is registered under the id test-only commands claim with.
     */
    private function assertNothingIsHeldUnderTheCommandId(): void
    {
        $this->assertFalse(TruthSourceRegistry::hasTruthSource(DeclaredCommandOwnershipTestCommand::COLLECTION));
        $this->assertFalse(RtTruthSourceRegistry::hasTruthSource(DeclaredCommandOwnershipTestCommand::RT_COLLECTION));
        $this->assertSame(
            [],
            SourceInterestRegistry::collectionsOfConsumer(
                SourceConsumer::agent(TestOnlyCommand::TRUTH_SOURCE_ID),
                SourceChange::KIND_DB,
            ),
        );
        $this->assertSame(
            [],
            SourceInterestRegistry::collectionsOfConsumer(
                SourceConsumer::agent(TestOnlyCommand::TRUTH_SOURCE_ID),
                SourceChange::KIND_RT,
            ),
        );
    }
}

/**
 * A test-only command owning one collection of each half, whose body reports what it sees.
 */
final class DeclaredCommandOwnershipTestCommand extends TestOnlyCommand
{
    public const string COLLECTION = 'unit_declared_command_ownership_db';
    public const string RT_COLLECTION = 'unit_declared_command_ownership_rt';

    public const array OWNS_DB = [self::COLLECTION => TruthSourceOperation::BY_KIND];
    public const array OWNS_RT = [self::RT_COLLECTION => TruthSourceOperation::BY_KIND];

    /** @var array<string, bool> What the body saw of its own claim, keyed by what was asked */
    public static array $seenInsideBody = [];

    public function getName(): string
    {
        return 'test:declared-command-ownership';
    }

    public function execution(): CommandExecution
    {
        return CommandExecution::cliOfflineWrite('fixture of a unit test: it never reaches a registry of commands');
    }

    public function getDescription(): string
    {
        return 'Test fixture: reports the claim its runner laid';
    }

    public function getHelp(): string
    {
        return 'Test fixture, not registered as a command.';
    }

    /**
     * Asks both registries about itself, with no agent id current - the CLI's own situation.
     *
     * @param array<string, mixed> $options Parsed options, unused by this fixture
     * @param list<string> $args Positional arguments, unused by this fixture
     * @return int Exit code, always success
     */
    protected function run(array $options, array $args): int
    {
        self::$seenInsideBody = [
            'db-write-allowed' => self::updateOfRowOneIsAllowed(),
            'db-ready' => SourceInterestRegistry::isReady(SourceChange::KIND_DB, self::COLLECTION),
            'rt-owned' => RtTruthSourceRegistry::isTruthSource(self::RT_COLLECTION, ['1']),
            'rt-ready' => SourceInterestRegistry::isReady(SourceChange::KIND_RT, self::RT_COLLECTION),
        ];

        return ExitCode::SUCCESS;
    }

    /**
     * Asks the guard the question a command body asks it, and answers it as a fact rather than
     * letting the refusal leave the body - what is under test is the claim, not the throw.
     *
     * @return bool True when the write guard lets this process update row 1 of the collection
     */
    private static function updateOfRowOneIsAllowed(): bool
    {
        try {
            TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '1', [], TruthSourceOperation::Update);

            return true;
        } catch (WriteNotAllowedException) {
            return false;
        }
    }
}

/**
 * A test-only command declaring the same collections, whose body refuses.
 */
final class DeclaredCommandOwnershipTestThrowingCommand extends TestOnlyCommand
{
    public const array OWNS_DB = [
        DeclaredCommandOwnershipTestCommand::COLLECTION => TruthSourceOperation::BY_KIND,
    ];
    public const array OWNS_RT = [
        DeclaredCommandOwnershipTestCommand::RT_COLLECTION => TruthSourceOperation::BY_KIND,
    ];

    public function getName(): string
    {
        return 'test:declared-command-ownership-throwing';
    }

    public function execution(): CommandExecution
    {
        return CommandExecution::cliOfflineWrite('fixture of a unit test: it never reaches a registry of commands');
    }

    public function getDescription(): string
    {
        return 'Test fixture: refuses its own input after the claim was laid';
    }

    public function getHelp(): string
    {
        return 'Test fixture, not registered as a command.';
    }

    /**
     * @param array<string, mixed> $options Parsed options, unused by this fixture
     * @param list<string> $args Positional arguments, unused by this fixture
     * @return int Exit code, never returned
     * @throws LogicException Always - this fixture exists to leave the body by throwing
     */
    protected function run(array $options, array $args): int
    {
        throw new LogicException('fixture refuses its own input');
    }
}

/**
 * A test-only command declaring nothing, whose body reports what was claimed for it.
 */
final class DeclaredCommandOwnershipTestSilentCommand extends TestOnlyCommand
{
    /** @var array<string, list<string>> Collections held under the command id inside the body, by half */
    public static array $seenInsideBody = [];

    public function getName(): string
    {
        return 'test:declared-command-ownership-silent';
    }

    public function execution(): CommandExecution
    {
        return CommandExecution::cliOfflineWrite('fixture of a unit test: it never reaches a registry of commands');
    }

    public function getDescription(): string
    {
        return 'Test fixture: declares nothing at all';
    }

    public function getHelp(): string
    {
        return 'Test fixture, not registered as a command.';
    }

    /**
     * @param array<string, mixed> $options Parsed options, unused by this fixture
     * @param list<string> $args Positional arguments, unused by this fixture
     * @return int Exit code, always success
     */
    protected function run(array $options, array $args): int
    {
        self::$seenInsideBody = [
            'db-collections' => SourceInterestRegistry::collectionsOfConsumer(
                SourceConsumer::agent(self::TRUTH_SOURCE_ID),
                SourceChange::KIND_DB,
            ),
            'rt-collections' => SourceInterestRegistry::collectionsOfConsumer(
                SourceConsumer::agent(self::TRUTH_SOURCE_ID),
                SourceChange::KIND_RT,
            ),
        ];

        return ExitCode::SUCCESS;
    }
}
