<?php

declare(strict_types=1);

namespace Hilos\Sms\Delivery;

use Hilos\API\AsyncHttpClient;
use Hilos\API\Exception\AsyncHttpException;
use Hilos\API\Exception\AsyncHttpStatusException;
use Hilos\Notification\Delivery\DeliveryAttempt;
use Hilos\Sms\Exception\SmsConfigException;
use Hilos\Sms\HttpSmsProvider;
use Hilos\Sms\SmsHttpRequest;
use Hilos\Sms\SmsMessage;
use Hilos\Sms\SmsSendResult;
use Hilos\Socket\SocketException;

/**
 * SmsDeliveryAttempt - one non-blocking HTTP gateway send wrapped for the delivery pipeline (HIL-285).
 *
 * Adapts a {@see HttpSmsProvider} plus an {@see AsyncHttpClient} to the channel agent's
 * {@see DeliveryAttempt} seam, exactly as the OAuth agent drives its exchanges: the provider
 * builds the request and interprets the outcome; this attempt owns the client and pumps it one
 * non-blocking step per {@see tick}. The delivery-channel agent owns the pool, retry policy,
 * and delivery-row bookkeeping; this only reflects one send's progress. A send failure never
 * throws out of {@see tick} - a non-2xx status is classified by the provider (4xx permanent,
 * else transient), and a timeout / dropped socket settles as a transient failure.
 */
final class SmsDeliveryAttempt implements SmsSendAttempt
{
    /** The non-blocking client running this send. */
    private readonly AsyncHttpClient $client;

    /** Settled result, latched once the send resolves; null while in flight. */
    private ?SmsSendResult $result = null;

    /**
     * Opens the gateway request on a fresh non-blocking client.
     *
     * @param HttpSmsProvider $provider Provider that builds the request and interprets the response
     * @param SmsMessage $message Recipient message to send
     * @param float $timeoutMs Per-send network timeout in milliseconds
     * @param float $nowMs Current time in milliseconds
     * @throws SmsConfigException When the provider cannot build a request from the config
     * @throws AsyncHttpException When the request cannot be started
     * @throws SocketException When socket creation or configuration fails
     */
    public function __construct(
        private readonly HttpSmsProvider $provider,
        SmsMessage $message,
        float $timeoutMs,
        float $nowMs,
    ) {
        $request = $provider->buildRequest($message);
        $this->client = $this->openClient($request, $timeoutMs, $nowMs);
    }

    /**
     * Pumps the client one step and latches the settled outcome (or a classified failure).
     *
     * @param float $nowMs Current time in milliseconds
     */
    public function tick(float $nowMs): void
    {
        if ($this->result !== null) {
            return;
        }

        try {
            $this->client->tick($nowMs);
        } catch (AsyncHttpStatusException $e) {
            $this->result = $this->provider->classifyStatus($e->statusCode);

            return;
        } catch (AsyncHttpException | SocketException $e) {
            $this->result = SmsSendResult::failed('gateway request failed: ' . $e->getMessage(), false);

            return;
        }

        if ($this->client->isBusy()) {
            return;
        }
        if (!$this->client->hasResult()) {
            $this->result = SmsSendResult::failed('gateway produced no response', false);

            return;
        }

        $this->result = $this->provider->parseResponse($this->client->consumeResult());
    }

    /**
     * @return bool True while the send has not settled
     */
    public function isBusy(): bool
    {
        return $this->result === null;
    }

    /**
     * @return bool True when the settled send was accepted for delivery
     */
    public function isDelivered(): bool
    {
        return $this->result?->delivered ?? false;
    }

    /**
     * @return ?string The settled failure sentence, or null when delivered or unsettled
     */
    public function errorDetail(): ?string
    {
        return $this->result?->errorDetail;
    }

    /**
     * @return bool True when the settled failure is terminal and must not be retried
     */
    public function isPermanentFailure(): bool
    {
        return $this->result !== null && !$this->result->delivered && $this->result->permanent;
    }

    /**
     * Releases the underlying client socket.
     */
    public function close(): void
    {
        $this->client->reset();
    }

    /**
     * Opens a non-blocking client for a gateway request and starts it.
     *
     * @param SmsHttpRequest $request Request to replay
     * @param float $timeoutMs Per-send network timeout in milliseconds
     * @param float $nowMs Current time in milliseconds
     * @return AsyncHttpClient Started client
     * @throws AsyncHttpException When the request cannot be started
     * @throws SocketException When socket creation or configuration fails
     */
    private function openClient(SmsHttpRequest $request, float $timeoutMs, float $nowMs): AsyncHttpClient
    {
        $client = new AsyncHttpClient($request->host, $request->port, $request->path, $request->useTls);
        $client->timeout = $timeoutMs;
        $client->setRequestOptions($request->method, $request->path, $request->body, $request->headers);
        $client->startNewRequest($nowMs);

        return $client;
    }
}
