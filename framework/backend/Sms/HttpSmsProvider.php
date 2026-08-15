<?php

declare(strict_types=1);

namespace Hilos\Sms;

use Hilos\API\AsyncHttpClient;
use Hilos\API\DTO\AsyncHttpResponse;
use Hilos\Auth\OAuth\HttpOAuthProvider;
use Hilos\Sms\Exception\SmsConfigException;

/**
 * HttpSmsProvider - an SMS provider whose send runs over real HTTP (HIL-285).
 *
 * The provider does no I/O itself: it builds the gateway request and interprets the
 * response, while the sharded SMS agent owns the non-blocking {@see AsyncHttpClient}
 * and pumps it across event-loop ticks - exactly the boundary
 * {@see HttpOAuthProvider} draws for OAuth. {@see GenericHttpSmsProvider}
 * is the config-driven implementation; a vendor with delivery-status semantics
 * (e.g. Twilio) is a later, separate implementation the registry admits.
 *
 * The async client raises non-2xx responses as an exception before a response is seen, so
 * status classification is separated from body parsing: {@see classifyStatus()} decides
 * retry vs terminal for an error status, and {@see parseResponse()} interprets a delivered
 * 2xx body against the success rule.
 */
interface HttpSmsProvider extends SmsProviderInterface
{
    /**
     * Builds the gateway request that sends one message.
     *
     * @param SmsMessage $message Recipient message to send
     * @return SmsHttpRequest Request the agent replays to the gateway
     * @throws SmsConfigException When the gateway endpoint or its credentials are unusable
     */
    public function buildRequest(SmsMessage $message): SmsHttpRequest;

    /**
     * Interprets a delivered 2xx gateway response against the success rule.
     *
     * @param AsyncHttpResponse $response Completed 2xx gateway response
     * @return SmsSendResult Delivered, or a permanent failure when the body reports a rejection
     */
    public function parseResponse(AsyncHttpResponse $response): SmsSendResult;

    /**
     * Classifies a non-2xx gateway status as a permanent or transient failure.
     *
     * @param int $statusCode HTTP status code the gateway returned
     * @return SmsSendResult Failed result: permanent for a 4xx rejection, transient otherwise
     */
    public function classifyStatus(int $statusCode): SmsSendResult;
}
