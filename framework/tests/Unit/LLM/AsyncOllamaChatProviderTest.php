<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\LLM;

use Hilos\API\Exception\AsyncHttpTlsHandshakeException;
use Hilos\Constants\HttpConstants;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\Exception\LLMRequestException;
use Hilos\LLM\Local\Chat\AsyncOllamaChatProvider;
use Hilos\Tests\Unit\AsyncHttpClientTest;
use PHPUnit\Framework\TestCase;

/**
 * Tests that the scheme of the local model's address decides whether the connection is encrypted.
 *
 * Both cases talk to one plain HTTP peer on purpose. The subject is the choice the provider makes
 * from its address, not the work of TLS itself - a real TLS peer is already held by
 * {@see AsyncHttpClientTest::testHttpsRequestOverRealTlsServer()} - and a plain peer shows the
 * choice both ways: an http address still reaches it, an https address cannot finish a handshake
 * with it (HIL-925).
 */
final class AsyncOllamaChatProviderTest extends TestCase
{
    /** Model name the calls carry; the peer does not read it. */
    private const string MODEL = 'stand';

    /**
     * An http address is reached without encryption, and the answer still arrives.
     */
    public function testPlainAddressCarriesTheAnswer(): void
    {
        [$server, $port] = $this->createServer();

        try {
            $provider = new AsyncOllamaChatProvider('http://127.0.0.1:' . $port, self::MODEL);
            $provider->startGenerate([['role' => 'user', 'content' => 'hello']], new ChatGenerateOptions());
            $this->serveUntilFinished($server, $provider, '{"response":"granted"}');

            $this->assertSame('granted', $provider->consumeResult());
        } finally {
            fclose($server);
        }
    }

    /**
     * An https address is reached over TLS: against a plain peer the request fails on the handshake.
     */
    public function testHttpsAddressEncryptsTheConnection(): void
    {
        [$server, $port] = $this->createServer();

        try {
            $provider = new AsyncOllamaChatProvider('https://127.0.0.1:' . $port, self::MODEL);
            $provider->startGenerate([['role' => 'user', 'content' => 'hello']], new ChatGenerateOptions());

            try {
                $this->serveUntilFinished($server, $provider, '{"response":"granted"}');
                $this->fail('A plain peer answered a request the provider was to send over TLS');
            } catch (LLMRequestException $failure) {
                $this->assertInstanceOf(AsyncHttpTlsHandshakeException::class, $failure->getPrevious());
            }
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
     * Runs the provider against a plain HTTP peer until its result or its failure arrives.
     *
     * The peer behaves as a plain server does: a request is answered once its head has arrived, and
     * bytes that cannot open an HTTP request - a TLS handshake among them - get a 400 and a hang-up.
     *
     * @param resource $server Server socket
     * @param AsyncOllamaChatProvider $provider Provider under test
     * @param string $body Body of the answer to a well-formed request
     */
    private function serveUntilFinished($server, AsyncOllamaChatProvider $provider, string $body): void
    {
        $connection = null;
        $requestBuffer = '';
        $deadline = microtime(true) + 2.0;

        try {
            while (!$provider->hasResult() && microtime(true) < $deadline) {
                $provider->tick(microtime(true) * 1000);

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

                    if ($requestBuffer !== '' && preg_match('/^[A-Z]/', $requestBuffer) !== 1) {
                        fwrite($connection, $this->response(400, 'Bad Request'));
                        fclose($connection);
                        $connection = null;
                    } elseif (str_contains($requestBuffer, HttpConstants::HTTP_DELIMITER)) {
                        fwrite($connection, $this->response(200, $body));
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

        $this->assertTrue($provider->hasResult(), 'Ollama provider did not finish within timeout');
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
