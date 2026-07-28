<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Sms;

use Hilos\API\DTO\AsyncHttpResponse;
use Hilos\Constants\HttpConstants;
use Hilos\Sms\GenericHttpSmsProvider;
use Hilos\Sms\SmsChannelConfig;
use Hilos\Sms\SmsMessage;
use PHPUnit\Framework\TestCase;

/**
 * Tests the config-driven SMS gateway provider (HIL-285).
 *
 * Covers the three pieces the provider owns with no I/O: building a request from the field map
 * and auth mode, interpreting a delivered response against the success rule, and classifying a
 * non-2xx status as permanent (4xx) or transient.
 */
final class GenericHttpSmsProviderTest extends TestCase
{
    public function testPostRequestMapsFieldsAndAppliesHeaderAuth(): void
    {
        $provider = new GenericHttpSmsProvider($this->config(
            httpMethod: 'POST',
            authMode: SmsChannelConfig::AUTH_MODE_HEADER,
            fieldMap: ['to' => 'phone', 'text' => 'message', 'from' => 'sender'],
        ));

        $request = $provider->buildRequest(new SmsMessage('+15551234567', 'hi there'));

        self::assertSame('gw.example.com', $request->host);
        self::assertSame(443, $request->port);
        self::assertTrue($request->useTls);
        self::assertSame(HttpConstants::METHOD_POST, $request->method);
        self::assertSame('/send', $request->path);
        self::assertSame('Bearer secret-key', $request->headers['Authorization']);
        self::assertNotNull($request->body);
        self::assertStringContainsString('phone=%2B15551234567', $request->body);
        self::assertStringContainsString('message=hi%20there', $request->body);
        self::assertStringContainsString('sender=HILOS', $request->body);
    }

    public function testGetRequestPutsMappedFieldsAndQueryAuthInThePath(): void
    {
        $provider = new GenericHttpSmsProvider($this->config(
            httpMethod: 'GET',
            authMode: SmsChannelConfig::AUTH_MODE_QUERY,
            fieldMap: ['to' => 'to', 'text' => 'body', 'from' => 'from'],
        ));

        $request = $provider->buildRequest(new SmsMessage('+15551234567', 'code'));

        self::assertSame(HttpConstants::METHOD_GET, $request->method);
        self::assertNull($request->body);
        self::assertStringContainsString('to=%2B15551234567', $request->path);
        self::assertStringContainsString('body=code', $request->path);
        self::assertStringContainsString('api_key=secret-key', $request->path);
    }

    public function testParseResponseHonoursSuccessRule(): void
    {
        $provider = new GenericHttpSmsProvider($this->config(successRule: 'OK'));

        self::assertTrue($provider->parseResponse($this->response('{"status":"OK"}'))->delivered);

        $rejected = $provider->parseResponse($this->response('{"status":"FAIL"}'));
        self::assertFalse($rejected->delivered);
        self::assertTrue($rejected->permanent);
    }

    public function testParseResponseWithoutRuleTreatsAny2xxAsDelivered(): void
    {
        $provider = new GenericHttpSmsProvider($this->config(successRule: ''));

        self::assertTrue($provider->parseResponse($this->response('anything'))->delivered);
    }

    public function testFourxxIsPermanentAndFivexxIsTransient(): void
    {
        $provider = new GenericHttpSmsProvider($this->config());

        $clientError = $provider->classifyStatus(404);
        self::assertFalse($clientError->delivered);
        self::assertTrue($clientError->permanent);

        $serverError = $provider->classifyStatus(503);
        self::assertFalse($serverError->delivered);
        self::assertFalse($serverError->permanent);
    }

    /**
     * Builds a gateway config for the tests, defaulting the fields the case does not vary.
     *
     * @param string $httpMethod HTTP method
     * @param string $authMode Auth mode
     * @param array<string, string> $fieldMap Logical-to-gateway param map
     * @param string $successRule Body success substring
     * @return SmsChannelConfig Gateway config
     */
    private function config(
        string $httpMethod = 'POST',
        string $authMode = SmsChannelConfig::AUTH_MODE_NONE,
        array $fieldMap = ['to' => 'to', 'text' => 'text', 'from' => 'from'],
        string $successRule = '',
    ): SmsChannelConfig {
        return new SmsChannelConfig(
            provider: SmsChannelConfig::PROVIDER_GENERIC,
            endpointUrl: 'https://gw.example.com/send',
            httpMethod: $httpMethod,
            authMode: $authMode,
            fieldMap: $fieldMap,
            successRule: $successRule,
            from: 'HILOS',
            timeoutMs: 5000,
            maxLength: 160,
            fileDir: '',
            apiKey: 'secret-key',
            apiPassword: 'secret-pass',
        );
    }

    /**
     * Builds a completed 2xx gateway response with the given body.
     *
     * @param string $body Response body
     * @return AsyncHttpResponse Completed response
     */
    private function response(string $body): AsyncHttpResponse
    {
        return new AsyncHttpResponse(200, '', $body);
    }
}
