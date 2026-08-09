<?php

declare(strict_types=1);

namespace Hilos\Sms;

use Hilos\API\DTO\AsyncHttpResponse;
use Hilos\Constants\HttpConstants;
use Hilos\Sms\Exception\SmsConfigException;

/**
 * A config-driven SMS client for any simple HTTP gateway (HIL-285).
 *
 * One implementation covers gateways that accept a message as form fields over a single
 * request: the {@see SmsChannelConfig} names the endpoint, HTTP method, the map from the
 * logical to/text/from keys to the gateway's own param names, the auth mode, and the rule
 * that recognizes success. Gateways with richer semantics (delivery-status callbacks) are a
 * later, separate implementation, not a config change here.
 *
 * It does no I/O: {@see buildRequest()} yields an {@see SmsHttpRequest} the agent replays,
 * and {@see parseResponse()}/{@see classifyStatus()} interpret the outcome. SMS failures are
 * classified stricter than email - a 4xx or an explicit gateway rejection is permanent (no
 * retry), since each attempt costs money and a typical rejection does not self-heal.
 */
final class GenericHttpSmsProvider implements HttpSmsProvider
{
    /** Query/body param name carrying the API key in query auth mode. */
    private const string API_KEY_PARAM = 'api_key';

    /** Form content type for a POST gateway request. */
    private const string CONTENT_TYPE_FORM = 'application/x-www-form-urlencoded';

    /** Authorization header name (HttpConstants carries no request-auth header). */
    private const string HEADER_AUTHORIZATION = 'Authorization';

    public function __construct(
        private readonly SmsChannelConfig $config,
    ) {
    }

    /**
     * @return string The `generic` provider key
     */
    public function getKey(): string
    {
        return SmsChannelConfig::PROVIDER_GENERIC;
    }

    /**
     * Builds the gateway request from the field map, auth mode, and HTTP method.
     *
     * @param SmsMessage $message Recipient message to send
     * @return SmsHttpRequest Request the agent replays to the gateway
     * @throws SmsConfigException When the endpoint URL has no host, or the auth mode needs
     *                            credentials the config does not carry
     */
    public function buildRequest(SmsMessage $message): SmsHttpRequest
    {
        $params = [
            $this->config->gatewayParam(SmsChannelConfig::MAP_KEY_TO) => $message->to,
            $this->config->gatewayParam(SmsChannelConfig::MAP_KEY_TEXT) => $message->text,
        ];
        if ($this->config->from !== '') {
            $params[$this->config->gatewayParam(SmsChannelConfig::MAP_KEY_FROM)] = $this->config->from;
        }

        $headers = [];
        $this->applyAuth($params, $headers);

        return $this->requestFor($params, $headers);
    }

    /**
     * Interprets a delivered 2xx gateway response against the success rule.
     *
     * With no rule, a 2xx is success. With a rule, the body must contain it; otherwise the
     * gateway answered 2xx while rejecting the message, a permanent failure.
     *
     * @param AsyncHttpResponse $response Completed 2xx gateway response
     * @return SmsSendResult Delivered, or a permanent failure when the body reports a rejection
     */
    public function parseResponse(AsyncHttpResponse $response): SmsSendResult
    {
        if ($this->config->successRule === '') {
            return SmsSendResult::delivered();
        }

        return str_contains($response->body, $this->config->successRule)
            ? SmsSendResult::delivered()
            : SmsSendResult::failed('gateway rejected the message', true);
    }

    /**
     * Classifies a non-2xx gateway status: a 4xx is permanent, everything else transient.
     *
     * @param int $statusCode HTTP status code the gateway returned
     * @return SmsSendResult Failed result carrying the status and retry classification
     */
    public function classifyStatus(int $statusCode): SmsSendResult
    {
        $permanent = $statusCode >= 400 && $statusCode < 500;

        return SmsSendResult::failed("gateway returned HTTP {$statusCode}", $permanent);
    }

    /**
     * Applies the configured auth mode to the request params/headers.
     *
     * @param array<string, string> $params Request params (query auth adds the key here)
     * @param array<string, string> $headers Request headers (header/basic auth add here)
     * @throws SmsConfigException When the auth mode needs credentials the config does not carry
     */
    private function applyAuth(array &$params, array &$headers): void
    {
        switch ($this->config->authMode) {
            case SmsChannelConfig::AUTH_MODE_QUERY:
                $params[self::API_KEY_PARAM] = $this->requireApiKey();
                break;
            case SmsChannelConfig::AUTH_MODE_HEADER:
                $headers[self::HEADER_AUTHORIZATION] = 'Bearer ' . $this->requireApiKey();
                break;
            case SmsChannelConfig::AUTH_MODE_BASIC:
                $headers[self::HEADER_AUTHORIZATION]
                    = 'Basic ' . base64_encode($this->requireApiKey() . ':' . $this->requireApiPassword());
                break;
            default:
                break;
        }
    }

    /**
     * Reads the configured gateway API key, refusing a send the gateway would reject anyway.
     *
     * Every auth mode but {@see SmsChannelConfig::AUTH_MODE_NONE} is a promise that credentials
     * exist; sending `Bearer ` or `api_key=` instead spends a paid attempt on a request the
     * gateway cannot authenticate.
     *
     * @return string Non-empty gateway API key
     * @throws SmsConfigException When no API key is configured
     */
    private function requireApiKey(): string
    {
        $apiKey = $this->config->apiKey;
        if ($apiKey === null || $apiKey === '') {
            throw new SmsConfigException("SMS auth mode '{$this->config->authMode}' needs an API key, none is configured");
        }

        return $apiKey;
    }

    /**
     * Reads the configured gateway API password for basic auth.
     *
     * An explicitly empty password is a legitimate gateway convention (the key rides the user
     * half of the pair); an absent one is not, and would otherwise be sent as if it were empty.
     *
     * @return string Gateway API password, possibly empty
     * @throws SmsConfigException When no API password is configured
     */
    private function requireApiPassword(): string
    {
        $apiPassword = $this->config->apiPassword;
        if ($apiPassword === null) {
            throw new SmsConfigException("SMS auth mode '{$this->config->authMode}' needs an API password, none is configured");
        }

        return $apiPassword;
    }

    /**
     * Splits the endpoint URL and places the params in the query (GET) or the body (POST).
     *
     * @param array<string, string> $params Request params
     * @param array<string, string> $headers Request headers
     * @return SmsHttpRequest Request descriptor for the gateway
     * @throws SmsConfigException When the endpoint URL has no host
     */
    private function requestFor(array $params, array $headers): SmsHttpRequest
    {
        $parts = parse_url($this->config->endpointUrl);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if (!is_string($host) || $host === '') {
            throw new SmsConfigException('SMS endpoint URL has no host: ' . $this->config->endpointUrl);
        }

        $useTls = ($parts['scheme'] ?? 'https') === 'https';
        $port = (int)($parts['port'] ?? ($useTls ? 443 : 80));
        $path = $parts['path'] ?? '/';
        // external-boundary: parse_url reads the configured endpoint, which usually carries no query at all
        $query = $parts['query'] ?? '';
        $encoded = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        if (strtoupper($this->config->httpMethod) === HttpConstants::METHOD_GET) {
            $merged = $query === '' ? $encoded : $query . '&' . $encoded;

            return new SmsHttpRequest($host, $port, $useTls, HttpConstants::METHOD_GET, $this->appendQuery($path, $merged), $headers, null);
        }

        $headers[HttpConstants::HEADER_CONTENT_TYPE] = self::CONTENT_TYPE_FORM;

        return new SmsHttpRequest($host, $port, $useTls, HttpConstants::METHOD_POST, $this->appendQuery($path, $query), $headers, $encoded);
    }

    /**
     * Appends a query string to a path, if any.
     *
     * @param string $path Request path
     * @param string $query Query string without the leading `?`, or empty
     * @return string Path with the query appended when present
     */
    private function appendQuery(string $path, string $query): string
    {
        return $query === '' ? $path : $path . '?' . $query;
    }
}
