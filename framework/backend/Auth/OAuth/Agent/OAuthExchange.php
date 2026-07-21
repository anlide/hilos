<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth\Agent;

use Hilos\API\AsyncHttpClient;
use Hilos\Auth\OAuth\HttpOAuthProvider;

/**
 * OAuthExchange - the mutable per-op state of one in-flight HTTP OAuth exchange (HIL-281).
 *
 * The framework OAuth agent pipelines many of these at once (one per pending login),
 * pumping each across ticks. It holds the provider that builds/parses the requests,
 * the current {@see AsyncHttpClient} (a fresh one per stage — the token and userinfo
 * endpoints may be different hosts, and a client is one-request-per-instance), the
 * two-step stage cursor, and the op's absolute deadline.
 *
 * Offline providers never produce an exchange: they resolve the code in-process, so
 * only {@see HttpOAuthProvider} exchanges live here.
 */
final class OAuthExchange
{
    /** Stage: waiting on the token endpoint response. */
    public const int STAGE_TOKEN = 1;

    /** Stage: waiting on the userinfo endpoint response. */
    public const int STAGE_USERINFO = 2;

    /**
     * @param HttpOAuthProvider $provider Provider that builds and parses the requests
     * @param int $stage Current stage ({@see STAGE_TOKEN} or {@see STAGE_USERINFO})
     * @param AsyncHttpClient $client Non-blocking client for the current stage
     * @param float $deadlineMs Absolute deadline in milliseconds
     */
    public function __construct(
        public readonly HttpOAuthProvider $provider,
        public int $stage,
        public AsyncHttpClient $client,
        public readonly float $deadlineMs,
    ) {
    }

    /**
     * Closes the current client's socket and clears its state.
     */
    public function reset(): void
    {
        $this->client->reset();
    }
}
