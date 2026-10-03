<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\LLM;

use Hilos\API\AsyncHttpClient;
use Hilos\Constants\HttpConstants;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\DTO\MessageImage;
use Hilos\LLM\External\Chat\AsyncOpenAIChatProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class AsyncOpenAIChatProviderTest extends TestCase
{
    /** A picture becomes an image_url part while text-only turns stay strings. */
    public function testRequestCarriesImageParts(): void
    {
        $provider = new AsyncOpenAIChatProvider('https://127.0.0.1', 'test-key', 'vision');
        $client = new CapturedOpenAIHttpClient('127.0.0.1', 443, '/v1/chat/completions', useTls: true);
        new ReflectionProperty(AsyncOpenAIChatProvider::class, 'httpClient')->setValue($provider, $client);

        try {
            $provider->startGenerate([
                new Message(Message::ROLE_SYSTEM, 'Inspect the picture'),
                new Message(Message::ROLE_USER, 'What is shown?', [new MessageImage('image/jpeg', 'AAEC')]),
            ], new ChatGenerateOptions());

            $request = '';
            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline) {
                $provider->tick(microtime(true) * 1000);
                $chunk = fread($client->peer, 8192);
                if (is_string($chunk)) {
                    $request .= $chunk;
                }
                if (str_contains($request, HttpConstants::HTTP_DELIMITER)) {
                    [$head, $body] = explode(HttpConstants::HTTP_DELIMITER, $request, 2);
                    preg_match('/Content-Length:\s*(\d+)/i', $head, $lengthMatch);
                    if (strlen($body) >= (int)($lengthMatch[1] ?? 0)) {
                        break;
                    }
                }
                usleep(1000);
            }

            $this->assertStringContainsString(HttpConstants::HTTP_DELIMITER, $request);
            $decoded = json_decode(explode(HttpConstants::HTTP_DELIMITER, $request, 2)[1], true);
            $this->assertSame('Inspect the picture', $decoded['messages'][0]['content']);
            $this->assertSame([
                ['type' => 'text', 'text' => 'What is shown?'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,AAEC']],
            ], $decoded['messages'][1]['content']);
        } finally {
            $provider->reset();
            if (is_resource($client->peer)) {
                fclose($client->peer);
            }
        }
    }
}

/** Socket-pair peer records the raw TLS-bound request after a scripted handshake. */
final class CapturedOpenAIHttpClient extends AsyncHttpClient
{
    /** @var resource Peer end of the socket pair */
    public $peer;

    /** @return resource Client end of the socket pair */
    protected function establishSocket()
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);
        $this->peer = $pair[1];

        return $pair[0];
    }

    /** @return bool The scripted handshake has completed */
    protected function enableCrypto(?string &$warning): int|bool
    {
        return true;
    }
}
