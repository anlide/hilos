<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\BaseDTO;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
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

    public function testParsesAgentSignalPayloadFromTopology(): void
    {
        $router = new IndexedAgentSignalDtoTestRouter();
        $parsed = $router->createAgentSignalPayloadDTO(
            IndexedAgentSignalDtoTestAgent::TYPED_SIGNAL,
            new AgentSignalData(new SignalData(['message' => 'hello'])),
        );

        $this->assertInstanceOf(IndexedAgentSignalDtoTestPayload::class, $parsed->data);
        $this->assertSame('hello', $parsed->data->message);
    }

    public function testTypedAgentSignalPayloadPassesThroughUnchanged(): void
    {
        $router = new IndexedAgentSignalDtoTestRouter();
        $payload = new IndexedAgentSignalDtoTestPayload('already-typed');
        $signalData = new AgentSignalData($payload);

        $parsed = $router->createAgentSignalPayloadDTO(
            IndexedAgentSignalDtoTestAgent::TYPED_SIGNAL,
            $signalData,
        );

        $this->assertSame($signalData, $parsed);
        $this->assertSame($payload, $parsed->data);
    }

    public function testInvalidAgentSignalPayloadThrowsWhenTopologyDtoDoesNotMatch(): void
    {
        $router = new IndexedAgentSignalDtoTestRouter();

        $this->expectException(InvalidAgentSignalPayloadException::class);
        $this->expectExceptionMessage(IndexedAgentSignalDtoTestPayload::class);

        $router->createAgentSignalPayloadDTO(
            IndexedAgentSignalDtoTestAgent::TYPED_SIGNAL,
            new AgentSignalData(new IndexedAgentSignalTestPayload(['entityId' => 42])),
        );
    }

    public function testIndexedSignalRoutesWithDeclaredDto(): void
    {
        $destinations = (new IndexedAgentSignalDtoTestRouter())->getDestinations(
            $this->agentSignal(
                IndexedAgentSignalDtoTestAgent::INDEXED_TYPED_SIGNAL,
                ['entityId' => 7, 'message' => 'route-me'],
            ),
        );

        $this->assertSame([
            ['type' => 'agent', 'agentType' => IndexedAgentSignalDtoTestAgent::AGENT_TYPE, 'agentIndex' => '7'],
        ], $destinations);
    }

    public function testParsesIndexedAgentSignalPayloadFromTopology(): void
    {
        $router = new IndexedAgentSignalDtoTestRouter();
        $parsed = $router->createAgentSignalPayloadDTO(
            IndexedAgentSignalDtoTestAgent::INDEXED_TYPED_SIGNAL,
            new AgentSignalData(new SignalData(['entityId' => 7, 'message' => 'route-me'])),
        );

        $this->assertInstanceOf(IndexedAgentSignalDtoTestPayload::class, $parsed->data);
        $this->assertSame(7, $parsed->data->entityId);
        $this->assertSame('route-me', $parsed->data->message);
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

final class IndexedAgentSignalDtoTestRouter extends SignalRouter
{
    /**
     * Returns DTO fixture facade for topology reads.
     *
     * @return class-string<HilosFacade> Fixture facade class
     */
    protected function hilosClass(): string
    {
        return IndexedAgentSignalDtoTestHilos::class;
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

final class IndexedAgentSignalDtoTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'indexed_dto_test_agent';

    public const string TYPED_SIGNAL = 'typed_test_signal';

    public const string INDEXED_TYPED_SIGNAL = 'indexed_typed_test_signal';

    public const array AGENT_SIGNALS = [
        self::TYPED_SIGNAL => IndexedAgentSignalDtoTestPayload::class,
        self::INDEXED_TYPED_SIGNAL => [
            AgentSignalConfigKey::INDEX_FIELD => 'entityId',
            AgentSignalConfigKey::DTO => IndexedAgentSignalDtoTestPayload::class,
        ],
    ];

    /**
     * No-op stop hook for indexed agent signal DTO tests.
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

final class IndexedAgentSignalTestAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'indexed_test_agent';
}

final class IndexedAgentSignalTestHilos extends HilosFacade
{
    public const array AGENTS = [
        IndexedAgentSignalTestAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => IndexedAgentSignalTestAgent::class,
            AgentRegistryKey::DAEMON => IndexedAgentSignalTestAgentDaemon::class,
        ],
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

final class IndexedAgentSignalDtoTestAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'indexed_dto_test_agent';
}

final class IndexedAgentSignalDtoTestHilos extends HilosFacade
{
    public const array AGENTS = [
        IndexedAgentSignalDtoTestAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => IndexedAgentSignalDtoTestAgent::class,
            AgentRegistryKey::DAEMON => IndexedAgentSignalDtoTestAgentDaemon::class,
        ],
        IndexedAgentSignalTestAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => IndexedAgentSignalTestAgent::class,
            AgentRegistryKey::DAEMON => IndexedAgentSignalTestAgentDaemon::class,
        ],
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

/**
 * Typed inner payload DTO for agent signal topology parsing tests.
 */
final class IndexedAgentSignalDtoTestPayload extends BaseDTO implements SignalDataInterface
{
    /**
     * @param ?int $entityId Indexed routing field
     */
    public function __construct(
        public readonly string $message = '',
        public readonly ?int $entityId = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['message' => $this->message];
        if ($this->entityId !== null) {
            $data['entityId'] = $this->entityId;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists('message', $data)) {
            throw new \InvalidArgumentException('Missing message');
        }

        $entityId = $data['entityId'] ?? null;

        return new self(
            message: is_string($data['message']) ? $data['message'] : '',
            entityId: is_int($entityId) ? $entityId : null,
        );
    }
}
