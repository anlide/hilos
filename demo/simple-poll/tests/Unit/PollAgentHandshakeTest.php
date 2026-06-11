<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Tests\Unit;

use Demo\SimplePoll\Agents\PollAgent;
use Demo\SimplePoll\Constants\CookieNames;
use Demo\SimplePoll\Constants\PollSignalConstants;
use Demo\SimplePoll\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PollAgent handshake cookie validation and agent-local identity.
 *
 * Identity is agent-local (no durable user table): the same session token keeps
 * its generated id and name for the worker lifetime, distinct tokens get fresh
 * ids. The handshake reply is captured from the signal router queue.
 */
final class PollAgentHandshakeTest extends TestCase
{
    private ?SignalRouter $previousRouter = null;
    private ?AnalyticsCollector $previousCollector = null;

    protected function setUp(): void
    {
        // sendToUser enqueues into Hilos::$sr; the collector is read nullsafe.
        $this->previousRouter = Hilos::$sr;
        $this->previousCollector = Hilos::$ac;
        Hilos::$sr = new SignalRouter();
        Hilos::$ac = null;
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousRouter;
        Hilos::$ac = $this->previousCollector;
    }

    public function testHandshakeWithoutSessionTokenCookieThrows(): void
    {
        // The contained family: the worker handshake dispatcher rejects
        // ValidationException without crashing (see WorkerManager).
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(CookieNames::SESSION_TOKEN . ' cookie is required');

        (new PollAgent())->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'poll-ak',
                cookies: [],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
            ),
            '',
            '',
        );
    }

    public function testHandshakeWithEmptySessionTokenCookieThrows(): void
    {
        $this->expectException(EmptyValueException::class);
        $this->expectExceptionMessage(CookieNames::SESSION_TOKEN . ' cookie cannot be empty');

        (new PollAgent())->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'poll-ak',
                cookies: [CookieNames::SESSION_TOKEN => ''],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
            ),
            '',
            '',
        );
    }

    public function testHandshakeWithInvalidSessionTokenCookieFormatThrows(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(CookieNames::SESSION_TOKEN . ' cookie must be a 32-character lowercase hex token');

        (new PollAgent())->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'poll-ak',
                cookies: [CookieNames::SESSION_TOKEN => 'short'],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
            ),
            '',
            '',
        );
    }

    public function testHandshakeRepliesWithGeneratedCurrentUser(): void
    {
        (new PollAgent())->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'poll-ak',
                cookies: [CookieNames::SESSION_TOKEN => str_repeat('a', 32)],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
            ),
            '',
            '',
        );

        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertInstanceOf(SignalDTO::class, $signal);
        $this->assertSame(PollSignalConstants::HANDSHAKE_RESPONSE, $signal->signalName->getName());

        $webSocketData = $signal->data;
        $this->assertInstanceOf(WebSocketSignalData::class, $webSocketData);
        $this->assertSame('poll-ak', $webSocketData->targetAcceptKey);

        $payload = $webSocketData->data;
        $this->assertInstanceOf(HandshakeResponseSignalData::class, $payload);
        $this->assertSame(1, $payload->selfId);
        $this->assertMatchesRegularExpression('/\AUser\d{4}\z/', $payload->selfName);
    }

    public function testHandshakeReturnsStableIdentityForSameToken(): void
    {
        $agent = new PollAgent();
        $token = str_repeat('b', 32);

        $first = $this->drainHandshakeReply($agent, $token, 'ak-1');
        $second = $this->drainHandshakeReply($agent, $token, 'ak-2');

        $this->assertSame($first->selfId, $second->selfId);
        $this->assertSame($first->selfName, $second->selfName);
    }

    public function testHandshakeAssignsDistinctIdsAcrossTokens(): void
    {
        $agent = new PollAgent();

        $first = $this->drainHandshakeReply($agent, str_repeat('c', 32), 'ak-1');
        $second = $this->drainHandshakeReply($agent, str_repeat('d', 32), 'ak-2');

        $this->assertSame(1, $first->selfId);
        $this->assertSame(2, $second->selfId);
    }

    /**
     * Drive one handshake and return the captured current-user reply payload.
     *
     * @param PollAgent $agent Agent under test (shared so the identity map persists across calls)
     * @param string $sessionToken Session token cookie value
     * @param string $acceptKey Connection accept key
     * @return HandshakeResponseSignalData Captured handshake reply payload
     */
    private function drainHandshakeReply(
        PollAgent $agent,
        string $sessionToken,
        string $acceptKey,
    ): HandshakeResponseSignalData {
        $agent->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: $acceptKey,
                cookies: [CookieNames::SESSION_TOKEN => $sessionToken],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
            ),
            '',
            '',
        );

        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertInstanceOf(SignalDTO::class, $signal);

        $webSocketData = $signal->data;
        $this->assertInstanceOf(WebSocketSignalData::class, $webSocketData);

        $payload = $webSocketData->data;
        $this->assertInstanceOf(HandshakeResponseSignalData::class, $payload);

        return $payload;
    }
}
