<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth\Agent;

use Hilos\API\AsyncHttpClient;
use Hilos\API\Exception\AsyncHttpException;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Auth\OAuth\Exception\OAuthException;
use Hilos\Auth\OAuth\HttpOAuthProvider;
use Hilos\Auth\OAuth\OAuthHttpRequest;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\OAuthUserInfo;
use Hilos\Auth\OAuth\OfflineOAuthProvider;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\OAuthPendingLogins;
use Hilos\Runtime\State\Item\OAuthPendingLogin;
use Hilos\Socket\SocketException;
use Throwable;

/**
 * AbstractOAuthAgent - the framework-owned async owner of in-flight OAuth logins (HIL-281).
 *
 * The tick-loop half of OAuth login mechanism B. The `oauthCallback` action verifies
 * the signed state synchronously (no I/O) and records an {@see OAuthPendingLogin} op
 * keyed by the initiating accept key; this agent observes that collection (mirroring
 * how {@see \Demo\Chat\Agents\ModeratorAgent} observes runtime connections) and drives
 * the token/userinfo round-trips off the master. Keeping the exchange in a tick-driven
 * agent — never inside the one-shot page action — is what lets the callback stay
 * non-blocking while the network round-trips run.
 *
 * It PIPELINES: it holds a pool of independent {@see OAuthExchange} state machines and
 * pumps up to {@see maxConcurrentExchanges()} at once, so a burst of logins does not
 * serialize behind one client (each login is two sequential provider round-trips, and
 * {@see AsyncHttpClient} is one-request-per-instance). Placed as a cluster-leader-pinned
 * monopolistic singleton ({@see AbstractOAuthAgentDaemon}); if login volume ever outgrows
 * one pipelining singleton the daemon can be flipped to per-worker with no contract change.
 *
 * It is abstract, not concrete like {@see \Hilos\Backup\Agent\BackupAgent}, because the
 * success path crosses the framework boundary: resolving/creating the account and binding
 * the live session touch project-owned tables (users, sessions) and the project's own
 * session/currentUser fan-out (HIL-161). The project supplies that step through
 * {@see completeOAuthLogin()} and its configured providers through
 * {@see buildProviderRegistry()}; everything HTTP, timing, and failure-signalling stays here.
 */
abstract class AbstractOAuthAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_OAUTH;

    /** Default ceiling on concurrently pumped exchanges (outbound sockets, not CPU). */
    private const int DEFAULT_MAX_CONCURRENT = 16;

    /** Default per-request network timeout in milliseconds (token and userinfo each). */
    private const float DEFAULT_HTTP_TIMEOUT_MS = 5000.0;

    /** Providers configured by the project, resolved once on start. */
    private OAuthProviderRegistry $providers;

    /** @var array<string, OAuthExchange> In-flight HTTP exchanges keyed by accept key. */
    private array $exchanges = [];

    /**
     * Resolves the project's configured OAuth providers.
     */
    public function onStart(): void
    {
        $this->providers = $this->buildProviderRegistry();
    }

    /**
     * Adopts newly recorded pending ops and pumps every in-flight exchange one step.
     */
    public function onTick(): void
    {
        $collection = $this->pendingLogins();
        if ($collection === null) {
            return;
        }

        $nowMs = microtime(true) * 1000;
        $this->adoptPendingOps($collection, $nowMs);
        $this->pumpExchanges($collection, $nowMs);
    }

    /**
     * Abandons any in-flight exchange on shutdown, closing its socket.
     */
    public function onStop(): void
    {
        foreach ($this->exchanges as $exchange) {
            $exchange->reset();
        }
        $this->exchanges = [];
    }

    /**
     * Builds the provider registry from the project's OAuth provider config.
     *
     * @return OAuthProviderRegistry Configured providers (real providers + dev stub)
     */
    abstract protected function buildProviderRegistry(): OAuthProviderRegistry;

    /**
     * Completes a resolved login: bind the account to the live session and fan out currentUser.
     *
     * Called on the tick the exchange resolves the account identity. The project resolves
     * the account by (oauth, provider:subject) — creating the user + oauth identity on first
     * login (email is never consulted for resolution; collision/merge is HIL-282) — and
     * authenticates {@see OAuthPendingLogin::$sessionToken} to that user, which rides the
     * existing session/currentUser signal (HIL-161). It must not throw on an expected miss;
     * a thrown failure is reported to the client as a generic login failure.
     *
     * @param OAuthPendingLogin $op The pending op being completed
     * @param OAuthUserInfo $info Resolved provider subject and email
     */
    abstract protected function completeOAuthLogin(OAuthPendingLogin $op, OAuthUserInfo $info): void;

    /**
     * @return int Maximum number of exchanges pumped concurrently
     */
    protected function maxConcurrentExchanges(): int
    {
        return self::DEFAULT_MAX_CONCURRENT;
    }

    /**
     * @return float Per-request network timeout in milliseconds
     */
    protected function httpTimeoutMs(): float
    {
        return self::DEFAULT_HTTP_TIMEOUT_MS;
    }

    /**
     * Starts an exchange for each fresh op, failing expired ones and respecting the pool ceiling.
     *
     * Ops are snapshotted before iterating so failing/adopting an op (which mutates the
     * collection) is safe. An op past its deadline is failed regardless of capacity; a fresh
     * op is only started while there is a free pool slot, otherwise it waits for a later tick.
     *
     * @param OAuthPendingLogins $collection Observed pending-login collection
     * @param float $nowMs Current time in milliseconds
     */
    private function adoptPendingOps(OAuthPendingLogins $collection, float $nowMs): void
    {
        $ops = [];
        foreach ($collection as $op) {
            if ($op instanceof OAuthPendingLogin) {
                $ops[] = $op;
            }
        }

        foreach ($ops as $op) {
            if (isset($this->exchanges[$op->getId()])) {
                continue;
            }
            if ($nowMs >= $op->deadlineMs) {
                $this->failOp($op, 'deadline exceeded before the exchange started');
                continue;
            }
            if (count($this->exchanges) >= $this->maxConcurrentExchanges()) {
                continue;
            }

            $this->startOp($op, $nowMs);
        }
    }

    /**
     * Dispatches one op: resolve offline in-process, or open the token request for HTTP.
     *
     * @param OAuthPendingLogin $op Op to start
     * @param float $nowMs Current time in milliseconds
     */
    private function startOp(OAuthPendingLogin $op, float $nowMs): void
    {
        $provider = $this->providers->get($op->provider);
        if ($provider === null) {
            $this->failOp($op, "unknown provider '{$op->provider}'");

            return;
        }

        if ($provider instanceof OfflineOAuthProvider) {
            try {
                $info = $provider->resolve($op->code);
            } catch (OAuthException $e) {
                $this->failOp($op, 'offline resolve failed: ' . $e->getMessage());

                return;
            }

            $this->succeedOp($op, $info);

            return;
        }

        if (!$provider instanceof HttpOAuthProvider) {
            $this->failOp($op, "provider '{$op->provider}' drives neither HTTP nor offline resolution");

            return;
        }

        try {
            $client = $this->openClient($provider->buildTokenRequest($op->code), $nowMs);
        } catch (OAuthException|AsyncHttpException|SocketException $e) {
            $this->failOp($op, 'token request failed to start: ' . $e->getMessage());

            return;
        }

        $this->exchanges[$op->getId()] = new OAuthExchange(
            $provider,
            OAuthExchange::STAGE_TOKEN,
            $client,
            $op->deadlineMs,
        );
    }

    /**
     * Pumps each in-flight exchange one step, advancing token → userinfo → completion.
     *
     * Exchange keys are snapshotted so completing/failing an op (which drops it from the
     * pool) is safe. An op that vanished from the collection (expired elsewhere) drops its
     * exchange; a deadline overrun fails it; any transport or parse error fails it generically.
     *
     * @param OAuthPendingLogins $collection Observed pending-login collection
     * @param float $nowMs Current time in milliseconds
     */
    private function pumpExchanges(OAuthPendingLogins $collection, float $nowMs): void
    {
        foreach (array_keys($this->exchanges) as $acceptKey) {
            $exchange = $this->exchanges[$acceptKey] ?? null;
            if ($exchange === null) {
                continue;
            }

            $op = $collection->get($acceptKey);
            if ($op === null) {
                $this->dropExchange($acceptKey);
                continue;
            }
            if ($nowMs >= $exchange->deadlineMs) {
                $this->failOp($op, 'exchange timed out');
                continue;
            }

            try {
                $this->advanceExchange($op, $exchange, $nowMs);
            } catch (OAuthException|AsyncHttpException|SocketException $e) {
                $this->failOp($op, 'exchange failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Advances one live exchange: ticks its client, then handles a completed stage response.
     *
     * @param OAuthPendingLogin $op Op the exchange belongs to
     * @param OAuthExchange $exchange In-flight exchange to advance
     * @param float $nowMs Current time in milliseconds
     * @throws AsyncHttpException When the client times out or the response is malformed
     * @throws SocketException When an underlying socket operation fails
     * @throws OAuthException When the provider cannot parse a stage response
     */
    private function advanceExchange(OAuthPendingLogin $op, OAuthExchange $exchange, float $nowMs): void
    {
        $exchange->client->tick($nowMs);

        if ($exchange->client->isBusy()) {
            return;
        }
        if (!$exchange->client->hasResult()) {
            $this->failOp($op, 'exchange produced no response');

            return;
        }

        $response = $exchange->client->consumeResult();

        if ($exchange->stage === OAuthExchange::STAGE_TOKEN) {
            $accessToken = $exchange->provider->parseTokenResponse($response);
            $exchange->reset();
            $exchange->client = $this->openClient($exchange->provider->buildUserInfoRequest($accessToken), $nowMs);
            $exchange->stage = OAuthExchange::STAGE_USERINFO;

            return;
        }

        $info = $exchange->provider->parseUserInfoResponse($response);
        if ($info->subject === '') {
            $this->failOp($op, 'userinfo resolved no subject');

            return;
        }

        $this->succeedOp($op, $info);
    }

    /**
     * Opens a non-blocking client for a provider request and starts it.
     *
     * A fresh client per stage: the token and userinfo endpoints may be different hosts,
     * and a client binds host/port/TLS at construction and serves one request at a time.
     *
     * @param OAuthHttpRequest $request Request to replay
     * @param float $nowMs Current time in milliseconds
     * @return AsyncHttpClient Started client
     * @throws AsyncHttpException When the request cannot be started
     * @throws SocketException When socket creation or configuration fails
     */
    private function openClient(OAuthHttpRequest $request, float $nowMs): AsyncHttpClient
    {
        $client = new AsyncHttpClient($request->host, $request->port, $request->path, $request->useTls);
        $client->timeout = $this->httpTimeoutMs();
        $client->setRequestOptions($request->method, $request->path, $request->body, $request->headers);
        $client->startNewRequest($nowMs);

        return $client;
    }

    /**
     * Completes a resolved login and clears the op; a thrown completion is reported as a failure.
     *
     * @param OAuthPendingLogin $op Op being completed
     * @param OAuthUserInfo $info Resolved provider identity
     */
    private function succeedOp(OAuthPendingLogin $op, OAuthUserInfo $info): void
    {
        try {
            $this->completeOAuthLogin($op, $info);
        } catch (Throwable $e) {
            $this->logAgentError("OAuth login completion failed for {$op->getId()}: " . $e->getMessage());
            $this->failOp($op, 'login completion failed');

            return;
        }

        $this->logAgentInfo("OAuth login resolved for {$op->getId()} (provider={$op->provider})");
        $this->clearOp($op->getId());
    }

    /**
     * Reports a login failure to the initiating connection and clears the op.
     *
     * The client sees a generic failure ({@see OAuthResultSignalData}); the specific cause
     * stays in the agent log so no provider/network detail crosses the wire.
     *
     * @param OAuthPendingLogin $op Op being failed
     * @param string $detail Cause detail for the log only
     */
    private function failOp(OAuthPendingLogin $op, string $detail): void
    {
        $this->logAgentWarning("OAuth login failed for {$op->getId()} (provider={$op->provider}): {$detail}");
        $this->sendToUser(
            HilosSignalConstants::HILOS_OAUTH_RESULT,
            $op->acceptKey,
            new OAuthResultSignalData($op->acceptKey, $op->provider),
        );
        $this->clearOp($op->getId());
    }

    /**
     * Clears an op: closes its exchange and removes the record (best-effort, deadline-bounded).
     *
     * The removal is a local drop, not a truth-source delete — the collection is written by
     * the callback side, and any copy that outlives this drop is reaped by its own deadline.
     *
     * @param string $acceptKey Op accept key to clear
     */
    private function clearOp(string $acceptKey): void
    {
        $this->dropExchange($acceptKey);
        $this->pendingLogins()?->remove($acceptKey);
    }

    /**
     * Drops an in-flight exchange from the pool, closing its socket.
     *
     * @param string $acceptKey Op accept key whose exchange is dropped
     */
    private function dropExchange(string $acceptKey): void
    {
        $exchange = $this->exchanges[$acceptKey] ?? null;
        $exchange?->reset();
        unset($this->exchanges[$acceptKey]);
    }

    /**
     * Resolves the observed pending-login collection, or null when it is unavailable.
     *
     * @return ?OAuthPendingLogins Pending-login collection or null
     */
    private function pendingLogins(): ?OAuthPendingLogins
    {
        $collection = Hilos::$rt?->getStateCollection(OAuthPendingLogin::RT_COLLECTION);

        return $collection instanceof OAuthPendingLogins ? $collection : null;
    }
}
