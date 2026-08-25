<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Session;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Hilos;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The split between parking a wait and re-pointing one (HIL-685).
 *
 * Two agents write these collections and they hold different rights: the session holder
 * owns them fully, and the users library holds adding and removing so it can park the
 * browser whose code it just sent. Everything here is that one sentence made checkable -
 * `park()` adds and never edits, `repoint()` edits, and the library is refused the second.
 *
 * The case that matters most is the one that reads like nothing happening: a library
 * parking a browser that is ALREADY parked. That was an unconditional upsert until this
 * leaf, and it made every second password-recovery submit die with a refused write.
 */
final class WaiterParkOwnershipTest extends TestCase
{
    private const string HOLDER = 'unit_waiter_holder';
    private const string LIBRARY = 'unit_waiter_library';
    private const string ACCEPT_KEY = 'ak-1';
    private const string SESSION = 'session-token-1';
    private const string OTHER_SESSION = 'session-token-2';
    private const string FIRST_ADDRESS = 'first@example.test';
    private const string SECOND_ADDRESS = 'second@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        Hilos::$rt = new WaiterOwnershipRtContext();
        Hilos::$rt->configure();

        foreach ([StateRegistrationWaiter::RT_COLLECTION, StateRecoveryWaiter::RT_COLLECTION] as $collection) {
            RtTruthSourceRegistry::register($collection, true, self::HOLDER);
            RtTruthSourceRegistry::register(
                $collection,
                true,
                self::LIBRARY,
                [TruthSourceOperation::Add, TruthSourceOperation::Remove],
            );
        }
    }

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        RtTruthSourceRegistry::unregisterAgent(self::HOLDER);
        RtTruthSourceRegistry::unregisterAgent(self::LIBRARY);
        Hilos::$rt = null;

        parent::tearDown();
    }

    public function testParkingBringsTheRowIntoBeing(): void
    {
        ExecutionContext::setCurrentAgentId(self::LIBRARY);

        Hilos::$rt->hilosRecoveryWaiters->actions->park(self::ACCEPT_KEY, self::FIRST_ADDRESS, self::SESSION);

        $parked = Hilos::$rt->hilosRecoveryWaiters[self::ACCEPT_KEY];
        $this->assertNotNull($parked);
        $this->assertSame(self::FIRST_ADDRESS, $parked->identifier);
        $this->assertSame(self::SESSION, $parked->sessionToken);
        $this->assertFalse($parked->codeAccepted);
    }

    public function testParkingAgainOnAnotherAddressLeavesTheRowAloneAndIsNotRefused(): void
    {
        ExecutionContext::setCurrentAgentId(self::LIBRARY);
        Hilos::$rt->hilosRecoveryWaiters->actions->park(self::ACCEPT_KEY, self::FIRST_ADDRESS, self::SESSION);

        // The regression: this second park used to be an update, which the library's grant
        // refuses - so asking for a second reset code killed the whole recovery.
        Hilos::$rt->hilosRecoveryWaiters->actions->park(self::ACCEPT_KEY, self::SECOND_ADDRESS, self::SESSION);

        $this->assertSame(self::FIRST_ADDRESS, Hilos::$rt->hilosRecoveryWaiters[self::ACCEPT_KEY]?->identifier);
    }

    public function testTheLibraryMayNotRepointAWaitItself(): void
    {
        ExecutionContext::setCurrentAgentId(self::LIBRARY);
        Hilos::$rt->hilosRecoveryWaiters->actions->park(self::ACCEPT_KEY, self::FIRST_ADDRESS, self::SESSION);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);

        Hilos::$rt->hilosRecoveryWaiters->actions->repoint(self::ACCEPT_KEY, self::SECOND_ADDRESS, self::SESSION);
    }

    public function testTheHolderRepointsAWaitAndTakesItsGrantWithIt(): void
    {
        ExecutionContext::setCurrentAgentId(self::LIBRARY);
        Hilos::$rt->hilosRecoveryWaiters->actions->park(self::ACCEPT_KEY, self::FIRST_ADDRESS, self::SESSION);

        ExecutionContext::setCurrentAgentId(self::HOLDER);
        Hilos::$rt->hilosRecoveryWaiters->actions->acceptCodeForSession(self::SESSION, self::FIRST_ADDRESS);
        $this->assertTrue(Hilos::$rt->hilosRecoveryWaiters[self::ACCEPT_KEY]?->codeAccepted);

        Hilos::$rt->hilosRecoveryWaiters->actions->repoint(self::ACCEPT_KEY, self::SECOND_ADDRESS, self::OTHER_SESSION);

        $parked = Hilos::$rt->hilosRecoveryWaiters[self::ACCEPT_KEY];
        $this->assertNotNull($parked);
        $this->assertSame(self::SECOND_ADDRESS, $parked->identifier);
        $this->assertSame(self::OTHER_SESSION, $parked->sessionToken);
        // The grant belonged to the address that was proven, and that address is gone.
        $this->assertFalse($parked->codeAccepted);
    }

    public function testRepointingAWaitThatIsNotThereParksIt(): void
    {
        ExecutionContext::setCurrentAgentId(self::HOLDER);

        // What a browser that reconnected between asking for the code and proving it
        // leaves behind: no row for the frame to edit, and one is owed either way.
        Hilos::$rt->hilosRecoveryWaiters->actions->repoint(self::ACCEPT_KEY, self::FIRST_ADDRESS, self::SESSION);

        $this->assertSame(self::FIRST_ADDRESS, Hilos::$rt->hilosRecoveryWaiters[self::ACCEPT_KEY]?->identifier);
    }

    public function testRegistrationWaitsSplitTheSameWay(): void
    {
        ExecutionContext::setCurrentAgentId(self::LIBRARY);
        Hilos::$rt->hilosRegistrationWaiters->actions->park(self::ACCEPT_KEY, self::FIRST_ADDRESS, self::SESSION);
        Hilos::$rt->hilosRegistrationWaiters->actions->park(self::ACCEPT_KEY, self::SECOND_ADDRESS, self::SESSION);

        $this->assertSame(self::FIRST_ADDRESS, Hilos::$rt->hilosRegistrationWaiters[self::ACCEPT_KEY]?->identifier);

        ExecutionContext::setCurrentAgentId(self::HOLDER);
        Hilos::$rt->hilosRegistrationWaiters->actions->repoint(self::ACCEPT_KEY, self::SECOND_ADDRESS, self::SESSION);

        $this->assertSame(self::SECOND_ADDRESS, Hilos::$rt->hilosRegistrationWaiters[self::ACCEPT_KEY]?->identifier);
    }
}

/**
 * A project that mounts the auth waits and nothing else, through the feature that owns them.
 *
 * Mounted by calling the real feature rather than by re-listing its two collections: what
 * is under test is the write API those mounts carry, and a hand-made copy of the mount
 * would be a copy of the thing being checked.
 */
final class WaiterOwnershipRtContext extends RtContext
{
    /**
     * Mounts both waits with their framework representation.
     *
     * @throws StateCollectionNotFoundException When a wait is represented before it is mounted
     */
    public function configure(): void
    {
        new AuthFeature()->mount($this);
    }
}
