<?php

declare(strict_types=1);

namespace Hilos\Push\Delivery;

use Hilos\API\AsyncHttpClient;
use Hilos\API\Exception\AsyncHttpException;
use Hilos\API\Exception\AsyncHttpStatusException;
use Hilos\Socket\SocketException;

/**
 * PushEndpointSend - one device endpoint's non-blocking web-push send (HIL-199).
 *
 * Push is multi-address: one notification fans out to every device a recipient subscribed. This
 * wraps a single endpoint's HTTP send on its own {@see AsyncHttpClient}, pumped one step per
 * {@see tick} by {@see PushDeliveryAttempt}, and latches a classified outcome the moment the send
 * settles. A 2xx is delivered; a 404/410 marks the subscription {@see isGone()} (the transport says
 * the endpoint no longer exists) so the attempt prunes it; any other status, a timeout, or a dropped
 * socket is a plain failure. A send failure never throws out of {@see tick}. A send whose request
 * could not even be built is created pre-settled through {@see failed()}.
 */
final class PushEndpointSend
{
    /** Push service status: the subscription is gone and must be pruned. */
    private const int STATUS_NOT_FOUND = 404;

    /** Push service status: the subscription is gone and must be pruned. */
    private const int STATUS_GONE = 410;

    /** Whether the settled send delivered; null while still in flight. */
    private ?bool $delivered = null;

    /** Whether the endpoint reported itself gone (404/410) and should be pruned. */
    private bool $gone = false;

    /** Settled failure detail, or null while in flight or on success. */
    private ?string $error = null;

    /**
     * Wraps a started client as the in-flight send for one endpoint.
     *
     * @param string $endpoint Target device endpoint URL (kept for pruning and logging)
     * @param ?AsyncHttpClient $client Client already started on the endpoint's request, or null for a pre-settled failure
     */
    public function __construct(
        public readonly string $endpoint,
        private ?AsyncHttpClient $client,
    ) {
    }

    /**
     * Builds a pre-settled failed send for an endpoint whose request could not be assembled.
     *
     * @param string $endpoint Target device endpoint URL
     * @param string $error Failure detail
     * @return self A send that is already settled as a non-delivered, non-gone failure
     */
    public static function failed(string $endpoint, string $error): self
    {
        $send = new self($endpoint, null);
        $send->delivered = false;
        $send->error = $error;

        return $send;
    }

    /**
     * Pumps the client one step and latches the classified outcome once the send settles.
     *
     * @param float $nowMs Current time in milliseconds
     */
    public function tick(float $nowMs): void
    {
        if ($this->client === null || $this->delivered !== null) {
            return;
        }

        try {
            $this->client->tick($nowMs);
        } catch (AsyncHttpStatusException $e) {
            $this->settleStatus($e->statusCode);

            return;
        } catch (AsyncHttpException | SocketException $e) {
            $this->settle(false, false, 'push send failed: ' . $e->getMessage());

            return;
        }

        if ($this->client->isBusy()) {
            return;
        }
        if ($this->client->hasResult()) {
            $this->client->consumeResult();
            $this->settle(true, false, null);

            return;
        }

        $this->settle(false, false, 'push service produced no response');
    }

    /**
     * @return bool True while the send has not settled
     */
    public function isBusy(): bool
    {
        return $this->delivered === null;
    }

    /**
     * @return bool True when the settled send was accepted by the push service
     */
    public function isDelivered(): bool
    {
        return $this->delivered ?? false;
    }

    /**
     * @return bool True when the endpoint reported itself gone (404/410) and must be pruned
     */
    public function isGone(): bool
    {
        return $this->gone;
    }

    /**
     * @return ?string The settled failure detail, or null when delivered or unsettled
     */
    public function errorDetail(): ?string
    {
        return $this->error;
    }

    /**
     * Releases the underlying client socket.
     */
    public function close(): void
    {
        $this->client?->reset();
        $this->client = null;
    }

    /**
     * Classifies a non-2xx push status into a gone-and-prune or a plain failure.
     *
     * @param int $statusCode HTTP status the push service returned
     */
    private function settleStatus(int $statusCode): void
    {
        $gone = $statusCode === self::STATUS_NOT_FOUND || $statusCode === self::STATUS_GONE;
        $this->settle(false, $gone, 'push service returned status ' . $statusCode);
    }

    /**
     * Latches the settled outcome for this send.
     *
     * @param bool $delivered Whether the send was accepted
     * @param bool $gone Whether the endpoint is gone and must be pruned
     * @param ?string $error Failure detail, or null on success
     */
    private function settle(bool $delivered, bool $gone, ?string $error): void
    {
        $this->delivered = $delivered && !$gone;
        $this->gone = $gone;
        $this->error = $this->delivered ? null : $error;
    }
}
