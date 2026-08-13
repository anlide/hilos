<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Auth\Throttle\DTO\ThrottleCheckSignalData;
use Hilos\Auth\Throttle\DTO\ThrottleSuccessSignalData;
use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Auth\Throttle\ThrottleGate;
use Hilos\Auth\Throttle\ThrottleScope;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\Definition\AuthThrottleFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\AuthAttempt as StateAuthAttempt;
use Hilos\Runtime\View\Collection\AuthAttempts;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\AuthAttempt;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * The anti-abuse throttle across the two stores it keeps its state in (HIL-420).
 *
 * The unit cases either side of this one each see half the layer: the pool test drives the
 * worker's half on a made-up verdict, and the policy test does arithmetic on numbers. What
 * is only true of the whole thing is pinned here, against a real table - that a window turns
 * into a refusal with a wait attached, that the refusal outlives the process which decided
 * it, that authenticating buys back the session and not the address it came from, and that
 * a deployment with the layer switched off keeps none of it.
 *
 * The agent is driven directly rather than through a daemon: the signals it answers are the
 * whole of its interface, and a worker sending them is exactly what
 * {@see ThrottleGate} does.
 */
final class AuthThrottleIntegrationTest extends FrameworkIntegrationTestCase
{
    /** @var list<string> Framework tables this case needs; the settings table is loaded eagerly by the context */
    private const array TABLES = ['hilos_auth_block', 'hilos_setting'];

    private const string SESSION_IDENTITY = '0d1e0ea0e4b0ba1d0c0e1d4c8d1cbb6a5b03d1b3d4d2b8d3f1a0c9e8b7a6d5c4';

    private const string CLIENT_IP = '203.0.113.9';

    private const string ACTION = 'sign_in';

    private const string ACCEPT_KEY = 'ak-throttle-integration';

    private const string REQUEST_KEY = 'rq-throttle-integration';

    /** Attempts one session gets per window in this case, small enough to breach in three signals. */
    private const int MAX_SESSION = 2;

    /** Attempts one address gets per window; above the session limit, as it is in production. */
    private const int MAX_IP = 3;

    /** First ladder step in this case, in seconds. */
    private const int FIRST_STEP = 30;

    private ?DbContext $previousDb = null;

    private ?EnvAccessor $previousEnv = null;

    private ?RtContext $previousRt = null;

    private ?SignalRouter $previousSignalRouter = null;

    /**
     * @throws DatabaseException When a stub statement fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);

        $this->previousDb = Hilos::$db;
        $this->previousEnv = Hilos::$env;
        $this->previousRt = Hilos::$rt;
        $this->previousSignalRouter = Hilos::$sr;

        $db = new AuthThrottleTestDbContext();
        $db->configure();
        Hilos::$db = $db;

        // An accessor with no .env behind it, so the numbers below are the ones read.
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        $this->configureThrottle(enabled: true);

        Hilos::$sr = new SignalRouter();
        $this->mountCounters();
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregister(StateAuthAttempt::RT_COLLECTION, HilosAgentType::HILOS_AUTH_THROTTLE);
        ExecutionContext::clear();
        foreach (self::THROTTLE_KNOBS as $knob) {
            putenv($knob);
        }

        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$rt = $this->previousRt;
        Hilos::$env = $this->previousEnv;
        Hilos::$db = $this->previousDb;

        self::runStubs(down: true);

        parent::tearDown();
    }

    /** @var list<string> Environment names this case sets and has to unset again */
    private const array THROTTLE_KNOBS = [
        'HILOS_AUTH_THROTTLE_ENABLED',
        'HILOS_AUTH_THROTTLE_WINDOW',
        'HILOS_AUTH_THROTTLE_MAX_SESSION',
        'HILOS_AUTH_THROTTLE_MAX_IP',
        'HILOS_AUTH_THROTTLE_STEPS',
    ];

    public function testAWindowSpentIsRefusedWithTheWaitItCosts(): void
    {
        $agent = $this->startedAgent();

        for ($attempt = 0; $attempt < self::MAX_SESSION; $attempt++) {
            $this->assertTrue($this->judge($agent, ThrottleScope::SESSION)->allowed);
        }

        $verdict = $this->judge($agent, ThrottleScope::SESSION);
        $this->assertFalse($verdict->allowed);
        $this->assertSame(self::FIRST_STEP, $verdict->retryAfter);

        $block = Hilos::$db->authBlocks->findByKey(ThrottleScope::SESSION, self::SESSION_IDENTITY, self::ACTION);
        $this->assertNotNull($block);
        $this->assertSame(1, $block->level);
    }

    public function testABlockOutlivesTheProcessThatDecidedIt(): void
    {
        $this->spendTheSessionWindow($this->startedAgent());

        // A restart: counters gone with the process, the table as it was left.
        $this->mountCounters();
        $restarted = $this->startedAgent();

        $verdict = $this->judge($restarted, ThrottleScope::SESSION);
        $this->assertFalse($verdict->allowed);
        $this->assertNotNull($verdict->retryAfter);
        $this->assertGreaterThan(0, $verdict->retryAfter);
        $this->assertLessThanOrEqual(self::FIRST_STEP, $verdict->retryAfter);
    }

    public function testAuthenticatingBuysBackTheSessionAndNotTheAddress(): void
    {
        $agent = $this->startedAgent();
        $this->assertTrue($this->judge($agent, ThrottleScope::IP)->allowed);
        $this->spendTheSessionWindow($agent);

        $agent->onSignalAgent(
            new AgentSignalData(new ThrottleSuccessSignalData(self::SESSION_IDENTITY)),
            SignalSource::WORKER,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED,
        );

        $this->assertNull($this->counter(ThrottleScope::SESSION, self::SESSION_IDENTITY));
        $this->assertNull(
            Hilos::$db->authBlocks->findByKey(ThrottleScope::SESSION, self::SESSION_IDENTITY, self::ACTION),
        );

        $address = $this->counter(ThrottleScope::IP, self::CLIENT_IP);
        $this->assertNotNull($address);
        $this->assertSame(1, $address->count);
    }

    public function testAServedBlockIsDroppedButOneStillInForceIsNot(): void
    {
        $this->spendTheSessionWindow($this->startedAgent());
        $blocks = Hilos::$db->authBlocks;
        $this->assertNotNull($blocks->findByKey(ThrottleScope::SESSION, self::SESSION_IDENTITY, self::ACTION));

        // The sweep as it runs while the block is still on: the row has to survive it.
        $this->assertSame(0, $blocks->clearServed(date('Y-m-d H:i:s')));
        $this->assertNotNull($blocks->findByKey(ThrottleScope::SESSION, self::SESSION_IDENTITY, self::ACTION));

        // And as it runs once the block has been served and the ladder cooled.
        $this->assertSame(1, $blocks->clearServed(date('Y-m-d H:i:s', time() + self::FIRST_STEP + 1)));
        $this->assertNull($blocks->findByKey(ThrottleScope::SESSION, self::SESSION_IDENTITY, self::ACTION));
    }

    public function testASwitchedOffLayerJudgesNothingAndRemembersNothing(): void
    {
        $this->configureThrottle(enabled: false);
        $agent = $this->startedAgent();

        for ($attempt = 0; $attempt < self::MAX_SESSION + 2; $attempt++) {
            $this->assertTrue($this->judge($agent, ThrottleScope::SESSION)->allowed);
        }

        $this->assertNull($this->counter(ThrottleScope::SESSION, self::SESSION_IDENTITY));
        $this->assertSame([], Hilos::$db->authBlocks->findActive(date('Y-m-d H:i:s', 0)));
    }

    /**
     * Sets the numbers this case judges against.
     *
     * @param bool $enabled Whether the layer refuses anything at all
     */
    private function configureThrottle(bool $enabled): void
    {
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_ENABLED->name . '=' . ($enabled ? 'true' : 'false'));
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_WINDOW->name . '=60');
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_MAX_SESSION->name . '=' . self::MAX_SESSION);
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_MAX_IP->name . '=' . self::MAX_IP);
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_STEPS->name . '=' . self::FIRST_STEP . ',120');
    }

    /**
     * Mounts an empty set of counters, as a freshly started worker would hold.
     */
    private function mountCounters(): void
    {
        $rt = new AuthThrottleTestRtContext();
        $rt->mountFeatureRuntime([new AuthThrottleFeature()]);
        Hilos::$rt = $rt;

        ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_AUTH_THROTTLE);
    }

    /**
     * Starts an agent over the mounted counters and the live table.
     *
     * @return AuthThrottleAgent Started agent
     */
    private function startedAgent(): AuthThrottleAgent
    {
        $agent = new AuthThrottleAgent();
        $agent->onStart();

        return $agent;
    }

    /**
     * Asks the agent about one attempt and reads the verdict it answered with.
     *
     * @param AuthThrottleAgent $agent Agent to ask
     * @param string $scope Throttle scope of the attempt
     * @return ThrottleVerdictSignalData The verdict
     */
    private function judge(AuthThrottleAgent $agent, string $scope): ThrottleVerdictSignalData
    {
        $agent->onSignalAgent(
            new AgentSignalData(new ThrottleCheckSignalData(
                scope: $scope,
                identity: $scope === ThrottleScope::SESSION ? self::SESSION_IDENTITY : self::CLIENT_IP,
                action: self::ACTION,
                acceptKey: self::ACCEPT_KEY,
                requestKey: self::REQUEST_KEY,
                agentType: HilosAgentType::HILOS_AUTH_THROTTLE,
            )),
            SignalSource::WORKER,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK,
        );

        $verdict = $this->lastVerdict();
        $this->assertNotNull($verdict);

        return $verdict;
    }

    /**
     * Spends the session's whole window and the attempt that breaches it.
     *
     * @param AuthThrottleAgent $agent Agent to ask
     */
    private function spendTheSessionWindow(AuthThrottleAgent $agent): void
    {
        for ($attempt = 0; $attempt <= self::MAX_SESSION; $attempt++) {
            $this->judge($agent, ThrottleScope::SESSION);
        }
    }

    /**
     * Drains the signal queue and returns the last verdict it held.
     *
     * @return ?ThrottleVerdictSignalData Verdict the agent queued, or null when it queued none
     */
    private function lastVerdict(): ?ThrottleVerdictSignalData
    {
        $verdict = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT) {
                continue;
            }

            $payload = $signal->data instanceof AgentSignalData ? $signal->data->data : null;
            if ($payload instanceof ThrottleVerdictSignalData) {
                $verdict = $payload;
            }
        }

        return $verdict;
    }

    /**
     * Reads one counter out of the mounted collection.
     *
     * @param string $scope Throttle scope
     * @param string $identity Identity within the scope
     * @return ?AuthAttempt Counter, or null when the key has none
     */
    private function counter(string $scope, string $identity): ?AuthAttempt
    {
        $attempts = Hilos::$rt?->hilosAuthAttempts;
        $this->assertInstanceOf(AuthAttempts::class, $attempts);

        return $attempts[StateAuthAttempt::keyFor($scope, $identity, self::ACTION)];
    }

    /**
     * Runs one direction of the stub file of every table this case uses.
     *
     * @param bool $down Run the down (drop) stubs when true, the create stubs when false
     * @throws DatabaseException When a stub statement fails
     */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        foreach (self::TABLES as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/**
 * A framework database context with nothing but the framework's own collections.
 *
 * The throttle is framework-owned and reads one framework table, so the smallest honest
 * context for it is {@see HilosDbContext} with no project collections added.
 */
final class AuthThrottleTestDbContext extends HilosDbContext
{
}

/**
 * Runtime context fixture that mounts only what the declared feature asks for.
 */
final class AuthThrottleTestRtContext extends RtContext
{
    /**
     * Declares no project runtime of its own; the feature mount is the whole fixture.
     */
    public function configure(): void
    {
    }
}
