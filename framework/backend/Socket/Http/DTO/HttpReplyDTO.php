<?php

declare(strict_types=1);

namespace Hilos\Socket\Http\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\Client\HttpClient;

/**
 * HttpReplyDTO - an agent's answer to an {@see HttpRequestDTO}, addressed to the held connection.
 *
 * Travels as the HTTP_REPLY signal payload, named by the correlation id of the request, back to
 * the master that parked the connection - or, in a cluster, to the node named by the request's
 * origin - which writes it out as a response ({@see HttpClient::writeReply()}).
 *
 * The body is binary: a file served by the daemon itself rides it whole. On the wire it is
 * base64, so JSON never fails on its bytes (worker IPC, the peer link, the daemon log), and the
 * reader decodes it strictly - a body that is not base64 is a broken frame, not a text body.
 *
 * Use {@see response()} for an answer and {@see refusal()} for a status the agent refuses with.
 */
final class HttpReplyDTO extends BaseDTO implements SignalDataInterface
{
    /** @var string Wire key of the correlation id */
    public const string FIELD_CORRELATION_ID = 'correlationId';

    /** @var string Wire key of the node holding the connection */
    public const string FIELD_ORIGIN_NODE_ID = 'originNodeId';

    /** @var string Wire key of the response status */
    public const string FIELD_STATUS = 'status';

    /** @var string Wire key of the response headers */
    public const string FIELD_HEADERS = 'headers';

    /** @var string Wire key of the base64 response body */
    public const string FIELD_BODY = 'body';

    /** @var string Key of the one field a refusal body carries */
    private const string REFUSAL_ERROR_KEY = 'error';

    /** @var string Refusal body written when the status text refuses to encode */
    private const string REFUSAL_ENCODE_FAILED_BODY = '{"error":"json_encode_failed"}';

    /**
     * Creates an HTTP reply.
     *
     * @param string $correlationId Correlation id copied from the request
     * @param ?string $originNodeId Node holding the connection, copied from the request; null off a cluster
     * @param int $status Response status
     * @param array<string, string> $headers Response headers by name
     * @param string $body Response body, raw bytes
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly ?string $originNodeId,
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /**
     * Builds the answer to a request.
     *
     * @param HttpRequestDTO $request Request being answered
     * @param int $status Response status
     * @param array<string, string> $headers Response headers by name
     * @param string $body Response body, raw bytes
     * @return self Reply addressed to the request's connection
     */
    public static function response(HttpRequestDTO $request, int $status, array $headers, string $body): self
    {
        return new self($request->correlationId, $request->originNodeId, $status, $headers, $body);
    }

    /**
     * Builds a refusal: a JSON body naming the status, which no cache may keep.
     *
     * Not cached, because a refusal is about the asker and the moment - a browser that signs in
     * after a 401 has to get the file on its next request, not the refusal again.
     *
     * @param HttpRequestDTO $request Request being refused
     * @param int $status Refusal status, one of {@see HttpConstants::HTTP_STATUS_TEXTS}
     * @return self Refusal addressed to the request's connection
     */
    public static function refusal(HttpRequestDTO $request, int $status): self
    {
        $body = json_encode([
            self::REFUSAL_ERROR_KEY => HttpConstants::HTTP_STATUS_TEXTS[$status] ?? HttpConstants::HTTP_STATUS_TEXT_UNKNOWN,
        ]);

        return self::response($request, $status, [
            HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON,
            HttpConstants::HEADER_CACHE_CONTROL => HttpConstants::CACHE_CONTROL_NO_STORE,
        ], $body !== false ? $body : self::REFUSAL_ENCODE_FAILED_BODY);
    }

    /**
     * Serializes the reply to its wire payload, the body as base64.
     *
     * @return array<string, mixed> Wire payload
     */
    public function toArray(): array
    {
        return [
            self::FIELD_CORRELATION_ID => $this->correlationId,
            self::FIELD_ORIGIN_NODE_ID => $this->originNodeId,
            self::FIELD_STATUS => $this->status,
            self::FIELD_HEADERS => $this->headers,
            self::FIELD_BODY => base64_encode($this->body),
        ];
    }

    /**
     * Restores a reply from its wire payload.
     *
     * Every field but the origin is required: {@see toArray()} writes them all, no headers as an
     * empty map and no body as an empty string, so an absent key is a truncated frame rather than
     * an empty answer. The origin is null off a cluster. The body is decoded strictly, because a
     * lenient decode would hand the browser bytes nobody sent.
     *
     * @param array<string, mixed> $data Wire payload
     * @return static Restored reply
     * @throws InvalidFormatException When a required field is absent, the origin is not a string,
     *     the headers are not a map of strings, or the body is not base64
     */
    public static function fromArray(array $data): static
    {
        $body = base64_decode(self::requireString($data, self::FIELD_BODY), true);
        if ($body === false) {
            throw new InvalidFormatException('Payload carries no base64 body under key ' . self::FIELD_BODY);
        }

        return new static(
            correlationId: self::requireString($data, self::FIELD_CORRELATION_ID),
            originNodeId: self::optionalString($data, self::FIELD_ORIGIN_NODE_ID),
            status: self::requireInt($data, self::FIELD_STATUS),
            headers: self::requireStringMap($data, self::FIELD_HEADERS),
            body: $body,
        );
    }
}
