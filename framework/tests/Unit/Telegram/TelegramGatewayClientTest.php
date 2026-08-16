<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Telegram;

use Hilos\API\DTO\AsyncHttpResponse;
use Hilos\Constants\HttpConstants;
use Hilos\Telegram\Exception\TelegramGatewayException;
use Hilos\Telegram\TelegramGatewayClient;
use Hilos\Telegram\TelegramGatewayConfig;
use PHPUnit\Framework\TestCase;

/**
 * The Telegram Gateway recipe: what goes on the wire, and what is read back off it.
 *
 * This is the whole value of a client that does no I/O (HIL-492) - the provider
 * contract can be pinned exactly, without a socket and without a mock server, so the
 * e2e stand is left to prove the flow rather than the field names.
 *
 * What is worth pinning is what a wrong guess would cost silently: the `request_id`
 * carried from the reachability call into the send is what makes the pair a single
 * charge, so losing it does not break anything visible - it just doubles the bill.
 */
final class TelegramGatewayClientTest extends TestCase
{
    private const string TOKEN = 'test-gateway-token';

    private const string PHONE = '+15551234567';

    private const string CODE = '424242';

    public function testTheReachabilityCallPostsTheNumberWithTheBearerToken(): void
    {
        $request = $this->client()->buildCheckSendAbility(self::PHONE);

        self::assertSame('gatewayapi.telegram.org', $request->host);
        self::assertSame(443, $request->port);
        self::assertTrue($request->useTls);
        self::assertSame(HttpConstants::METHOD_POST, $request->method);
        self::assertSame(TelegramGatewayClient::PATH_CHECK_SEND_ABILITY, $request->path);
        self::assertSame('Bearer ' . self::TOKEN, $request->headers['Authorization'] ?? null);
        self::assertSame('phone_number=%2B15551234567', $request->body);
    }

    public function testTheSendCarriesTheCodeTheSenderAndTheProbeRequestId(): void
    {
        $request = $this->client(senderUsername: 'HilosDemo')
            ->buildSendVerificationMessage(self::PHONE, self::CODE, 'req-99', 900);

        self::assertSame(TelegramGatewayClient::PATH_SEND_VERIFICATION_MESSAGE, $request->path);
        parse_str((string)$request->body, $sent);
        self::assertSame(
            [
                'phone_number' => self::PHONE,
                'code' => self::CODE,
                'request_id' => 'req-99',
                'sender_username' => 'HilosDemo',
                'ttl' => '900',
            ],
            $sent,
        );
    }

    public function testTheSendOmitsTheFieldsTheCallerLeftUnset(): void
    {
        $request = $this->client()->buildSendVerificationMessage(self::PHONE, self::CODE);

        parse_str((string)$request->body, $sent);
        self::assertSame(['phone_number' => self::PHONE, 'code' => self::CODE], $sent);
    }

    public function testACustomEndpointIsHonoredWithItsSchemePortAndBasePath(): void
    {
        $client = $this->client(endpointUrl: 'http://telegram-mock-test:18000/gw');
        $request = $client->buildCheckSendAbility(self::PHONE);

        self::assertSame('telegram-mock-test', $request->host);
        self::assertSame(18000, $request->port);
        self::assertFalse($request->useTls);
        self::assertSame('/gw' . TelegramGatewayClient::PATH_CHECK_SEND_ABILITY, $request->path);
    }

    public function testAnUnconfiguredGatewayRefusesToBuildACallAtAll(): void
    {
        $client = new TelegramGatewayClient(
            new TelegramGatewayConfig(TelegramGatewayConfig::DEFAULT_ENDPOINT_URL, '', '', 5000),
        );

        $this->expectException(TelegramGatewayException::class);
        $client->buildCheckSendAbility(self::PHONE);
    }

    public function testAnEndpointWithNoHostRefusesToBuildACall(): void
    {
        $client = $this->client(endpointUrl: 'not-a-url');

        $this->expectException(TelegramGatewayException::class);
        $client->buildCheckSendAbility(self::PHONE);
    }

    public function testAnAcceptedAnswerYieldsItsResultAndItsRequestId(): void
    {
        $client = $this->client();
        $result = $client->readResponse($this->response('{"ok":true,"result":{"request_id":"req-7"}}'));

        self::assertTrue($result->accepted);
        self::assertSame('req-7', $client->readRequestId($result));
    }

    public function testAnAcceptedAnswerWithNoRequestIdYieldsNullRatherThanAnEmptyString(): void
    {
        $client = $this->client();
        $result = $client->readResponse($this->response('{"ok":true,"result":{"request_id":""}}'));

        self::assertTrue($result->accepted);
        self::assertNull($client->readRequestId($result), 'An empty handle must not be quoted back as one');
    }

    public function testARefusedAnswerCarriesTheGatewayErrorAsALogSentence(): void
    {
        $result = $this->client()->readResponse($this->response('{"ok":false,"error":"ACCESS_TOKEN_INVALID"}'));

        self::assertFalse($result->accepted);
        self::assertSame('gateway refused: ACCESS_TOKEN_INVALID', $result->reason);
    }

    public function testANonSuccessStatusIsRefusedAndNamesTheStatus(): void
    {
        $result = $this->client()->readResponse($this->response('{"ok":true}', statusCode: 500));

        self::assertFalse($result->accepted);
        self::assertSame('gateway returned HTTP 500', $result->reason);
    }

    public function testABodyThatIsNotJsonIsRefusedRatherThanReadAsSuccess(): void
    {
        $result = $this->client()->readResponse($this->response('<html>gateway down</html>'));

        self::assertFalse($result->accepted);
        self::assertSame('gateway answered a body that is not a JSON object', $result->reason);
    }

    /**
     * Builds a client over a configured Gateway.
     *
     * @param string $endpointUrl Gateway base URL
     * @param string $senderUsername Sender username shown on the message
     * @return TelegramGatewayClient Client under test
     */
    private function client(
        string $endpointUrl = TelegramGatewayConfig::DEFAULT_ENDPOINT_URL,
        string $senderUsername = '',
    ): TelegramGatewayClient {
        return new TelegramGatewayClient(
            new TelegramGatewayConfig($endpointUrl, self::TOKEN, $senderUsername, 5000),
        );
    }

    /**
     * @param string $body Response body
     * @param int $statusCode HTTP status the Gateway answered with
     * @return AsyncHttpResponse Completed response to read
     */
    private function response(string $body, int $statusCode = 200): AsyncHttpResponse
    {
        return new AsyncHttpResponse($statusCode, '', $body);
    }
}
