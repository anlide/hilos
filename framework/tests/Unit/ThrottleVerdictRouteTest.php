<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Throttle\DTO\ThrottleCheckSignalData;
use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Auth\Throttle\ThrottleScope;
use Hilos\Auth\Throttle\ThrottleVerdictRoute;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Resolves only the active facade's declared answer; malformed topology never borrows the auth route. */
final class ThrottleVerdictRouteTest extends TestCase
{
    private string $previousApp;

    protected function setUp(): void
    {
        $this->previousApp = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, VerdictRouteTestHilos::class);
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
    }

    public function testDefaultAndOverriddenNamesComeFromTheActiveRegistry(): void
    {
        $this->assertSame([], Hilos::AGENTS);
        $this->assertSame(HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT, ThrottleVerdictRoute::signalFor(VerdictRouteAuthAgent::AGENT_TYPE));
        $this->assertSame(HilosSignalConstants::STATUS_THROTTLE_VERDICT, ThrottleVerdictRoute::signalFor(VerdictRouteStatusAgent::AGENT_TYPE));
    }

    /** @return iterable<string, array{class-string<Hilos>, string, string}> Invalid registry, target and diagnostic */
    public static function invalidRoutes(): iterable
    {
        yield 'unknown type' => [VerdictRouteTestHilos::class, 'missing', 'not registered'];
        yield 'not an agent' => [VerdictRouteMalformedHilos::class, 'malformed', 'not registered'];
        yield 'empty name' => [VerdictRouteEmptyHilos::class, VerdictRouteStatusAgent::AGENT_TYPE, 'empty THROTTLE_VERDICT_SIGNAL'];
        yield 'missing own route' => [VerdictRouteMissingHilos::class, VerdictRouteStatusAgent::AGENT_TYPE, 'does not own verdict route'];
        yield 'foreign owner' => [VerdictRouteForeignHilos::class, VerdictRouteStatusAgent::AGENT_TYPE, 'does not own verdict route'];
        yield 'untyped' => [VerdictRouteUntypedHilos::class, VerdictRouteStatusAgent::AGENT_TYPE, 'must carry'];
        yield 'wrong dto' => [VerdictRouteWrongDtoHilos::class, VerdictRouteStatusAgent::AGENT_TYPE, 'must carry'];
        yield 'missing index' => [VerdictRouteNoIndexHilos::class, VerdictRouteStatusAgent::AGENT_TYPE, 'must name index field agentIndex'];
        yield 'wrong index' => [VerdictRouteWrongIndexHilos::class, VerdictRouteStatusAgent::AGENT_TYPE, 'must name index field agentIndex'];
    }

    #[DataProvider('invalidRoutes')]
    public function testInvalidTopologyRefusesWithoutFallback(string $app, string $type, string $diagnostic): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $app);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($diagnostic);
        ThrottleVerdictRoute::signalFor($type);
    }

    public function testExistingWorkerIpcPreservesCheckAddressVerdictIndexAndReceipt(): void
    {
        $check = new ThrottleCheckSignalData(ThrottleScope::EMAIL, 'email-hash', 'subscribe', 'ak', 'rq', VerdictRouteStatusAgent::AGENT_TYPE, '42');
        $ask = WorkerDTO::factoryWorkerDTO(new WorkerAgentMessageDTO(null, new SignalDTO(
            new SignalSource(SignalSource::AGENT, VerdictRouteStatusAgent::AGENT_TYPE, '42'),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK),
            new AgentSignalData($check, 27),
        ))->toJson());
        $this->assertInstanceOf(WorkerAgentMessageDTO::class, $ask);
        $this->assertInstanceOf(AgentSignalData::class, $ask->signal->data);
        $this->assertInstanceOf(ThrottleCheckSignalData::class, $ask->signal->data->data);
        $this->assertSame($check->toArray(), $ask->signal->data->data->toArray());
        $this->assertSame(27, $ask->signal->data->receiptId);

        $verdict = new ThrottleVerdictSignalData($check->requestKey, false, 30, $check->agentIndex);
        $answer = WorkerDTO::factoryWorkerDTO(new DaemonAgentMessageDTO(VerdictRouteStatusAgent::AGENT_TYPE . ':42', new SignalDTO(
            new SignalSource(SignalSource::AGENT, 'throttle'),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(ThrottleVerdictRoute::signalFor($check->agentType)),
            new AgentSignalData($verdict, 27),
        ))->toJson());
        $this->assertInstanceOf(DaemonAgentMessageDTO::class, $answer);
        $this->assertSame(VerdictRouteStatusAgent::AGENT_TYPE . ':42', $answer->agentId);
        $this->assertSame(HilosSignalConstants::STATUS_THROTTLE_VERDICT, $answer->signal->signalName->getName());
        $this->assertInstanceOf(AgentSignalData::class, $answer->signal->data);
        $this->assertInstanceOf(ThrottleVerdictSignalData::class, $answer->signal->data->data);
        $this->assertSame($verdict->toArray(), $answer->signal->data->data->toArray());
        $this->assertSame(27, $answer->signal->data->receiptId);
    }
}

class VerdictRouteAuthAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'verdict_auth';
    public const array AGENT_SIGNALS = [HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT => ThrottleVerdictSignalData::class];

    /** No resources are started by the resolver fixture. */
    public function onStop(): void
    {
    }
}

class VerdictRouteStatusAgent extends VerdictRouteAuthAgent
{
    public const string AGENT_TYPE = 'verdict_status';
    public const string THROTTLE_VERDICT_SIGNAL = HilosSignalConstants::STATUS_THROTTLE_VERDICT;
    public const array AGENT_SIGNALS = [HilosSignalConstants::STATUS_THROTTLE_VERDICT => [
        AgentSignalConfigKey::DTO => ThrottleVerdictSignalData::class,
        AgentSignalConfigKey::INDEX_FIELD => ThrottleVerdictSignalData::agentIndex,
    ]];
}

class VerdictRouteTestHilos extends Hilos
{
    public const array AGENTS = [
        VerdictRouteAuthAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => VerdictRouteAuthAgent::class],
        VerdictRouteStatusAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => VerdictRouteStatusAgent::class, AgentRegistryKey::INDEXED => true],
    ];

    /** @return HilosDbContext Unconfigured metadata-only context */
    protected static function createDb(): HilosDbContext
    {
        return new HilosDbContext();
    }
}

final class VerdictRouteEmptyAgent extends VerdictRouteStatusAgent
{
    public const string THROTTLE_VERDICT_SIGNAL = '';
}

final class VerdictRouteEmptyHilos extends VerdictRouteTestHilos
{
    public const array AGENTS = [
        VerdictRouteAuthAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => VerdictRouteAuthAgent::class],
        VerdictRouteStatusAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => VerdictRouteEmptyAgent::class, AgentRegistryKey::INDEXED => true],
    ];
}

final class VerdictRouteMissingAgent extends VerdictRouteStatusAgent
{
    public const array AGENT_SIGNALS = [];
}

final class VerdictRouteMissingHilos extends VerdictRouteTestHilos
{
    public const array AGENTS = [
        VerdictRouteAuthAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => VerdictRouteAuthAgent::class],
        VerdictRouteStatusAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => VerdictRouteMissingAgent::class, AgentRegistryKey::INDEXED => true],
    ];
}

final class VerdictRouteForeignAgent extends VerdictRouteStatusAgent
{
    public const string THROTTLE_VERDICT_SIGNAL = HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT;
    public const array AGENT_SIGNALS = [];
}

final class VerdictRouteForeignHilos extends VerdictRouteTestHilos
{
    public const array AGENTS = [
        VerdictRouteAuthAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => VerdictRouteAuthAgent::class],
        VerdictRouteStatusAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => VerdictRouteForeignAgent::class, AgentRegistryKey::INDEXED => true],
    ];
}

final class VerdictRouteUntypedAgent extends VerdictRouteStatusAgent
{
    public const array AGENT_SIGNALS = [HilosSignalConstants::STATUS_THROTTLE_VERDICT];
}

final class VerdictRouteUntypedHilos extends VerdictRouteTestHilos
{
    public const array AGENTS = [
        VerdictRouteAuthAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => VerdictRouteAuthAgent::class],
        VerdictRouteStatusAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => VerdictRouteUntypedAgent::class, AgentRegistryKey::INDEXED => true],
    ];
}

final class VerdictRouteWrongDtoAgent extends VerdictRouteStatusAgent
{
    public const array AGENT_SIGNALS = [HilosSignalConstants::STATUS_THROTTLE_VERDICT => ThrottleCheckSignalData::class];
}

final class VerdictRouteWrongDtoHilos extends VerdictRouteTestHilos
{
    public const array AGENTS = [
        VerdictRouteAuthAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => VerdictRouteAuthAgent::class],
        VerdictRouteStatusAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => VerdictRouteWrongDtoAgent::class, AgentRegistryKey::INDEXED => true],
    ];
}

final class VerdictRouteNoIndexAgent extends VerdictRouteStatusAgent
{
    public const array AGENT_SIGNALS = [HilosSignalConstants::STATUS_THROTTLE_VERDICT => ThrottleVerdictSignalData::class];
}

final class VerdictRouteNoIndexHilos extends VerdictRouteTestHilos
{
    public const array AGENTS = [
        VerdictRouteAuthAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => VerdictRouteAuthAgent::class],
        VerdictRouteStatusAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => VerdictRouteNoIndexAgent::class, AgentRegistryKey::INDEXED => true],
    ];
}

final class VerdictRouteWrongIndexAgent extends VerdictRouteStatusAgent
{
    public const array AGENT_SIGNALS = [HilosSignalConstants::STATUS_THROTTLE_VERDICT => [
        AgentSignalConfigKey::DTO => ThrottleVerdictSignalData::class,
        AgentSignalConfigKey::INDEX_FIELD => 'requestKey',
    ]];
}

final class VerdictRouteWrongIndexHilos extends VerdictRouteTestHilos
{
    public const array AGENTS = [
        VerdictRouteAuthAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => VerdictRouteAuthAgent::class],
        VerdictRouteStatusAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => VerdictRouteWrongIndexAgent::class, AgentRegistryKey::INDEXED => true],
    ];
}

final class VerdictRouteMalformedHilos extends VerdictRouteTestHilos
{
    public const array AGENTS = ['malformed' => [AgentRegistryKey::WORKER => Hilos::class]];
}
