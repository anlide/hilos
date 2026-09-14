<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Hilos;
use Hilos\ProtectedMode\Exception\SessionlessConnectionsRosterException;
use Hilos\ProtectedMode\SessionStageStartupGuard;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tests\Unit\PresenceConnectionFixtures;
use Hilos\Tests\Unit\Runtime\EmptyTestRtContext;
use Hilos\Tests\Unit\SessionConnectionFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the startup gate requiring session-stage browser connections.
 *
 * Every judged case mounts a context of its own because the gate reads only the
 * collections mounted by this installation. A node with no runtime or no browser
 * roster has no conflicting session source and stays outside the gate.
 */
final class SessionStageStartupGuardTest extends TestCase
{
    /**
     * Clears the runtime context mounted by the preceding case.
     */
    protected function tearDown(): void
    {
        Hilos::$rt = null;

        parent::tearDown();
    }

    public function testANodeWithNoRuntimeContextIsNotJudged(): void
    {
        $this->expectNotToPerformAssertions();

        SessionStageStartupGuard::assertRosterCarriesSessions();
    }

    public function testANodeWithNoConnectionsRosterIsNotJudged(): void
    {
        $this->expectNotToPerformAssertions();

        $this->mount(new EmptyTestRtContext());

        SessionStageStartupGuard::assertRosterCarriesSessions();
    }

    public function testTheSessionStagePasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->mount(new SessionStageRosterContext());

        SessionStageStartupGuard::assertRosterCarriesSessions();
    }

    public function testThePresenceStageIsRefusedAndTheMessageNamesTheMount(): void
    {
        $this->mount(new PresenceStageRosterContext());

        $message = '';
        try {
            SessionStageStartupGuard::assertRosterCarriesSessions();
            $this->fail('A presence-stage browser connections roster was accepted');
        } catch (SessionlessConnectionsRosterException $refusal) {
            $message = $refusal->getMessage();
        }

        $this->assertStringContainsString(PresenceConnectionFixtures::class, $message);
        $this->assertStringContainsString(PresenceStageRosterContext::class, $message);
        $this->assertStringContainsString(HilosConnections::class, $message);
        $this->assertStringContainsString(HilosSessionConnections::class, $message);
    }

    /**
     * Mounts and configures one test runtime context.
     *
     * @param RtContext $context Runtime context for the case
     */
    private function mount(RtContext $context): void
    {
        Hilos::$rt = $context;
        Hilos::$rt->configure();
    }
}

/**
 * Runtime context carrying browser connections on the presence stage.
 */
final class PresenceStageRosterContext extends RtContext
{
    public const string connections = 'connections';

    /**
     * Mounts the presence-stage connections fixture.
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = PresenceConnectionFixtures::init();
    }
}

/**
 * Runtime context carrying browser connections on the session stage.
 */
final class SessionStageRosterContext extends RtContext
{
    public const string connections = 'connections';

    /**
     * Mounts the session-stage connections fixture.
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = SessionConnectionFixtures::init();
    }
}
