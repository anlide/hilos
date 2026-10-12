<?php

declare(strict_types=1);

namespace Hilos\Auth\Code;

use Hilos\Auth\Code\DTO\AuthCodeMintSignalData;
use Hilos\Auth\Code\DTO\AuthCodeMintedSignalData;
use Hilos\API\AsyncHttpClient;
use Hilos\API\DTO\AsyncHttpRequest;
use Hilos\API\DTO\AsyncHttpResponse;
use Hilos\API\Exception\AsyncHttpException;
use Hilos\Auth\Code\DTO\AuthCodeSendSignalData;
use Hilos\Auth\Code\DTO\CodeSendStepSignalData;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\DTO\AuthRegistrationWaitHeldSignalData;
use Hilos\Auth\CodeChannel\CodeChannel;
use Hilos\Auth\CodeChannel\CodeChannelProbe;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt;
use Hilos\Socket\SocketException;
use Throwable;

/**
 * AuthCodeAgent - the async owner of outgoing phone one-time codes (HIL-492).
 *
 * The tick-loop half of the code-channel mechanism. A page action that wants a code
 * sent validates only what costs nothing and hands the request here over
 * {@see HilosSignalConstants::HILOS_AUTH_CODE_SEND}; this agent drives what a worker
 * may not - a network round-trip to ask a messenger whether it can reach the number,
 * then the mint, then the delivery - and reports every outcome on the closing step of the
 * send's progress line ({@see HilosSignalConstants::HILOS_CODE_SEND_STEP}), which the session
 * holder keeps and replays to every tab of the session, a reconnected one included (HIL-1044).
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
 * It writes no database table: the users library holds a free number and issues its code
 * on the request sent after the probe (HIL-1411). This agent alone knows whether the channel
 * can reach the number, and then delivers through the registered CodeChannel. The closing
 * step and registration wait leave from this same sender, in their established order.
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
     * Requests start a probe; minted orders start delivery or close the send with a refusal.
     * Both are singleton DTO routes, with no node field (HIL-1411).
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_AUTH_CODE_SEND => AuthCodeSendSignalData::class,
        HilosSignalConstants::HILOS_AUTH_CODE_MINTED => AuthCodeMintedSignalData::class,
    ];

    /** Default ceiling on operations holding a socket at once (outbound sockets, not CPU). */
    private const int DEFAULT_MAX_CONCURRENT = 16;

    /** Default per-request network timeout in milliseconds (probe and send each). */
    private const float DEFAULT_HTTP_TIMEOUT_MS = 5000.0;

    /** @var array<int, AuthCodeOperation> In-flight operations keyed by a monotonic op id. */
    private array $operations = [];

    /** Next op id for an adopted request. */
    private int $nextId = 0;

    /**
     * Adopts a request for a probe, or a self-contained delivery order from the users library.
     * The latter may reach an agent that never held the probe operation (HIL-1411).
     *
     * @param AgentSignalData $data Wrapped payload parsed by the topology
     * @param string $sender Sender description (unused)
     * @param string $name Routed signal name
     * @throws AgentUnknownSignalException When the name is not declared by this agent
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_AUTH_CODE_SEND:
                $this->adoptRequest($data->data);

                return;

            case HilosSignalConstants::HILOS_AUTH_CODE_MINTED:
                $this->adoptMinted($data->data);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
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

            try {
                $this->advance($id, $operation, $nowMs);
            } catch (Throwable $e) {
                $this->fail($id, $operation, 'operation failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Answers every in-flight operation with a refused send on shutdown, closing its socket.
     *
     * Silence is not an option here any more (HIL-1044): the browser waiting on a code has no
     * clock left to end its wait, and a send this agent drops without a word is a line saying
     * "sending" forever. The refusal leaves in the same tick, ahead of the stop, as every frame
     * of an ordinary stop does.
     * Between mint and minted the operation is outside this pool: its line waits for code
     * expiry or a resend, as on a silent transport (HIL-1411).
     * On a node's stop the holder may be leaving in the same wave, and the refusal finds it gone;
     * the holder then fails the send itself, on its own stop
     * ({@see AbstractSessionsLibraryAgent::onStop()}, HIL-1207).
     *
     * @throws InvalidArgumentException When a refusal cannot be named or queued
     */
    public function onStop(): void
    {
        foreach ($this->operations as $id => $operation) {
            $this->fail($id, $operation, 'agent stopped');
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
     * Resolves a channel name against the project's registry.
     *
     * @param string $channel Channel name the request named
     * @return ?CodeChannel Registered channel, or null when the project carries none by that name
     */
    protected function resolveChannel(string $channel): ?CodeChannel
    {
        return Hilos::codeChannelRegistryClass()::get($channel);
    }

    /** @param AuthCodeSendSignalData $request Request to probe before asking for a code */
    private function adoptRequest(AuthCodeSendSignalData $request): void
    {
        $channel = $this->resolveChannel($request->channel);
        if ($channel === null || !$channel->supportsType($request->type)) {
            $this->logAgentWarning(
                "code request refused: channel '{$request->channel}' does not serve type '{$request->type}'",
            );
            $this->reportCodeSendStep(
                $request,
                HilosCodeSendAttempt::STATE_FAILED,
                null,
                HilosCodeSendAttempt::REASON_CHANNEL_UNAVAILABLE,
            );

            return;
        }

        $this->operations[$this->nextId++] = new AuthCodeOperation($request, $channel, AuthCodeOperation::STAGE_PROBE);
    }

    /** @param AuthCodeMintedSignalData $minted Delivery order independent of the original probe's agent */
    private function adoptMinted(AuthCodeMintedSignalData $minted): void
    {
        $channel = $this->resolveChannel($minted->request->channel);
        if ($channel === null || !$channel->supportsType($minted->request->type)) {
            $this->logAgentWarning(
                "minted code refused: channel '{$minted->request->channel}' does not serve type '{$minted->request->type}'",
            );
            $this->reportCodeSendStep(
                $minted->request,
                HilosCodeSendAttempt::STATE_FAILED,
                null,
                HilosCodeSendAttempt::REASON_CHANNEL_UNAVAILABLE,
            );

            return;
        }

        $id = $this->nextId++;
        $operation = new AuthCodeOperation($minted->request, $channel, AuthCodeOperation::STAGE_SEND);
        $operation->probeToken = $minted->probeToken;
        $operation->code = $minted->code;
        $operation->resendAt = $minted->resendAt;
        $operation->expiresAt = $minted->expiresAt;
        $operation->registration = $minted->registration;
        if ($minted->code === null) {
            $this->finish($id, $operation, $minted->reason, $minted->resendAt);

            return;
        }

        $this->operations[$id] = $operation;
    }

    /**
     * Advances one operation by its stage.
     *
     * @param int $id Op id in the pool
     * @param AuthCodeOperation $operation Operation to advance
     * @param float $nowMs Current time in milliseconds
     * @throws AsyncHttpException When a stage request cannot start, times out, or answers malformed
     * @throws SocketException When an underlying socket operation fails
     * @throws HilosException When the channel configuration or probe response is refused
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
     * held back by the concurrency ceiling simply waits for a free slot, and the queue drains
     * by facts rather than by a clock: every operation holding a slot ends within the
     * ceilings of its own requests (HIL-1044).
     *
     * @param int $id Op id in the pool
     * @param AuthCodeOperation $operation Operation being probed
     * @param float $nowMs Current time in milliseconds
     * @throws AsyncHttpException When the probe cannot start, times out, or answers malformed
     * @throws SocketException When an underlying socket operation fails
     * @throws HilosException When the channel configuration or probe response is refused
     */
    private function advanceProbe(int $id, AuthCodeOperation $operation, float $nowMs): void
    {
        if ($operation->client === null) {
            $request = $operation->channel->probeRequest($operation->request->identifier);
            if ($request === null) {
                $reaches = $operation->channel->reaches($operation->request->identifier);
                $probe = $reaches ? CodeChannelProbe::reachable() : CodeChannelProbe::unreachable();
                $this->settleProbe($id, $operation, $probe);

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

        $this->settleProbe($id, $operation, $operation->channel->readProbe($response));
    }

    /**
     * A reachable probe hands the mint to the users library and leaves the pool.
     * An unreachable channel leaves neither a hold nor a code (HIL-1411).
     *
     * @param int $id Operation id in the pool
     * @param AuthCodeOperation $operation Operation whose probe settled
     * @param CodeChannelProbe $probe Reachability and the delivery handle
     * @throws InvalidArgumentException When reporting the failed handoff fails
     */
    private function settleProbe(int $id, AuthCodeOperation $operation, CodeChannelProbe $probe): void
    {
        if (!$probe->reachable) {
            $this->finish($id, $operation, HilosCodeSendAttempt::REASON_CHANNEL_UNAVAILABLE);

            return;
        }

        try {
            $this->sendToAgent(
                HilosSignalConstants::HILOS_AUTH_CODE_MINT,
                new AuthCodeMintSignalData($operation->request, $probe->token),
            );
        } catch (InvalidArgumentException $failure) {
            $this->fail($id, $operation, 'mint handoff failed: ' . $failure->getMessage());

            return;
        }

        $operation->closeClient();
        unset($this->operations[$id]);
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
            $this->finish(
                $id,
                $operation,
                HilosCodeSendAttempt::REASON_SEND_FAILED,
                $operation->resendAt,
                $send->detail,
            );

            return;
        }

        $this->finish($id, $operation, HilosCodeSendAttempt::REASON_CODE_SENT, $operation->resendAt);
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
                // Reported only once the attempt really opens: over the concurrency ceiling
                // this method returns and is called again next tick, and the code is still
                // exactly where the line already says it is - in the queue (HIL-826).
                $this->reportCodeSendStep($operation->request, HilosCodeSendAttempt::STATE_SENDING, null);
            }

            return;
        }

        $this->reportCodeSendStep($operation->request, HilosCodeSendAttempt::STATE_SENDING, null);

        try {
            $operation->channel->handoff($operation->request->identifier, $operation->request->type, $code);
        } catch (Throwable $e) {
            $this->logAgentWarning($this->describe($operation) . ' handoff refused the code: ' . $e->getMessage());
            $this->finish($id, $operation, HilosCodeSendAttempt::REASON_SEND_FAILED, $operation->resendAt);

            return;
        }

        $this->finish($id, $operation, HilosCodeSendAttempt::REASON_CODE_SENT, $operation->resendAt);
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
     * Reports an outcome on the closing step of the line and drops the operation.
     *
     * The wait on a registration is announced here, before the closing step and on exactly
     * the arms the surface opens its code screen on (HIL-486): a code that went out, and a
     * send the cooldown held back because an earlier one already did. The refusals announce
     * nothing - there is no code to come back to. Before the step and from this one sender,
     * so a line the holder replays after the step already finds the wait written (HIL-1044).
     *
     * @param int $id Op id in the pool
     * @param AuthCodeOperation $operation Operation being finished
     * @param string $reason Stable outcome reason (see HilosCodeSendAttempt REASON_*)
     * @param ?int $resendAt Server moment a send is allowed again, in epoch ms, or null when waiting is not the answer
     * @param ?string $detail Channel's own refusal sentence for the progress line, null on every other arm
     */
    private function finish(
        int $id,
        AuthCodeOperation $operation,
        string $reason,
        ?int $resendAt = null,
        ?string $detail = null,
    ): void {
        $operation->closeClient();
        unset($this->operations[$id]);

        if ($reason === HilosCodeSendAttempt::REASON_CODE_SENT
            || $reason === HilosCodeSendAttempt::REASON_RATE_LIMITED) {
            $this->rememberWait($operation);
        }

        $this->reportCodeSendStep(
            $operation->request,
            $this->lineStateFor($reason),
            $detail,
            $reason,
            $resendAt,
            $operation->expiresAt,
        );
    }

    /**
     * Says what one outcome means to the line on the code screen (HIL-826).
     *
     * Two of the arms are not refusals of THIS send and still leave a code on its way,
     * which is why the map is not "sent or failed": a rate-limited request is held back
     * precisely because an earlier code already went out, and the screen the person is looking
     * at is waiting for that one. The other reasons end with nothing travelling, and the line
     * says so and stops promising - the reason itself is the surface's to word, which it
     * already does by dimming the channel or refusing the cap out loud.
     *
     * @param string $reason Stable outcome reason (see HilosCodeSendAttempt REASON_*)
     * @return string One of the four states on {@see HilosCodeSendAttempt}
     */
    private function lineStateFor(string $reason): string
    {
        return match ($reason) {
            HilosCodeSendAttempt::REASON_CODE_SENT,
            HilosCodeSendAttempt::REASON_RATE_LIMITED => HilosCodeSendAttempt::STATE_SENT,
            default => HilosCodeSendAttempt::STATE_FAILED,
        };
    }

    /**
     * Tells the sessions library where this operation's code has got to (HIL-826).
     *
     * The phone twin of the mail queue's reporter, and it reaches the same owner over the same
     * frame: this agent knows the session token but not the line, and the line is not its row
     * to write. What travels is the opaque ticket the request arrived with.
     *
     * It takes a REQUEST rather than an operation: one arm has no operation to speak from,
     * because a channel the registry does not carry is refused at intake - and that refusal
     * leaves a line saying a code is queued when none is.
     *
     * A step that cannot be named is logged and swallowed, for the reason
     * {@see self::rememberWait()} gives about its own row: the code did go out, and turning a
     * delivered code into an error over a frame nobody could name would be the worse lie.
     *
     * The closing step carries the outcome (HIL-1044): the reason, and the two moments the code
     * screen counts down to. The line is the one source the tab reads it from, which is what lets
     * a tab that reconnected mid-send read it at all.
     *
     * @param AuthCodeSendSignalData $request Request whose step is being reported
     * @param string $state One of the four states on {@see HilosCodeSendAttempt}
     * @param ?string $detail Channel's own refusal sentence, null on every other step
     * @param ?string $reason How the send ended (HilosCodeSendAttempt REASON_*), on the closing step alone
     * @param ?int $resendAt Server moment a send is allowed again, in epoch ms, or null
     * @param ?int $expiresAt Server moment the live code dies, in epoch ms, or null
     */
    private function reportCodeSendStep(
        AuthCodeSendSignalData $request,
        string $state,
        ?string $detail,
        ?string $reason = null,
        ?int $resendAt = null,
        ?int $expiresAt = null,
    ): void {
        try {
            $this->sendToAgent(
                HilosSignalConstants::HILOS_CODE_SEND_STEP,
                CodeSendStepSignalData::step($request->progressTicket, $state, $detail, $reason, $resendAt, $expiresAt),
            );
        } catch (InvalidArgumentException $failure) {
            $this->logAgentWarning(
                "code send step over '{$request->channel}' could not be reported: " . $failure->getMessage(),
            );
        }
    }

    /**
     * Tells the session holder the asking session now waits on registering this number (HIL-486,
     * HIL-1044).
     *
     * The durable half of the unfinished-registration memory: the runtime waiter list is a
     * projection of the asking session's row, so a browser that reloads is parked again at its
     * handshake and given back the code screen it was on. Only a REGISTRATION is announced - a
     * code sent to a number that already has an account signs somebody in, and there is no
     * half-finished registration to come back to.
     *
     * The row is the holder's to write, so this agent says it rather than writing it: until
     * HIL-1044 it wrote the column itself, under a claim borrowed from the owner of the session
     * set. A frame that cannot be named is logged and swallowed, for the reason the old write gave
     * about a failure: the code did go out, and turning a delivered code into "send failed" over
     * a memory row would be the worse lie.
     *
     * @param AuthCodeOperation $operation Operation whose code left a session waiting
     */
    private function rememberWait(AuthCodeOperation $operation): void
    {
        if (!$operation->registration) {
            return;
        }

        try {
            $this->sendToAgent(
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_HELD,
                new AuthRegistrationWaitHeldSignalData(
                    $operation->request->sessionToken,
                    $operation->request->identifier,
                ),
            );
        } catch (InvalidArgumentException $failure) {
            $this->logAgentWarning($this->describe($operation) . ' left no wait behind: ' . $failure->getMessage());
        }
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
        $this->finish($id, $operation, HilosCodeSendAttempt::REASON_SEND_FAILED, $operation->resendAt);
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
