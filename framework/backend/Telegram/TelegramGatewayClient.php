<?php

declare(strict_types=1);

namespace Hilos\Telegram;

use Hilos\API\DTO\AsyncHttpRequest;
use Hilos\API\DTO\AsyncHttpResponse;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\HttpConstants;
use Hilos\Sms\GenericHttpSmsProvider;
use Hilos\Telegram\Exception\TelegramGatewayException;

/**
 * TelegramGatewayClient - the Telegram Gateway recipe, with no I/O (HIL-492).
 *
 * The provider half of the messenger code channel, shaped like
 * {@see GenericHttpSmsProvider}: it knows the endpoints, the field names and the
 * response envelope, and it does none of the talking. Requests come back as
 * {@see AsyncHttpRequest} descriptors for the code agent to replay, and responses
 * come back in as {@see AsyncHttpResponse} to be read - which is what lets the whole
 * recipe be tested without a socket, and keeps blocking I/O out of the process that
 * owns the sockets.
 *
 * Two calls, and their relationship is the reason the channel has two steps at all:
 *  - `checkSendAbility` asks whether a number can be reached, and answers a
 *    `request_id`;
 *  - `sendVerificationMessage` delivers the code, quoting that `request_id` back.
 * Inside one request id the Gateway charges once, so asking first is free - which is
 * what makes "probe before minting" affordable rather than merely careful.
 *
 * `checkVerificationStatus` and `revokeVerificationMessage` are deliberately not
 * here. Hilos mints and verifies its own codes ({@see VerificationService}), so the
 * Gateway is a transport and never an authority on whether a code was right; asking
 * it would put a second opinion in a place that must have exactly one.
 *
 * Every answer arrives in the same envelope - `{"ok":true,"result":{…}}` or
 * `{"ok":false,"error":"…"}` - and an `ok:false` is the Gateway WORKING, so it is a
 * result and not an exception. The exception is reserved for a Gateway that cannot be
 * called at all.
 */
final readonly class TelegramGatewayClient
{
    /** Path of the reachability call. */
    public const string PATH_CHECK_SEND_ABILITY = '/checkSendAbility';

    /** Path of the delivery call. */
    public const string PATH_SEND_VERIFICATION_MESSAGE = '/sendVerificationMessage';

    /** Wire field carrying the recipient number on both calls. */
    private const string FIELD_PHONE_NUMBER = 'phone_number';

    /** Wire field carrying the reachability call's handle back on the send. */
    private const string FIELD_REQUEST_ID = 'request_id';

    /** Wire field carrying the code to show the recipient. */
    private const string FIELD_CODE = 'code';

    /** Wire field naming the sender shown on the message. */
    private const string FIELD_SENDER_USERNAME = 'sender_username';

    /** Wire field carrying how long the code stays valid, in seconds. */
    private const string FIELD_TTL = 'ttl';

    /** Envelope field: whether the Gateway accepted the call. */
    private const string FIELD_OK = 'ok';

    /** Envelope field: the payload of an accepted call. */
    private const string FIELD_RESULT = 'result';

    /** Envelope field: the refusal code of a rejected call. */
    private const string FIELD_ERROR = 'error';

    /** Authorization header name (HttpConstants carries no request-auth header). */
    private const string HEADER_AUTHORIZATION = 'Authorization';

    /** Form content type of a Gateway call. */
    private const string CONTENT_TYPE_FORM = 'application/x-www-form-urlencoded';

    /**
     * @param TelegramGatewayConfig $config Endpoint, token and sender the calls are built with
     */
    public function __construct(
        private TelegramGatewayConfig $config,
    ) {
    }

    /**
     * Builds the call that asks whether a number can be reached on Telegram.
     *
     * @param string $phoneNumber Recipient number in canonical E.164
     * @return AsyncHttpRequest Request the agent replays to the Gateway
     * @throws TelegramGatewayException When the endpoint URL has no host, or no token is configured
     */
    public function buildCheckSendAbility(string $phoneNumber): AsyncHttpRequest
    {
        return $this->request(self::PATH_CHECK_SEND_ABILITY, [self::FIELD_PHONE_NUMBER => $phoneNumber]);
    }

    /**
     * Builds the call that delivers one code.
     *
     * The `request_id` of the reachability call rides along whenever there is one, and
     * that is what makes the pair cost a single charge; without it the Gateway treats
     * the send as a fresh request and bills it again.
     *
     * @param string $phoneNumber Recipient number in canonical E.164
     * @param string $code Plaintext code the recipient reads
     * @param ?string $requestId Handle from the reachability call, or null when there was none
     * @param ?int $ttlSeconds How long the code stays valid, or null to let the Gateway decide
     * @return AsyncHttpRequest Request the agent replays to the Gateway
     * @throws TelegramGatewayException When the endpoint URL has no host, or no token is configured
     */
    public function buildSendVerificationMessage(
        string $phoneNumber,
        string $code,
        ?string $requestId = null,
        ?int $ttlSeconds = null,
    ): AsyncHttpRequest {
        $params = [
            self::FIELD_PHONE_NUMBER => $phoneNumber,
            self::FIELD_CODE => $code,
        ];
        if ($requestId !== null && $requestId !== '') {
            $params[self::FIELD_REQUEST_ID] = $requestId;
        }
        if ($this->config->senderUsername !== '') {
            $params[self::FIELD_SENDER_USERNAME] = $this->config->senderUsername;
        }
        if ($ttlSeconds !== null) {
            $params[self::FIELD_TTL] = (string)$ttlSeconds;
        }

        return $this->request(self::PATH_SEND_VERIFICATION_MESSAGE, $params);
    }

    /**
     * Reads a Gateway answer into the envelope it always arrives in.
     *
     * A non-2xx status, a body that is not JSON, and an `ok:false` all reduce to the
     * same shape - refused, with a reason for the log - because the caller does the
     * same thing with all three: report the channel unavailable and let the person
     * pick another. Only the status is worth keeping apart in the reason, since a 401
     * means a token nobody has fixed and a 500 means a bad minute.
     *
     * @param AsyncHttpResponse $response Completed Gateway response
     * @return TelegramGatewayResult Accepted payload, or the refusal reason
     */
    public function readResponse(AsyncHttpResponse $response): TelegramGatewayResult
    {
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            return TelegramGatewayResult::refused("gateway returned HTTP {$response->statusCode}");
        }

        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            return TelegramGatewayResult::refused('gateway answered a body that is not a JSON object');
        }

        if (($decoded[self::FIELD_OK] ?? false) !== true) {
            $error = $decoded[self::FIELD_ERROR] ?? null;

            return TelegramGatewayResult::refused(
                'gateway refused: ' . (is_string($error) && $error !== '' ? $error : 'no error given'),
            );
        }

        $result = $decoded[self::FIELD_RESULT] ?? [];

        return TelegramGatewayResult::accepted(is_array($result) ? $result : []);
    }

    /**
     * Reads the request id out of an accepted answer, or null when it carries none.
     *
     * @param TelegramGatewayResult $result Accepted Gateway answer
     * @return ?string The `request_id` to quote back on the send, or null when absent
     */
    public function readRequestId(TelegramGatewayResult $result): ?string
    {
        $requestId = $result->result[self::FIELD_REQUEST_ID] ?? null;

        return is_string($requestId) && $requestId !== '' ? $requestId : null;
    }

    /**
     * Builds one Gateway call: the token in the header, the fields in a form body.
     *
     * @param string $path Gateway path, leading slash included
     * @param array<string, string> $params Wire fields of the call
     * @return AsyncHttpRequest Request descriptor for the agent
     * @throws TelegramGatewayException When the endpoint URL has no host, or no token is configured
     */
    private function request(string $path, array $params): AsyncHttpRequest
    {
        if (!$this->config->isConfigured()) {
            throw new TelegramGatewayException('Telegram Gateway needs an access token, none is configured');
        }

        $parts = parse_url($this->config->endpointUrl);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if (!is_string($host) || $host === '') {
            throw new TelegramGatewayException(
                'Telegram Gateway endpoint URL has no host: ' . $this->config->endpointUrl,
            );
        }

        $useTls = ($parts['scheme'] ?? 'https') === 'https';
        $port = (int)($parts['port'] ?? ($useTls ? 443 : 80));
        // external-boundary: the configured base URL usually carries no path of its own
        $base = rtrim($parts['path'] ?? '', '/');

        return new AsyncHttpRequest(
            host: $host,
            port: $port,
            useTls: $useTls,
            method: HttpConstants::METHOD_POST,
            path: $base . $path,
            headers: [
                self::HEADER_AUTHORIZATION => 'Bearer ' . $this->config->accessToken,
                HttpConstants::HEADER_CONTENT_TYPE => self::CONTENT_TYPE_FORM,
            ],
            body: http_build_query($params, '', '&', PHP_QUERY_RFC3986),
        );
    }
}
