<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\BaseDTO;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Database\Context\DbContext;
use Hilos\Hilos as HilosFacade;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests HilosPageFactory::createPageSignalPayloadDTO topology-driven payload hydration.
 */
final class PageSignalPayloadDtoTest extends TestCase
{
    public function testHydratesDeclaredPayloadFromTopology(): void
    {
        $parsed = $this->factory()->createPageSignalPayloadDTO(
            SignalTypeConstants::AGENT_SIGNAL,
            PageSignalPayloadTestHilos::TYPED_SIGNAL,
            new SignalData(['message' => 'hello']),
        );

        $this->assertInstanceOf(PageSignalPayloadTestData::class, $parsed);
        $this->assertSame('hello', $parsed->message);
    }

    public function testPassesThroughPayloadThatIsAlreadyTheDeclaredType(): void
    {
        $payload = new PageSignalPayloadTestData('already-typed');

        $parsed = $this->factory()->createPageSignalPayloadDTO(
            SignalTypeConstants::AGENT_SIGNAL,
            PageSignalPayloadTestHilos::TYPED_SIGNAL,
            $payload,
        );

        $this->assertSame($payload, $parsed);
    }

    public function testPassesThroughWhenSignalDeclaresNoDto(): void
    {
        $payload = new SignalData(['message' => 'untyped']);

        $parsed = $this->factory()->createPageSignalPayloadDTO(
            SignalTypeConstants::AGENT_SIGNAL,
            'plain_test_signal',
            $payload,
        );

        $this->assertSame($payload, $parsed);
    }

    public function testHydrationFailureCarriesItsCause(): void
    {
        try {
            $this->factory()->createPageSignalPayloadDTO(
                SignalTypeConstants::AGENT_SIGNAL,
                PageSignalPayloadTestHilos::TYPED_SIGNAL,
                new SignalData([]),
            );
            $this->fail('Expected InvalidAgentSignalPayloadException');
        } catch (InvalidAgentSignalPayloadException $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
            $this->assertStringContainsString('message is required', $e->getMessage());
        }
    }

    public function testSignalDataInterfaceDeclaresStaticFromArray(): void
    {
        $factory = new ReflectionMethod(SignalDataInterface::class, 'fromArray');

        $this->assertTrue($factory->isStatic());
    }

    /**
     * Builds the factory pinned to the page signal DTO fixture facade.
     *
     * @return HilosPageFactory Factory under test
     */
    private function factory(): HilosPageFactory
    {
        return new HilosPageFactory(new PageSignalPayloadTestAgent(), PageSignalPayloadTestHilos::class);
    }
}

/**
 * Test payload DTO requiring a non-empty 'message' field.
 */
final class PageSignalPayloadTestData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $message Required payload message
     */
    public function __construct(public readonly string $message)
    {
    }

    /**
     * @return array<string, mixed> Wire payload
     */
    public function toArray(): array
    {
        return ['message' => $this->message];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Restored payload
     */
    public static function fromArray(array $data): static
    {
        $message = $data['message'] ?? null;
        if (!is_string($message) || $message === '') {
            throw new \InvalidArgumentException('message is required');
        }

        return new static($message);
    }
}

/**
 * Test facade declaring a page signal DTO route for the typed signal.
 */
final class PageSignalPayloadTestHilos extends HilosFacade
{
    public const string TYPED_SIGNAL = 'typed_test_signal';

    /**
     * @return array<string, array<string, class-string<SignalDataInterface>>> DTO class keyed by signal type, then signal name
     */
    public static function getPageSignalDtoRoutes(): array
    {
        return [
            SignalTypeConstants::AGENT_SIGNAL => [
                self::TYPED_SIGNAL => PageSignalPayloadTestData::class,
            ],
        ];
    }

    protected static function createDb(): DbContext
    {
        throw new \LogicException('createDb is not used in the page signal payload test');
    }
}

/**
 * Minimal page agent for the factory fixture.
 */
final class PageSignalPayloadTestAgent implements PageAgentInterface
{
    /**
     * @return string Agent id
     */
    public function getId(): string
    {
        return 'page-signal-payload-test-agent';
    }

    /**
     * @return SignalSourceInterface Signal source
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'page-signal-payload-test');
    }
}
