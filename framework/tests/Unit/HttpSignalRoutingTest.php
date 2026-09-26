<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\HttpReplyDestination;
use Hilos\Core\Router\Destination\RemoteHttpReplyDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Tests SignalRouter routing for the two signals of an agent-answered HTTP address.
 */
final class HttpSignalRoutingTest extends TestCase
{
    /** Address the fixture agent declares */
    private const string AGENT_PATH = '/_test/file';

    /** Agent type the fixture address routes to */
    private const string AGENT_TYPE = 'test_files_agent';

    public function testAnHttpRequestRoutesToTheAgentThatDeclaresItsAddress(): void
    {
        $router = new HttpRoutingTestRouter();
        $signal = $this->requestSignal(self::AGENT_PATH);

        $this->assertEquals([new AgentDestination(self::AGENT_TYPE)], $router->getDestinations($signal));
    }

    public function testAnHttpRequestToAnUndeclaredAddressRoutesNowhereAndIsReportedAsMissing(): void
    {
        $router = new HttpRoutingTestRouter();
        $signal = $this->requestSignal('/_test/elsewhere');

        $this->assertSame([], $router->getDestinations($signal));
        $this->assertTrue($router->expectsDestination($signal), 'an empty route is a lost request, not an idle one');
    }

    public function testAReplyWithoutAnOriginIsWrittenToTheConnectionHeldHere(): void
    {
        $this->assertEquals(
            [new HttpReplyDestination('corr-1')],
            new SignalRouter()->getDestinations($this->replySignal('corr-1', null)),
        );
    }

    public function testAReplyNamingAnotherNodeTravelsToTheNodeHoldingTheConnection(): void
    {
        $this->assertEquals(
            [new RemoteHttpReplyDestination('node-b', 'corr-2')],
            new SignalRouter()->getDestinations($this->replySignal('corr-2', 'node-b')),
        );
    }

    public function testAReplyWithoutACorrelationIdRoutesNowhere(): void
    {
        $this->assertSame([], new SignalRouter()->getDestinations($this->replySignal('', null)));
    }

    /**
     * An HTTP_REQUEST as the master queues it.
     *
     * @param string $path Requested path
     * @return SignalDTO Request signal
     */
    private function requestSignal(string $path): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::HTTP_REQUEST),
            new SignalName(HttpConstants::METHOD_GET . ' ' . $path),
            new HttpRequestDTO('corr-0', HttpConstants::METHOD_GET, $path, ['id' => '1'], null, null),
        );
    }

    /**
     * An HTTP_REPLY as an agent queues it.
     *
     * @param string $correlationId Correlation id of the parked request
     * @param ?string $originNodeId Node holding the connection, null off a cluster
     * @return SignalDTO Reply signal
     */
    private function replySignal(string $correlationId, ?string $originNodeId): SignalDTO
    {
        $request = new HttpRequestDTO($correlationId, HttpConstants::METHOD_GET, self::AGENT_PATH, [], null, $originNodeId);

        return new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::HTTP_REPLY),
            new SignalName(SignalTypeConstants::HTTP_REPLY),
            HttpReplyDTO::refusal($request, HttpConstants::HTTP_NOT_FOUND),
        );
    }
}

/**
 * Test facade declaring one agent-answered address, for HTTP_REQUEST routing.
 */
final class HttpRoutingTestHilos extends Hilos
{
    /**
     * @return array<string, array<string, string>> Agent type keyed by method, then by path
     */
    public static function getHttpAgentRoutes(): array
    {
        return [HttpConstants::METHOD_GET => ['/_test/file' => 'test_files_agent']];
    }

    protected static function createDb(): HilosDbContext
    {
        throw new LogicException('createDb is not used in the routing test');
    }
}

/**
 * Test router pinning the facade to the one-address fixture.
 */
final class HttpRoutingTestRouter extends SignalRouter
{
    protected function hilosClass(): string
    {
        return HttpRoutingTestHilos::class;
    }
}
