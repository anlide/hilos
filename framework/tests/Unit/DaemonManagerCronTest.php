<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Hilos;
use Hilos\Socket\Worker\DTO\CronSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for default daemon cron signal dispatch.
 */
final class DaemonManagerCronTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testOnCronQueuesDaemonCronSignal(): void
    {
        $manager = new DaemonManagerCronTestManager();
        $rule = new CronRule('cleanup_history', '*/30 * * * *');

        $manager->invokeOnCron($rule);

        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertSame(SignalSource::DAEMON, $signal->signalSource->getSource());
        $this->assertSame(SignalTypeConstants::CRON, $signal->signalType->getType());
        $this->assertSame('cleanup_history', $signal->signalName->getName());
        $this->assertInstanceOf(CronSignalDTO::class, $signal->data);
        $this->assertSame('cleanup_history', $signal->data->cronName);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }
}

final class DaemonManagerCronTestManager extends DaemonManager
{
    public function invokeOnCron(CronRule $rule): void
    {
        $this->onCron($rule);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerCronTestAgentManagerDaemon();
    }
}

final class DaemonManagerCronTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}
