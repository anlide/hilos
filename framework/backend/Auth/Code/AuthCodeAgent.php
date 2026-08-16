<?php

declare(strict_types=1);

namespace Hilos\Auth\Code;

use Hilos\API\AsyncHttpClient;
use Hilos\API\DTO\AsyncHttpRequest;
use Hilos\API\DTO\AsyncHttpResponse;
use Hilos\API\Exception\AsyncHttpException;
use Hilos\Auth\Code\DTO\AuthCodeResultSignalData;
use Hilos\Auth\Code\DTO\AuthCodeSendSignalData;
use Hilos\Auth\CodeChannel\CodeChannel;
use Hilos\Auth\CodeChannel\CodeChannelProbe;
use Hilos\Auth\Verification\VerificationIssuedCode;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Hilos;
use Hilos\Socket\SocketException;
use Throwable;

/**
 * AuthCodeAgent - the async owner of outgoing phone one-time codes (HIL-492).
 *
 * The tick-loop half of the code-channel mechanism. A page action that wants a code
 * sent validates only what costs nothing and hands the request here over
 * {@see HilosSignalConstants::HILOS_AUTH_CODE_SEND}; this agent drives what a worker
 * may not - a network round-trip to ask a messenger whether it can reach the number,
 * then the mint, then the delivery - and reports every outcome back to the requesting
 * socket on {@see HilosSignalConstants::HILOS_AUTH_CODE_RESULT}.
 *
 * The ORDER is the design, not an implementation detail: probe, then mint, then send.
 * A channel that cannot reach the target must cost the person NOTHING - no challenge
 * row, no spent cooldown - because the next thing they will do is pick another channel
 * and they are owed a code on it. Minting first would burn the cooldown on a message
 * that was never sent. The probe is free where it matters: the Telegram Gateway bills
 * once per request id, and the id the probe opens is the one the send reuses.
 *
 * It PIPELINES, like the OAuth agent it is shaped after: a pool of independent
 * {@see AuthCodeOperation} state machines, with at most
 * {@see maxConcurrentOperations()} holding a socket at once, since
 * {@see AsyncHttpClient} is one-request-per-instance and a burst of sign-ins must not
 * serialize behind one slow messenger.
 *
 * It is CONCRETE, where the OAuth agent is abstract, and the difference says what this
 * agent does not do: nothing here touches a project table. It mints through
 * {@see VerificationService}, delivers through a {@see CodeChannel} the project
 * registered, and signals an accept key. Whoever the code belongs to is decided later,
 * on the confirm action, by the project.
 *
 * A monopolistic singleton ({@see AuthCodeAgentDaemon}). Ops live only in its memory,
 * so a restart loses in-flight requests - correct for this operation: a code request is
 * seconds long and the person is watching a spinner, so the honest recovery is them
 * pressing the button again, not a resurrected send to a screen that has moved on.
 */
class AuthCodeAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_AUTH_CODE;

    /**
     * Page action → agent route for one code request. A singleton signal (this agent
     * is monopolistic), so it maps straight to its payload DTO with no index field.
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_AUTH_CODE_SEND => AuthCodeSendSignalData::class,
    ];

    /** Default ceiling on operations holding a socket at once (outbound sockets, not CPU). */
    private const int DEFAULT_MAX_CONCURRENT = 16;

    /** Default per-request network timeout in milliseconds (probe and send each). */
    private const float DEFAULT_HTTP_TIMEOUT_MS = 5000.0;

    /** Default whole-operation deadline in milliseconds, covering probe, mint and send. */
    private const float DEFAULT_OPERATION_TTL_MS = 15000.0;

    /** @var array<int, AuthCodeOperation> In-flight operations keyed by a monotonic op id. */
    private array $operations = [];

    /** Next op id for an adopted request. */
    private int $nextId = 0;

    /**
     * Adopts one handed-off code request, resolving its channel up front.
     *
     * The single intake. An unknown signal name is refused loudly so a routing mistake
     * surfaces; a malformed payload is dropped with a log, and a channel the registry
     * does not carry - or one that does not serve this verification type - is answered
     * as an unavailable channel rather than dropped, because a guest is holding a
     * spinner that only a signal can end.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $source Signal source (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the signal name is not the code-request handoff
     * @throws InvalidArgumentException When the outcome signal cannot be named or queued
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        if ($name !== HilosSignalConstants::HILOS_AUTH_CODE_SEND) {
            throw new AgentUnknownSignalException($name);
        }
        if (!$data->data instanceof AuthCodeSendSignalData) {
            $this->logAgentWarning(
                HilosSignalConstants::HILOS_AUTH_CODE_SEND . ' payload must be ' . AuthCodeSendSignalData::class,
            );

            return;
        }

        $request = $data->data;
        $channel = $this->resolveChannel($request->channel);
        if ($channel === null || !$channel->supportsType($request->type)) {
            $this->logAgentWarning(
                "code request refused: channel '{$request->channel}' does not serve type '{$request->type}'",
            );
            $this->report($request, AuthCodeResultSignalData::REASON_CHANNEL_UNAVAILABLE);

            return;
        }

        $this->operations[$this->nextId++] = new AuthCodeOperation(
            $request,
            $channel,
            AuthCodeOperation::STAGE_PROBE,
            microtime(true) * TimeConstants::MS_PER_SECOND + $this->operationTtlMs(),
        );
    }

    /**
     * Pumps every in-flight operation one step.
     *
     * Op ids are snapshotted so finishing an op (which drops it from the pool) is safe.
     * Any failure inside one operation is contained to that operation: it is reported
     * as a refused send and the rest of the pool keeps moving, because a burst of
     * sign-ins must not be taken down by one broken transport.
     *
     * @throws InvalidArgumentException When an outcome signal cannot be named or queued
     */
    public function onTick(): void
    {
        $nowMs = microtime(true) * TimeConstants::MS_PER_SECOND;

        foreach (array_keys($this->operations) as $id) {
            $operation = $this->operations[$id] ?? null;
            if ($operation === null) {
                continue;
            }

            if ($nowMs >= $operation->deadlineMs) {
                $this->fail($id, $operation, 'operation timed out');
                continue;
            }

            try {
                $this->advance($id, $operation, $nowMs);
            } catch (Throwable $e) {
                $this->fail($id, $operation, 'operation failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Abandons every in-flight operation on shutdown, closing its socket.
     */
    public function onStop(): void
    {
        foreach ($this->operations as $operation) {
            $operation->closeClient();
        }
        $this->operations = [];
    }

    /**
     * @return int Maximum number of operations holding a socket at once
     */
    protected function maxConcurrentOperations(): int
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
     * @return float Whole-operation deadline in milliseconds
     */
    protected function operationTtlMs(): float
    {
        return self::DEFAULT_OPERATION_TTL_MS;
    }

    /**
     * Resolves a channel name against the project's registry.
     *
     * @param string $channel Channel name the request named
     * @return ?CodeChannel Registered channel, or null when the project carries none by that name
     */
    protected function resolveChannel(string $channel): ?CodeChannel
    {
        return Hilos::codeChannelRegistryClass()::get($channel);
    }

    /**
     * Advances one operation by its stage.
     *
     * @param int $id Op id in the pool
     * @param AuthCodeOperation $operation Operation to advance
     * @param float $nowMs Current time in milliseconds
     * @throws AsyncHttpException When a stage request cannot start, times out, or answers malformed
     * @throws SocketException When an underlying socket operation fails
     * @throws InvalidArgumentException When an outcome signal cannot be named or queued
     */
    private function advance(int $id, AuthCodeOperation $operation, float $nowMs): void
    {
        if ($operation->stage === AuthCodeOperation::STAGE_PROBE) {
            $this->advanceProbe($id, $operation, $nowMs);

            return;
        }

        $this->advanceSend($id, $operation, $nowMs);
    }

    /**
     * Drives the reachability question.
     *
     * A channel that answers without the network settles inside this tick; one that
     * needs a round-trip opens a client here and is read on a later tick. A request
     * held back by the concurrency ceiling simply waits for a free slot - the whole
     * operation still has its deadline, so nothing waits forever.
     *
     * @param int $id Op id in the pool
     * @param AuthCodeOperation $operation Operation being probed
     * @param float $nowMs Current time in milliseconds
     * @throws AsyncHttpException When the probe cannot start, times out, or answers malformed
     * @throws SocketException When an underlying socket operation fails
     * @throws InvalidArgumentException When an outcome signal cannot be named or queued
     */
    private function advanceProbe(int $id, AuthCodeOperation $operation, float $nowMs): void
    {
        if ($operation->client === null) {
            $request = $operation->channel->probeRequest($operation->request->identifier);
            if ($request === null) {
                $reaches = $operation->channel->reaches($operation->request->identifier);
                $probe = $reaches ? CodeChannelProbe::reachable() : CodeChannelProbe::unreachable();
                $this->settleProbe($id, $operation, $probe, $nowMs);

                return;
            }
            if ($this->socketsInUse() < $this->maxConcurrentOperations()) {
                $this->startRequest($operation, $request, $nowMs);
            }

            return;
        }

        $response = $this->collect($operation, $nowMs);
        if ($response === null) {
            return;
        }

        $this->settleProbe($id, $operation, $operation->channel->readProbe($response), $nowMs);
    }

    /**
     * Acts on a settled probe: report an unreachable channel, or mint and move to the send.
     *
     * The mint is here rather than at intake precisely because it must not happen for a
     * channel that cannot deliver: a refused probe leaves no challenge row and spends no
     * cooldown, so the person can pick another channel and still get their first code.
     *
     * @param int $id Op id in the pool
     * @param AuthCodeOperation $operation Operation whose probe settled
     * @param CodeChannelProbe $probe Probe result carrying reachability and the send's handle
     * @param float $nowMs Current time in milliseconds
     * @throws AsyncHttpException When the send cannot start
     * @throws SocketException When an underlying socket operation fails
     * @throws InvalidArgumentException When an outcome signal cannot be named or queued
     */
    private function settleProbe(int $id, AuthCodeOperation $operation, CodeChannelProbe $probe, float $nowMs): void
    {
        $operation->closeClient();

        if (!$probe->reachable) {
            $this->finish($id, $operation, AuthCodeResultSignalData::REASON_CHANNEL_UNAVAILABLE);

            return;
        }

        $issued = $this->issue($operation);
        if ($issued->code === null) {
            $this->reportRefusedIssue($id, $operation, $issued);

            return;
        }

        $operation->probeToken = $probe->token;
        $operation->code = $issued->code;
        $operation->resendInSeconds = $issued->outcome->resendInSeconds;
        $operation->stage = AuthCodeOperation::STAGE_SEND;

        $this->advanceSend($id, $operation, $nowMs);
    }

    /**
     * Reports a mint the send gate refused, without minting or sending anything.
     *
     * The two refusals are told apart because the surface owes different things: a
     * cooldown is a countdown to draw, a cap is a sentence to show - so the cap
     * carries no seconds, which would otherwise promise a button that is not coming
     * back this window.
     *
     * @param int $id Op id in the pool
     * @param AuthCodeOperation $operation Operation being refused
     * @param VerificationIssuedCode $issued Refused issue carrying the gate's verdict
     * @throws InvalidArgumentException When the outcome signal cannot be named or queued
     */
    private function reportRefusedIssue(int $id, AuthCodeOperation $operation, VerificationIssuedCode $issued): void
    {
        if ($issued->outcome->capReached) {
            $this->finish($id, $operation, AuthCodeResultSignalData::REASON_CAP_REACHED);

            return;
        }

        $this->finish(
            $id,
            $operation,
            AuthCodeResultSignalData::REASON_RATE_LIMITED,
            $issued->outcome->resendInSeconds,
        );
    }

    /**
     * Drives the delivery of a minted code: a handoff settles now, an HTTP send opens a client.
     *
     * The cooldown of the fresh issue rides every arm from here on, the failure one
     * included: the code was minted, so the gate has already counted it, and saying
     * otherwise would offer a resend the gate then refuses.
     *
     * @param int $id Op id in the pool
     * @param AuthCodeOperation $operation Operation delivering its code
     * @param float $nowMs Current time in milliseconds
     * @throws AsyncHttpException When the send cannot start, times out, or answers malformed
     * @throws SocketException When an underlying socket operation fails
     * @throws InvalidArgumentException When an outcome signal cannot be named or queued
     */
    private function advanceSend(int $id, AuthCodeOperation $operation, float $nowMs): void
    {
        if ($operation->client === null) {
            $this->startSend($id, $operation, $nowMs);

            return;
        }

        $response = $this->collect($operation, $nowMs);
        if ($response === null) {
            return;
        }

        $send = $operation->channel->readSend($response);
        if (!$send->delivered) {
            $this->logAgentWarning($this->describe($operation) . ' send refused: ' . ($send->detail ?? 'no detail'));
            $this->finish($id, $operation, AuthCodeResultSignalData::REASON_SEND_FAILED, $operation->resendInSeconds);

            return;
        }

        $this->finish($id, $operation, AuthCodeResultSignalData::REASON_CODE_SENT, $operation->resendInSeconds);
    }

    /**
     * Hands the minted code to its channel, either by handoff or by opening a send request.
     *
     * A handoff is reported sent once it is accepted, not once it arrives: the
     * subsystem it goes to owns retries and the outcome, and waiting on it here would
     * mean owning that outcome twice.
     *
     * @param int $id Op id in the pool
     * @param AuthCodeOperation $operation Operation whose code is being handed to the channel
     * @param float $nowMs Current time in milliseconds
     * @throws AsyncHttpException When the send request cannot start
     * @throws SocketException When an underlying socket operation fails
     * @throws InvalidArgumentException When the outcome signal cannot be named or queued
     */
    private function startSend(int $id, AuthCodeOperation $operation, float $nowMs): void
    {
        $code = (string)$operation->code;
        $request = $operation->channel->sendRequest($operation->request->identifier, $code, $operation->probeToken);

        if ($request !== null) {
            if ($this->socketsInUse() < $this->maxConcurrentOperations()) {
                $this->startRequest($operation, $request, $nowMs);
            }

            return;
        }

        try {
            $operation->channel->handoff($operation->request->identifier, $operation->request->type, $code);
        } catch (Throwable $e) {
            $this->logAgentWarning($this->describe($operation) . ' handoff refused the code: ' . $e->getMessage());
            $this->finish($id, $operation, AuthCodeResultSignalData::REASON_SEND_FAILED, $operation->resendInSeconds);

            return;
        }

        $this->finish($id, $operation, AuthCodeResultSignalData::REASON_CODE_SENT, $operation->resendInSeconds);
    }

    /**
     * Mints a code for the operation through the verification layer.
     *
     * The owning user is null on purpose: a phone-login code is issued before anyone
     * knows whose phone it is, and the account is find-or-created on the confirm.
     *
     * @param AuthCodeOperation $operation Operation to mint for
     * @return VerificationIssuedCode The gate's verdict, and the code when one was minted
     */
    private function issue(AuthCodeOperation $operation): VerificationIssuedCode
    {
        return new VerificationService()->issueForChannel(
            $operation->request->type,
            $operation->request->identifier,
            null,
            $operation->channel->name(),
        );
    }

    /**
     * Opens a non-blocking client for one stage request.
     *
     * @param AuthCodeOperation $operation Operation the request belongs to
     * @param AsyncHttpRequest $request Stage request to replay
     * @param float $nowMs Current time in milliseconds
     * @throws AsyncHttpException When the request cannot start
     * @throws SocketException When an underlying socket operation fails
     */
    private function startRequest(AuthCodeOperation $operation, AsyncHttpRequest $request, float $nowMs): void
    {
        $client = new AsyncHttpClient($request->host, $request->port, $request->path, $request->useTls);
        // The channel's own budget when it has one: it knows what is at the other end
        // of the socket, and the agent only knows how to hold it open.
        $client->timeout = $operation->channel->timeoutMs() ?? $this->httpTimeoutMs();
        $client->setRequestOptions($request->method, $request->path, $request->body, $request->headers);
        $client->startNewRequest($nowMs);

        $operation->client = $client;
    }

    /**
     * Ticks the current stage's client and takes its response once it has one.
     *
     * @param AuthCodeOperation $operation Operation whose stage is in flight
     * @param float $nowMs Current time in milliseconds
     * @return ?AsyncHttpResponse Completed response, or null while the stage is still running
     * @throws AsyncHttpException When the request times out or the response is malformed
     * @throws SocketException When an underlying socket operation fails
     */
    private function collect(AuthCodeOperation $operation, float $nowMs): ?AsyncHttpResponse
    {
        $client = $operation->client;
        if ($client === null) {
            return null;
        }

        $client->tick($nowMs);
        if ($client->isBusy() || !$client->hasResult()) {
            return null;
        }

        return $client->consumeResult();
    }

    /**
     * @return int Number of operations currently holding a socket
     */
    private function socketsInUse(): int
    {
        $count = 0;
        foreach ($this->operations as $operation) {
            if ($operation->client !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Reports an outcome to the requesting connection and drops the operation.
     *
     * @param int $id Op id in the pool
     * @param AuthCodeOperation $operation Operation being finished
     * @param string $reason Stable outcome reason (see AuthCodeResultSignalData REASON_*)
     * @param ?int $resendInSeconds Seconds until a send is allowed again, or null when waiting is not the answer
     * @throws InvalidArgumentException When the outcome signal cannot be named or queued
     */
    private function finish(int $id, AuthCodeOperation $operation, string $reason, ?int $resendInSeconds = null): void
    {
        $operation->closeClient();
        unset($this->operations[$id]);

        $this->report($operation->request, $reason, $resendInSeconds);
    }

    /**
     * Reports a failed operation as a refused send, logging the cause.
     *
     * The client is told the transport failed and nothing else: the person asking is a
     * guest, so no network or provider detail crosses to them.
     *
     * @param int $id Op id in the pool
     * @param AuthCodeOperation $operation Operation being failed
     * @param string $detail Cause detail for the log only
     * @throws InvalidArgumentException When the outcome signal cannot be named or queued
     */
    private function fail(int $id, AuthCodeOperation $operation, string $detail): void
    {
        $this->logAgentWarning($this->describe($operation) . ' failed: ' . $detail);
        $this->finish($id, $operation, AuthCodeResultSignalData::REASON_SEND_FAILED, $operation->resendInSeconds);
    }

    /**
     * Queues the outcome signal to the requesting connection's accept key.
     *
     * @param AuthCodeSendSignalData $request Request being answered
     * @param string $reason Stable outcome reason (see AuthCodeResultSignalData REASON_*)
     * @param ?int $resendInSeconds Seconds until a send is allowed again, or null
     * @throws InvalidArgumentException When the outcome signal cannot be named or queued
     */
    private function report(AuthCodeSendSignalData $request, string $reason, ?int $resendInSeconds = null): void
    {
        $this->sendToUser(
            HilosSignalConstants::HILOS_AUTH_CODE_RESULT,
            $request->acceptKey,
            new AuthCodeResultSignalData($request->acceptKey, $request->channel, $reason, $resendInSeconds),
        );
    }

    /**
     * Names an operation for the log without naming its target.
     *
     * The identifier is a phone number and never reaches a log line; the channel and
     * the type are enough to read a failure by.
     *
     * @param AuthCodeOperation $operation Operation to describe
     * @return string Log-safe description
     */
    private function describe(AuthCodeOperation $operation): string
    {
        return "code request (channel={$operation->request->channel}, type={$operation->request->type})";
    }
}
