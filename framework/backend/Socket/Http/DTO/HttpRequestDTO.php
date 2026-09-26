<?php

declare(strict_types=1);

namespace Hilos\Socket\Http\DTO;

use Hilos\API\Router\HttpRouter;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;

/**
 * HttpRequestDTO - an HTTP request the master hands to the agent that declares its address.
 *
 * The HTTP server lives in the daemon's master process, where the database and the files may
 * not be touched (docs/agents/antipatterns/heavy-work-in-master.md). A request to an address an
 * agent declares is therefore parked by the master under a correlation id and travels to that
 * agent as the HTTP_REQUEST signal payload; the agent's {@see HttpReplyDTO} comes back addressed
 * by the same id (docs/agents/architecture/agent-http-routes.md).
 *
 * It carries only what an answer is decided on - the method, the path, the query map and the
 * session token - and no headers. The token rides the frame the way
 * {@see WebSocketHandshakeSignalDTO::$sessionToken} does: the router took it from the header or
 * the cookie and never from the url (docs/agents/antipatterns/secret-in-query.md).
 */
final class HttpRequestDTO extends BaseDTO implements SignalDataInterface
{
    /** @var string Wire key of the correlation id */
    public const string FIELD_CORRELATION_ID = 'correlationId';

    /** @var string Wire key of the request method */
    public const string FIELD_METHOD = 'method';

    /** @var string Wire key of the request path */
    public const string FIELD_PATH = 'path';

    /** @var string Wire key of the query map */
    public const string FIELD_QUERY = 'query';

    /** @var string Wire key of the session token */
    public const string FIELD_SESSION_TOKEN = 'sessionToken';

    /** @var string Wire key of the node holding the connection */
    public const string FIELD_ORIGIN_NODE_ID = 'originNodeId';

    /**
     * Creates an HTTP request routed to an agent.
     *
     * @param string $correlationId Id the master parked the connection under, 32 hex digits
     * @param string $method Request method, upper case
     * @param string $path Request path without the query string
     * @param array<string, string> $query Query parameters by name
     * @param ?string $sessionToken Session token the request presented, already checked for shape by
     *     {@see HttpRouter}, or null when it presented none
     * @param ?string $originNodeId Id of the node holding the connection, null off a cluster
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly ?string $sessionToken,
        public readonly ?string $originNodeId,
    ) {
    }

    /**
     * Serializes the request to its wire payload.
     *
     * @return array<string, mixed> Wire payload
     */
    public function toArray(): array
    {
        return [
            self::FIELD_CORRELATION_ID => $this->correlationId,
            self::FIELD_METHOD => $this->method,
            self::FIELD_PATH => $this->path,
            self::FIELD_QUERY => $this->query,
            self::FIELD_SESSION_TOKEN => $this->sessionToken,
            self::FIELD_ORIGIN_NODE_ID => $this->originNodeId,
        ];
    }

    /**
     * Restores a request from its wire payload.
     *
     * The token and the origin may be null - a request that presented no session, a node that is
     * not in a cluster - and everything else is required: the master writes every field through
     * {@see toArray()}, an empty query as an empty map, so an absent key is a truncated frame.
     *
     * @param array<string, mixed> $data Wire payload
     * @return static Restored request
     * @throws InvalidFormatException When a required field is absent, the query is not a map of
     *     strings, or the token or the origin is present and not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            correlationId: self::requireString($data, self::FIELD_CORRELATION_ID),
            method: self::requireString($data, self::FIELD_METHOD),
            path: self::requireString($data, self::FIELD_PATH),
            query: self::requireStringMap($data, self::FIELD_QUERY),
            sessionToken: self::optionalString($data, self::FIELD_SESSION_TOKEN),
            originNodeId: self::optionalString($data, self::FIELD_ORIGIN_NODE_ID),
        );
    }
}
