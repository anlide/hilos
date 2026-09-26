<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Hilos\Constants\HttpConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use PHPUnit\Framework\TestCase;

/**
 * The two frames of an agent-answered HTTP address, HTTP_REQUEST and HTTP_REPLY, across the wire.
 *
 * The reply carries a file's bytes when the daemon serves the file itself, so what matters most
 * is that every byte value comes back as it left and that a body that is not base64 is refused
 * rather than handed to the browser as text.
 */
final class HttpAgentRouteDtoTest extends TestCase
{
    /** @var string Correlation id the fixtures carry */
    private const string CORRELATION_ID = '0123456789abcdef0123456789abcdef';

    /**
     * @throws InvalidFormatException When the request refuses its own wire payload
     */
    public function testARequestComesBackAsItLeft(): void
    {
        $request = $this->request('node-b');

        $this->assertEquals($request, HttpRequestDTO::fromJson($request->toJson()));
    }

    /**
     * @throws InvalidFormatException When the request refuses its own wire payload
     */
    public function testARequestWithoutASessionOffAClusterComesBackWithBothNulls(): void
    {
        $request = new HttpRequestDTO(self::CORRELATION_ID, HttpConstants::METHOD_GET, '/_test/file', [], null, null);

        $restored = HttpRequestDTO::fromJson($request->toJson());

        $this->assertNull($restored->sessionToken);
        $this->assertNull($restored->originNodeId);
        $this->assertSame([], $restored->query);
    }

    public function testARequestWhoseQueryIsNotAMapOfStringsIsRefused(): void
    {
        $payload = $this->request(null)->toArray();
        $payload[HttpRequestDTO::FIELD_QUERY] = ['id' => ['1', '2']];

        $this->expectException(InvalidFormatException::class);

        HttpRequestDTO::fromArray($payload);
    }

    public function testARequestWithoutAPathIsRefused(): void
    {
        $payload = $this->request(null)->toArray();
        unset($payload[HttpRequestDTO::FIELD_PATH]);

        $this->expectException(InvalidFormatException::class);

        HttpRequestDTO::fromArray($payload);
    }

    /**
     * @throws InvalidFormatException When the reply refuses its own wire payload
     */
    public function testEveryByteOfABinaryBodySurvivesTheWire(): void
    {
        $bytes = implode('', array_map(chr(...), range(0, 255)));
        $reply = HttpReplyDTO::response($this->request('node-b'), HttpConstants::HTTP_OK, [
            HttpConstants::HEADER_CONTENT_TYPE => 'image/png',
        ], $bytes);

        $restored = HttpReplyDTO::fromJson($reply->toJson());

        $this->assertSame($bytes, $restored->body);
        $this->assertEquals($reply, $restored);
    }

    public function testABodyThatIsNotBase64IsRefused(): void
    {
        $payload = HttpReplyDTO::response($this->request(null), HttpConstants::HTTP_OK, [], 'x')->toArray();
        $payload[HttpReplyDTO::FIELD_BODY] = 'not base64!';

        $this->expectException(InvalidFormatException::class);

        HttpReplyDTO::fromArray($payload);
    }

    public function testAReplyWhoseHeadersAreNotAMapOfStringsIsRefused(): void
    {
        $payload = HttpReplyDTO::response($this->request(null), HttpConstants::HTTP_OK, [], '')->toArray();
        $payload[HttpReplyDTO::FIELD_HEADERS] = [HttpConstants::HEADER_CONTENT_LENGTH => 12];

        $this->expectException(InvalidFormatException::class);

        HttpReplyDTO::fromArray($payload);
    }

    public function testARefusalNamesItsStatusInJsonAndForbidsTheCache(): void
    {
        $refusal = HttpReplyDTO::refusal($this->request('node-b'), HttpConstants::HTTP_SERVICE_UNAVAILABLE);

        $this->assertSame(self::CORRELATION_ID, $refusal->correlationId);
        $this->assertSame('node-b', $refusal->originNodeId);
        $this->assertSame(HttpConstants::HTTP_SERVICE_UNAVAILABLE, $refusal->status);
        $this->assertSame('{"error":"Service Unavailable"}', $refusal->body);
        $this->assertSame([
            HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON,
            HttpConstants::HEADER_CACHE_CONTROL => HttpConstants::CACHE_CONTROL_NO_STORE,
        ], $refusal->headers);
    }

    /**
     * A request routed to an agent, as the router builds it.
     *
     * @param ?string $originNodeId Node holding the connection, null off a cluster
     * @return HttpRequestDTO Request fixture
     */
    private function request(?string $originNodeId): HttpRequestDTO
    {
        return new HttpRequestDTO(
            correlationId: self::CORRELATION_ID,
            method: HttpConstants::METHOD_GET,
            path: '/_test/file',
            query: ['id' => '42', '7' => 'numeric key'],
            sessionToken: str_repeat('a', 32),
            originNodeId: $originNodeId,
        );
    }
}
