<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\API\AsyncHttpClient;
use Hilos\API\Exception\AsyncHttpBusyException;
use Hilos\API\Exception\AsyncHttpStatusException;
use Hilos\Constants\HttpConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for async HTTP client result and exception contracts.
 */
final class AsyncHttpClientTest extends TestCase
{
    /**
     * Successful responses are consumed once through consumeResult().
     */
    public function testConsumesSuccessfulResponse(): void
    {
        [$server, $port] = $this->createServer();

        try {
            $client = new AsyncHttpClient('127.0.0.1', $port, '/status');
            $client->startNewRequest(microtime(true) * 1000);
            $this->serveResponseUntilFinished($server, $client, $this->response(200, 'hello'));

            $this->assertTrue($client->hasResult());
            $response = $client->consumeResult();
            $this->assertSame(200, $response->statusCode);
            $this->assertSame('hello', $response->body);
            $this->assertFalse($client->hasResult());
        } finally {
            fclose($server);
        }
    }

    /**
     * Starting a second request while connecting fails through exception.
     */
    public function testStartNewRequestThrowsWhenBusy(): void
    {
        [$server, $port] = $this->createServer();

        try {
            $client = new AsyncHttpClient('127.0.0.1', $port, '/status');
            $client->startNewRequest(microtime(true) * 1000);

            $this->expectException(AsyncHttpBusyException::class);
            $client->startNewRequest(microtime(true) * 1000);
        } finally {
            fclose($server);
        }
    }

    /**
     * Non-success HTTP status codes are failures, not false result payloads.
     */
    public function testNonSuccessStatusThrows(): void
    {
        [$server, $port] = $this->createServer();

        try {
            $client = new AsyncHttpClient('127.0.0.1', $port, '/status');
            $client->startNewRequest(microtime(true) * 1000);

            $this->expectException(AsyncHttpStatusException::class);
            $this->serveResponseUntilFinished($server, $client, $this->response(500, 'broken'));
        } finally {
            fclose($server);
        }
    }

    /**
     * Creates a non-blocking local TCP server.
     *
     * @return array{0: resource, 1: int} Server socket and port
     */
    private function createServer(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, $errstr);
        stream_set_blocking($server, false);

        $name = stream_socket_get_name($server, false);
        $this->assertIsString($name);
        $port = (int)substr($name, strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port);

        return [$server, $port];
    }

    /**
     * Runs the client/server loop until the client result or exception arrives.
     *
     * @param resource $server Server socket
     * @param AsyncHttpClient $client Client under test
     * @param string $response Raw HTTP response to send
     */
    private function serveResponseUntilFinished($server, AsyncHttpClient $client, string $response): void
    {
        $connection = null;
        $requestBuffer = '';
        $deadline = microtime(true) + 2.0;

        try {
            while (!$client->hasResult() && microtime(true) < $deadline) {
                $client->tick(microtime(true) * 1000);

                if ($connection === null) {
                    $read = [$server];
                    $write = [];
                    $except = [];
                    if (stream_select($read, $write, $except, 0, 0) > 0) {
                        $accepted = stream_socket_accept($server, 0);
                        if (is_resource($accepted)) {
                            $connection = $accepted;
                            stream_set_blocking($connection, false);
                        }
                    }
                }

                if (is_resource($connection)) {
                    $chunk = fread($connection, 8192);
                    if (is_string($chunk) && $chunk !== '') {
                        $requestBuffer .= $chunk;
                    }

                    if (str_contains($requestBuffer, HttpConstants::HTTP_DELIMITER)) {
                        fwrite($connection, $response);
                        fclose($connection);
                        $connection = null;
                    }
                }

                usleep(1000);
            }
        } finally {
            if (is_resource($connection)) {
                fclose($connection);
            }
        }

        $this->assertTrue($client->hasResult(), 'Async HTTP client did not finish within timeout');
    }

    /**
     * Builds a raw HTTP response.
     *
     * @param int $statusCode HTTP status code
     * @param string $body Response body
     * @return string Raw HTTP response
     */
    private function response(int $statusCode, string $body): string
    {
        return "HTTP/1.1 {$statusCode} Test" . HttpConstants::HTTP_LINE_SEPARATOR
            . HttpConstants::HEADER_CONTENT_LENGTH . ': ' . strlen($body) . HttpConstants::HTTP_LINE_SEPARATOR
            . HttpConstants::HEADER_CONNECTION . ': close'
            . HttpConstants::HTTP_DELIMITER
            . $body;
    }
}
