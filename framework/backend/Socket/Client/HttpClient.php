<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\API\Router\HttpRouter;
use Hilos\API\Router\ParkedHttpRequest;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Client\Interface\HttpClientInterface;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\Server\HttpServer;
use Hilos\Socket\SocketException;
use Hilos\Socket\Transport\SocketTransportInterface;
use Hilos\Utils\Helpers\HttpHeaderHelper;
use Hilos\Utils\Logger;

/**
 * HttpClient - Represents a single HTTP client connection.
 *
 * Handles reading HTTP requests and writing responses for a single client.
 * Created by HttpServer when accepting new connections.
 *
 * Persistent connections (keep-alive) are controlled by {@see EnvConstants::HTTP_STATUS_KEEP_ALIVE}
 * and the client's Connection / HTTP version. When the server closes after a response, closing is
 * deferred until the outbound buffer is fully drained (avoids truncated bodies on partial writes).
 *
 * A request body is read by its declared Content-Length and handed to the route as a raw string;
 * the client decodes neither a form nor JSON. A chunked body and a body above
 * {@see self::MAX_REQUEST_BODY_BYTES} are refused before any route is chosen.
 *
 * A request to an address an agent answers is parked rather than answered: the client holds
 * itself in its {@see HttpServer} under the request's correlation id, hands the request to the
 * agent as an HTTP_REQUEST signal and reads no further request of the connection until
 * {@see writeReply()} writes the agent's answer (docs/agents/architecture/agent-http-routes.md).
 */
class HttpClient extends AbstractClient implements HttpClientInterface
{
    /** @var array{status: string} Default JSON body when no router is assigned */
    private const array DEFAULT_RESPONSE_BODY = ['status' => 'ok'];

    /**
     * Largest request body the client accepts, in bytes.
     *
     * The HTTP server lives in the daemon's master process and a body is held whole in its memory
     * until it is routed, so the ceiling is fixed here rather than left to the sender.
     */
    private const int MAX_REQUEST_BODY_BYTES = 1048576;

    /** @var ?HttpRouter Router for handling requests */
    private ?HttpRouter $router = null;

    /** @var bool Server policy: allow HTTP keep-alive when the client also allows it */
    private bool $serverAllowsPersistentConnections = true;

    /** @var ?HttpServer Owning server holding the parked-request registry, null for a client no server holds */
    private ?HttpServer $server;

    /** @var ?ParkedHttpRequest Request parked awaiting its agent's reply, or null when nothing is parked */
    private ?ParkedHttpRequest $parked = null;

    /** @var bool Whether the parked request's response may keep the connection open */
    private bool $parkedPersistent = false;

    /**
     * Create HTTP client with socket and load keep-alive policy from env.
     *
     * @param resource|object $socket Client socket resource or Socket object
     * @param ?SocketTransportInterface $transport Transport over that socket, the bare one when null
     * @param ?HttpServer $server Owning server that holds a parked request, null when none does
     * @throws EnvException When socket buffer or keep-alive env values are missing or invalid
     */
    public function __construct($socket, ?SocketTransportInterface $transport = null, ?HttpServer $server = null)
    {
        parent::__construct($socket, $transport);

        $this->serverAllowsPersistentConnections = Hilos::$env[EnvConstants::HTTP_STATUS_KEEP_ALIVE]->bool();
        $this->server = $server;
    }

    /**
     * Set router for handling HTTP requests.
     *
     * @param HttpRouter $router Router instance
     */
    public function setRouter(HttpRouter $router): void
    {
        $this->router = $router;
    }

    /**
     * Parse complete HTTP request(s) from the read buffer when outbound data is fully sent.
     *
     * Nothing is parsed while a request is parked: its answer has to leave first, and a request
     * behind it waits in the buffer the way it waits behind a response not yet sent.
     *
     * @throws SocketException When outbound write fails during request handling
     * @throws InvalidFormatException When the request query string carries a non-string value
     * @throws InvalidArgumentException When a parked request's signal cannot be named
     * @throws HilosException When a pipelined follow-up request refuses to become a response
     */
    protected function processReadBuffer(): void
    {
        if ($this->writeBuffer !== '' || $this->parked !== null) {
            return;
        }

        while (true) {
            $pos = strpos($this->readBuffer, HttpConstants::HTTP_DELIMITER);
            if ($pos === false) {
                break;
            }
            $end = $pos + strlen(HttpConstants::HTTP_DELIMITER);
            $rawHeaders = substr($this->readBuffer, 0, $end);
            $headers = $this->parseHeaders(explode(HttpConstants::HTTP_LINE_SEPARATOR, $rawHeaders));

            // A chunked body has no length to wait for, and staying silent on it would leave
            // the sender hanging until its own timeout.
            if (HttpHeaderHelper::get($headers, HttpConstants::HEADER_TRANSFER_ENCODING) !== null) {
                $this->refuseRequest(HttpConstants::HTTP_LENGTH_REQUIRED);
                break;
            }

            $declaredLength = HttpHeaderHelper::get($headers, HttpConstants::HEADER_CONTENT_LENGTH);
            if ($declaredLength !== null && !ctype_digit($declaredLength)) {
                $this->refuseRequest(HttpConstants::HTTP_BAD_REQUEST);
                break;
            }

            // A length too long for an integer saturates to PHP_INT_MAX and is refused as too large.
            $bodyLength = $declaredLength === null ? 0 : (int)$declaredLength;
            if ($bodyLength > self::MAX_REQUEST_BODY_BYTES) {
                $this->refuseRequest(HttpConstants::HTTP_PAYLOAD_TOO_LARGE);
                break;
            }

            // The body has not arrived whole: the buffer stays as it is until the next read.
            // Cutting here would hand the route a short body and read its rest as the next request.
            if (strlen($this->readBuffer) < $end + $bodyLength) {
                break;
            }

            $body = substr($this->readBuffer, $end, $bodyLength);
            $this->readBuffer = substr($this->readBuffer, $end + $bodyLength);
            $this->processSingleHttpRequest($rawHeaders, $body);
            if ($this->writeBuffer !== '' || $this->parked !== null) {
                break;
            }
        }
    }

    /**
     * Route one complete HTTP request and queue its response, or park it for the agent that answers it.
     *
     * @param string $rawHeaders Raw request line and headers including the header/body delimiter
     * @param string $body Request body, exactly as many bytes as the request declared
     * @throws SocketException When outbound write fails while sending the response
     * @throws InvalidArgumentException When a parked request's signal cannot be named
     * @throws HilosException When a pipelined follow-up request refuses to become a response
     */
    private function processSingleHttpRequest(string $rawHeaders, string $body): void
    {
        $request = $this->parseRequest($rawHeaders, $body);
        $persistent = $this->effectivePersistentConnectionForResponse(
            $request[HttpConstants::REQUEST_KEY_HEADERS],
            $request[HttpConstants::REQUEST_KEY_VERSION],
        );

        if ($this->router !== null) {
            $response = $this->router->route($request);
            if ($response instanceof ParkedHttpRequest) {
                $this->park($response, $persistent);

                return;
            }
        } else {
            $response = [
                HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_OK,
                HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON],
                HttpConstants::RESPONSE_KEY_BODY => json_encode(self::DEFAULT_RESPONSE_BODY),
            ];
        }

        $this->queueResponse($response, $persistent);
        $this->write();
    }

    /**
     * Holds the connection for the agent that answers the request, and hands the request to it.
     *
     * Held in the server first and signalled second, so a reply can never arrive for a request
     * nobody holds yet.
     *
     * @param ParkedHttpRequest $parked Request the router parked
     * @param bool $persistent Whether the eventual response may keep the connection open
     * @throws InvalidArgumentException When the request's signal cannot be named
     */
    private function park(ParkedHttpRequest $parked, bool $persistent): void
    {
        $request = $parked->request;
        $this->parked = $parked;
        $this->parkedPersistent = $persistent;
        $this->server?->hold($request->correlationId, $this);
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::DAEMON),
            signalType: new SignalType(SignalTypeConstants::HTTP_REQUEST),
            signalName: new SignalName("{$request->method} {$request->path}"),
            signalData: $request,
        );
    }

    /**
     * Writes an agent's answer to the parked request and lets the connection read on.
     *
     * The response leaves on the server's next write, like every other one, and a request that
     * waited behind the parked one is parsed once it has drained. The analytics row the router
     * started is finished here, with the reply's status and the time since the request parked.
     *
     * A reply with nothing parked is dropped: the server delivers only to a client it holds, so
     * this is a reply that lost a race to the connection's own close.
     *
     * @param HttpReplyDTO $reply Agent reply to write
     */
    public function writeReply(HttpReplyDTO $reply): void
    {
        $parked = $this->parked;
        if ($parked === null) {
            return;
        }

        $this->parked = null;
        Hilos::$ac?->finishApiRequest($parked->apiRequestId, $reply->status, self::millisecondsSince($parked->startedAtNs));
        $this->queueResponse([
            HttpConstants::RESPONSE_KEY_STATUS => $reply->status,
            HttpConstants::RESPONSE_KEY_HEADERS => $reply->headers,
            HttpConstants::RESPONSE_KEY_BODY => $reply->body,
        ], $this->parkedPersistent);
    }

    /**
     * Puts a response in the outbound buffer with the Connection header the connection is owed.
     *
     * @param array<string, mixed> $response Response payload keyed by HttpConstants::RESPONSE_KEY_*
     * @param bool $persistent Whether the connection stays open after the response
     */
    private function queueResponse(array $response, bool $persistent): void
    {
        $headers = $response[HttpConstants::RESPONSE_KEY_HEADERS] ?? [];
        if (!is_array($headers)) {
            $headers = [];
        }
        $headers[HttpConstants::HEADER_CONNECTION] = $persistent
            ? HttpConstants::CONNECTION_VALUE_KEEP_ALIVE
            : HttpConstants::CONNECTION_VALUE_CLOSE;
        $response[HttpConstants::RESPONSE_KEY_HEADERS] = $headers;

        $this->writeBuffer = $this->buildResponse($response);
        $this->closeWhenOutputDrained = !$persistent;
    }

    /**
     * Answer a request that cannot be read any further, and close the connection once the answer is sent.
     *
     * The refusal is built here rather than by the router: no route is chosen yet, and the bytes
     * after the headers can no longer be told apart from the next request, so they are dropped
     * instead of being kept in memory.
     *
     * @param int $status HTTP status of the refusal
     * @throws SocketException When outbound write fails while sending the refusal
     * @throws HilosException When the client fails to send the refusal
     */
    private function refuseRequest(int $status): void
    {
        $this->readBuffer = '';
        $this->writeBuffer = $this->buildResponse([
            HttpConstants::RESPONSE_KEY_STATUS => $status,
            HttpConstants::RESPONSE_KEY_HEADERS => [
                HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON,
                HttpConstants::HEADER_CONNECTION => HttpConstants::CONNECTION_VALUE_CLOSE,
            ],
            HttpConstants::RESPONSE_KEY_BODY => json_encode(['error' => HttpConstants::HTTP_STATUS_TEXTS[$status]]),
        ]);
        $this->closeWhenOutputDrained = true;
        $this->write();
    }

    /**
     * Resolve whether the response may use a persistent TCP connection.
     *
     * @param array<string, string> $headers Request headers (lowercase header names)
     * @param string $version HTTP version from the request line
     * @return bool True when keep-alive is allowed for this response
     */
    private function effectivePersistentConnectionForResponse(array $headers, string $version): bool
    {
        if (!$this->serverAllowsPersistentConnections) {
            return false;
        }

        $connection = HttpHeaderHelper::get($headers, HttpConstants::HEADER_CONNECTION);
        if ($connection !== null) {
            $conn = strtolower(trim($connection));
            if (str_contains($conn, HttpConstants::CONNECTION_VALUE_CLOSE)) {
                return false;
            }
            if (str_contains($conn, HttpConstants::CONNECTION_VALUE_KEEP_ALIVE)) {
                return true;
            }
        }

        $ver = strtoupper(trim($version));

        return str_contains($ver, HttpConstants::HTTP_VERSION_NUMBER);
    }

    /**
     * Parse raw HTTP request into method, path, headers, body, and query params.
     *
     * @param string $rawHeaders Raw request line and headers
     * @param string $body Request body as it arrived, not decoded
     * @return array{
     *     method: string,
     *     path: string,
     *     version: string,
     *     headers: array<string, string>,
     *     body: string,
     *     query: string,
     *     queryParams: RequestQueryParams
     * } Parsed request keyed by HttpConstants::REQUEST_KEY_* constants
     */
    private function parseRequest(string $rawHeaders, string $body): array
    {
        $lines = explode(HttpConstants::HTTP_LINE_SEPARATOR, $rawHeaders);
        $firstLine = $lines[0];

        // Parse: GET /path?a=1 HTTP/1.1
        $parts = explode(' ', $firstLine);
        $rawPath = $parts[1] ?? HttpConstants::PATH_ROOT;
        $path = $rawPath;
        $queryString = '';
        $queryPos = strpos($rawPath, HttpConstants::QUERY_STRING_SEPARATOR);
        if ($queryPos !== false) {
            $path = substr($rawPath, 0, $queryPos);
            $queryString = substr($rawPath, $queryPos + 1);
        }

        return [
            HttpConstants::REQUEST_KEY_METHOD => $parts[0] ?? HttpConstants::METHOD_GET,
            HttpConstants::REQUEST_KEY_PATH => $path,
            HttpConstants::REQUEST_KEY_VERSION => $parts[2] ?? HttpConstants::HTTP_VERSION,
            HttpConstants::REQUEST_KEY_HEADERS => $this->parseHeaders($lines),
            HttpConstants::REQUEST_KEY_BODY => $body,
            HttpConstants::REQUEST_KEY_QUERY => $queryString,
            HttpConstants::REQUEST_KEY_QUERY_PARAMS => RequestQueryParams::fromQueryString($queryString),
        ];
    }

    /**
     * Build raw HTTP response string from status, headers, and body.
     *
     * @param array{status?: int, headers?: array<string, string>, body?: string} $response Response data
     * @return string Serialized HTTP response
     */
    private function buildResponse(array $response): string
    {
        $status = $response[HttpConstants::RESPONSE_KEY_STATUS] ?? HttpConstants::HTTP_OK;
        $statusText = HttpConstants::HTTP_STATUS_TEXTS[$status]
            ?? HttpConstants::HTTP_STATUS_TEXT_UNKNOWN;
        $headers = $response[HttpConstants::RESPONSE_KEY_HEADERS] ?? [];
        // external-boundary: "no body" and "an empty body" are one state — it is counted, then concatenated
        $body = $response[HttpConstants::RESPONSE_KEY_BODY] ?? '';

        $http = HttpConstants::HTTP_VERSION . " {$status} {$statusText}" . HttpConstants::HTTP_LINE_SEPARATOR;

        $headers[HttpConstants::HEADER_CONTENT_LENGTH] = strlen($body);
        foreach ($headers as $key => $value) {
            $http .= "{$key}: {$value}" . HttpConstants::HTTP_LINE_SEPARATOR;
        }

        $http .= HttpConstants::HTTP_LINE_SEPARATOR;
        $http .= $body;

        return $http;
    }

    /**
     * After a full response is sent on a keep-alive connection, parse any pipelined request in the buffer.
     *
     * @throws SocketException When outbound write fails while handling a subsequent request
     * @throws InvalidFormatException When the request query string carries a non-string value
     * @throws HilosException When a pipelined follow-up request refuses to become a response
     */
    protected function onAfterOutboundDrained(): void
    {
        $this->processReadBuffer();
    }

    /**
     * Periodic tick hook; HTTP clients have no timeout or heartbeat work.
     */
    public function onTick(): void
    {
        // No periodic operations needed for HTTP clients
    }

    /**
     * Connection close hook: a request still parked is given up on.
     *
     * The browser left before the agent answered - its own timeout, or a closed tab. The server
     * drops the hold and tells the master, which drops a frame it keeps for a starting agent on
     * this request's behalf (HIL-1040); the reply, whenever it comes, finds nobody. The analytics
     * row the router started is finished without a status: no response was ever written.
     */
    protected function onClose(): void
    {
        $parked = $this->parked;
        if ($parked === null) {
            return;
        }

        $request = $parked->request;
        Logger::warning("HTTP: the browser left while {$request->method} {$request->path} #{$request->correlationId} was parked");
        $this->parked = null;
        Hilos::$ac?->finishApiRequest($parked->apiRequestId, null, self::millisecondsSince($parked->startedAtNs));
        $this->server?->abandon($request->correlationId);
    }

    /**
     * Milliseconds since a moment read from hrtime(true).
     *
     * @param int $startedAtNs The moment, in nanoseconds
     * @return int Whole milliseconds since then
     */
    private static function millisecondsSince(int $startedAtNs): int
    {
        return (int)round((hrtime(true) - $startedAtNs) / TimeConstants::NS_PER_MILLISECOND);
    }
}
