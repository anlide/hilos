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
 * two-step stage cursor. No deadline: each request is bounded by its own timeout (HIL-1044).
 *
 * Every login goes through one: since the in-process stub was removed (HIL-924) every
 * provider is an {@see HttpOAuthProvider} and resolves the code over the network.
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
     */
    public function __construct(
        public readonly HttpOAuthProvider $provider,
        public int $stage,
        public AsyncHttpClient $client,
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
