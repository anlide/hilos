<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\BaseDTO;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Database\Context\DbContext;
use Hilos\Hilos as HilosFacade;
use PHPUnit\Framework\TestCase;

/**
 * Tests indexed multi-instance agent routing through AGENT_SIGNALS INDEX_FIELD config.
 */
final class SignalRouterIndexedAgentSignalTest extends TestCase
{
    public function testIndexedSignalRoutesToAgentWithExtractedIndex(): void
    {
        $destinations = (new IndexedAgentSignalTestRouter())->getDestinations(
            $this->agentSignal(IndexedAgentSignalTestAgent::INDEXED_SIGNAL, ['entityId' => 42]),
        );

        $this->assertSame([
            ['type' => 'agent', 'agentType' => IndexedAgentSignalTestAgent::AGENT_TYPE, 'agentIndex' => '42'],
        ], $destinations);
    }

    public function testIndexedSignalAcceptsStringIndex(): void
    {
        $destinations = (new IndexedAgentSignalTestRouter())->getDestinations(
            $this->agentSignal(IndexedAgentSignalTestAgent::INDEXED_SIGNAL, ['entityId' => 'abc']),
        );

        $this->assertSame([
            ['type' => 'agent', 'agentType' => IndexedAgentSignalTestAgent::AGENT_TYPE, 'agentIndex' => 'abc'],
        ], $destinations);
    }

    public function testIndexedSignalReturnsEmptyWhenFieldIsZero(): void
    {
        $destinations = (new IndexedAgentSignalTestRouter())->getDestinations(
            $this->agentSignal(IndexedAgentSignalTestAgent::INDEXED_SIGNAL, ['entityId' => 0]),
        );

        $this->assertSame([], $destinations);
    }

    public function testIndexedSignalReturnsEmptyWhenFieldIsEmptyString(): void
    {
        $destinations = (new IndexedAgentSignalTestRouter())->getDestinations(
            $this->agentSignal(IndexedAgentSignalTestAgent::INDEXED_SIGNAL, ['entityId' => '']),
        );

        $this->assertSame([], $destinations);
    }

    public function testIndexedSignalReturnsEmptyWhenFieldIsMissing(): void
    {
        $destinations = (new IndexedAgentSignalTestRouter())->getDestinations(
            $this->agentSignal(IndexedAgentSignalTestAgent::INDEXED_SIGNAL, []),
        );

        $this->assertSame([], $destinations);
    }

    public function testIndexedSignalReturnsEmptyWhenFieldIsNull(): void
    {
        $destinations = (new IndexedAgentSignalTestRouter())->getDestinations(
            $this->agentSignal(IndexedAgentSignalTestAgent::INDEXED_SIGNAL, ['entityId' => null]),
        );

        $this->assertSame([], $destinations);
    }

    public function testSingletonSignalOnMixedAgentRoutesWithNullIndex(): void
    {
        $destinations = (new IndexedAgentSignalTestRouter())->getDestinations(
            $this->agentSignal(IndexedAgentSignalTestAgent::SINGLETON_SIGNAL, []),
        );

        $this->assertSame([
            ['type' => 'agent', 'agentType' => IndexedAgentSignalTestAgent::AGENT_TYPE, 'agentIndex' => null],
        ], $destinations);
    }

    public function testIndexedSignalDoesNotRouteFromNonAgentSource(): void
    {
        $destinations = (new IndexedAgentSignalTestRouter())->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(IndexedAgentSignalTestAgent::INDEXED_SIGNAL),
            new AgentSignalData(new IndexedAgentSignalTestPayload(['entityId' => 42])),
        ));

        $this->assertSame([], $destinations);
    }

    /**
     * Builds an AGENT_SIGNAL DTO with an in-memory inner payload.
     *
     * @param string $signalName Signal name
     * @param array<string, mixed> $payloadData Inner payload data array
     */
    private function agentSignal(string $signalName, array $payloadData): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName($signalName),
            new AgentSignalData(new IndexedAgentSignalTestPayload($payloadData)),
        );
    }
}

final class IndexedAgentSignalTestRouter extends SignalRouter
{
    /**
     * Returns fixture facade for topology reads.
     *
     * @return class-string<HilosFacade> Fixture facade class
     */
    protected function hilosClass(): string
    {
        return IndexedAgentSignalTestHilos::class;
    }
}

final class IndexedAgentSignalTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'indexed_test_agent';

    public const string INDEXED_SIGNAL = 'indexed_test_signal';

    public const string SINGLETON_SIGNAL = 'singleton_test_signal';

    public const array AGENT_SIGNALS = [
        self::SINGLETON_SIGNAL,
        self::INDEXED_SIGNAL => [
            AgentSignalConfigKey::INDEX_FIELD => 'entityId',
        ],
    ];

    /**
     * No-op stop hook for indexed agent signal tests.
     */
    public function onStop(): void
    {
    }
}

final class IndexedAgentSignalTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for indexed agent signal tests.
     */
    public function configure(): void
    {
    }
}

final class IndexedAgentSignalTestHilos extends HilosFacade
{
    public const array AGENTS = [
        IndexedAgentSignalTestAgent::AGENT_TYPE => IndexedAgentSignalTestAgent::class,
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new IndexedAgentSignalTestDbContext();
    }
}

/**
 * Minimal inner payload DTO whose toArray() returns the given data array.
 */
final class IndexedAgentSignalTestPayload extends BaseDTO implements SignalDataInterface
{
    /**
     * @param array<string, mixed> $data Payload data
     */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new self($data);
    }
}
