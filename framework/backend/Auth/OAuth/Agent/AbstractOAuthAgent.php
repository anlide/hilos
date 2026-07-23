<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth\Agent;

use Hilos\API\AsyncHttpClient;
use Hilos\API\Exception\AsyncHttpException;
use Hilos\Auth\OAuth\DTO\OAuthPendingLoginSignalData;
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
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\OAuthPendingLogins;
use Hilos\Runtime\State\Item\OAuthPendingLogin;
use Hilos\Socket\SocketException;
use Throwable;

/**
 * AbstractOAuthAgent - the framework-owned async owner of in-flight OAuth logins (HIL-281).
 *
 * The tick-loop half of OAuth login mechanism B. The `oauthCallback` action verifies
 * the signed state synchronously (no I/O) and hands the resulting {@see OAuthPendingLogin}
 * op to this agent point-to-point over the {@see HilosSignalConstants::HILOS_OAUTH_PENDING}
 * agent signal ({@see onSignalAgent()}); the op has exactly one consumer — this
 * monopolistic singleton — so a synced signal carries it across the worker→agent process
 * boundary, and the pending-op pool is this agent's own runtime state, not a cross-process
 * collection. This agent drives the token/userinfo round-trips off the master; keeping the
 * exchange in a tick-driven agent — never inside the one-shot page action — is what lets the
 * callback stay non-blocking while the network round-trips run.
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

    /**
     * Callback → agent route for the pending-login handoff (HIL-281). A singleton signal
     * (this agent is monopolistic), so it maps straight to its payload DTO.
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_OAUTH_PENDING => OAuthPendingLoginSignalData::class,
    ];

    /** Default ceiling on concurrently pumped exchanges (outbound sockets, not CPU). */
    private const int DEFAULT_MAX_CONCURRENT = 16;

    /** Default per-request network timeout in milliseconds (token and userinfo each). */
    private const float DEFAULT_HTTP_TIMEOUT_MS = 5000.0;

    /** Providers configured by the project, resolved once on start. */
    private OAuthProviderRegistry $providers;

    /**
     * In-flight pending ops delivered by the callback, this agent's own runtime state.
     *
     * Not a cross-process runtime collection: it is written only by {@see onSignalAgent()}
     * on the tick the callback's handoff arrives and drained by the tick loop, so a raw
     * in-memory collection with no RT sync is exactly right.
     */
    private OAuthPendingLogins $pending;

    /** @var array<string, OAuthExchange> In-flight HTTP exchanges keyed by accept key. */
    private array $exchanges = [];

    /**
     * Resolves the project's configured OAuth providers and initializes the pending-op store.
     */
    public function onStart(): void
    {
        $this->providers = $this->buildProviderRegistry();
        $this->pending = OAuthPendingLogins::init();
    }

    /**
     * Adopts a callback's handed-off pending login, keyed by its accept key (HIL-281).
     *
     * The single intake for the in-flight pool: the verified op is rebuilt from the
     * delivered payload and stored in this agent's own runtime state, then the tick loop
     * drives the exchange. A malformed payload (wrong type) or an unknown signal name is
     * refused loudly so a routing mistake surfaces instead of silently dropping a login.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $source Signal source (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the signal name is not the pending-login handoff
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        if ($name !== HilosSignalConstants::HILOS_OAUTH_PENDING) {
            throw new AgentUnknownSignalException($name);
        }
        if (!$data->data instanceof OAuthPendingLoginSignalData) {
            $this->logAgentWarning(
                HilosSignalConstants::HILOS_OAUTH_PENDING . ' payload must be ' . OAuthPendingLoginSignalData::class,
            );

            return;
        }

        $op = $data->data->toPendingLogin();
        $this->pending->add($op);
        $this->logAgentInfo("adopted OAuth {$op->mode} handoff {$op->getId()} (provider={$op->provider})");
    }

    /**
     * Pumps every in-flight exchange one step and starts any freshly delivered op.
     */
    public function onTick(): void
    {
        $nowMs = microtime(true) * 1000;
        $this->adoptPendingOps($this->pending, $nowMs);
        $this->pumpExchanges($this->pending, $nowMs);
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
        $this->pending->clear();
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
     * @param OAuthPendingLogins $collection Agent-local pending-login pool
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
     * @param OAuthPendingLogins $collection Agent-local pending-login pool
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
     * Completes a resolved exchange and clears the op, branching on the op's flow mode.
     *
     * A {@see OAuthPendingLogin::MODE_LINK} op binds the resolved identity to the
     * already-signed-in initiator ({@see completeOAuthLink}), a framework-owned
     * branch that touches only the framework identity table; a login op hands off
     * to the project's {@see completeOAuthLogin}, which owns the users/sessions
     * account resolution. A thrown login completion is reported as a generic failure.
     *
     * @param OAuthPendingLogin $op Op being completed
     * @param OAuthUserInfo $info Resolved provider identity
     */
    private function succeedOp(OAuthPendingLogin $op, OAuthUserInfo $info): void
    {
        if ($op->mode === OAuthPendingLogin::MODE_LINK) {
            $this->completeOAuthLink($op, $info);

            return;
        }

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
     * Binds a resolved identity to the initiating account in link mode, then clears the op (HIL-401).
     *
     * The framework-owned success path: the initiator is already signed in (their
     * user id rode the signed state and was recorded on the op server-side), so no
     * account is resolved or created and the session is never touched — the identity
     * is written straight through the framework identity primitive. Every terminal
     * outcome is signalled explicitly to the initiating connection, since a link
     * has no session change to ride: success ({@see OAuthResultSignalData::REASON_LINK_OK}),
     * an identity already linked to some account
     * ({@see OAuthResultSignalData::REASON_LINK_DUPLICATE}), or any other write
     * failure ({@see OAuthResultSignalData::REASON_LINK_FAILED}).
     *
     * @param OAuthPendingLogin $op Op being completed (carries the target user and accept key)
     * @param OAuthUserInfo $info Resolved provider identity
     */
    private function completeOAuthLink(OAuthPendingLogin $op, OAuthUserInfo $info): void
    {
        $db = Hilos::$db;
        if (!$db instanceof HilosDbContext) {
            $this->logAgentError("OAuth link failed for {$op->getId()}: identity context is unavailable");
            $this->sendLinkResult($op, OAuthResultSignalData::REASON_LINK_FAILED);
            $this->clearOp($op->getId());

            return;
        }

        try {
            $db->identities->createOauthIdentity($op->linkUserId, $op->provider, $info->subject);
        } catch (DuplicateValueException) {
            $this->logAgentWarning("OAuth link refused for {$op->getId()} (provider={$op->provider}): already linked");
            $this->sendLinkResult($op, OAuthResultSignalData::REASON_LINK_DUPLICATE);
            $this->clearOp($op->getId());

            return;
        } catch (Throwable $e) {
            $this->logAgentError("OAuth link write failed for {$op->getId()}: " . $e->getMessage());
            $this->sendLinkResult($op, OAuthResultSignalData::REASON_LINK_FAILED);
            $this->clearOp($op->getId());

            return;
        }

        $this->logAgentInfo("OAuth link resolved for {$op->getId()} (provider={$op->provider}, user={$op->linkUserId})");
        $this->sendLinkResult($op, OAuthResultSignalData::REASON_LINK_OK);
        $this->clearOp($op->getId());
    }

    /**
     * Delivers a terminal link-result signal to the initiating connection (HIL-401).
     *
     * @param OAuthPendingLogin $op Op whose accept key the signal targets
     * @param string $reason Terminal link reason ({@see OAuthResultSignalData} REASON_LINK_*)
     */
    private function sendLinkResult(OAuthPendingLogin $op, string $reason): void
    {
        $this->sendToUser(
            HilosSignalConstants::HILOS_OAUTH_RESULT,
            $op->acceptKey,
            new OAuthResultSignalData($op->acceptKey, $op->provider, $reason),
        );
    }

    /**
     * Reports a login failure to the initiating connection and clears the op.
     *
     * The client sees a generic failure ({@see OAuthResultSignalData}); the specific cause
     * stays in the agent log so no provider/network detail crosses the wire. The generic
     * reason follows the op's flow mode so a link exchange fails as a link, not a login.
     *
     * @param OAuthPendingLogin $op Op being failed
     * @param string $detail Cause detail for the log only
     */
    private function failOp(OAuthPendingLogin $op, string $detail): void
    {
        $this->logAgentWarning("OAuth {$op->mode} failed for {$op->getId()} (provider={$op->provider}): {$detail}");
        $reason = $op->mode === OAuthPendingLogin::MODE_LINK
            ? OAuthResultSignalData::REASON_LINK_FAILED
            : OAuthResultSignalData::REASON_LOGIN_FAILED;
        $this->sendToUser(
            HilosSignalConstants::HILOS_OAUTH_RESULT,
            $op->acceptKey,
            new OAuthResultSignalData($op->acceptKey, $op->provider, $reason),
        );
        $this->clearOp($op->getId());
    }

    /**
     * Clears an op: closes its exchange and removes the record from the pending pool.
     *
     * @param string $acceptKey Op accept key to clear
     */
    private function clearOp(string $acceptKey): void
    {
        $this->dropExchange($acceptKey);
        $this->pending->remove($acceptKey);
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
}
