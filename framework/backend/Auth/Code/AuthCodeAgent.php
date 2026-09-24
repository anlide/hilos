<?php

declare(strict_types=1);

namespace Hilos\Auth\Code;

use Hilos\API\AsyncHttpClient;
use Hilos\API\DTO\AsyncHttpRequest;
use Hilos\API\DTO\AsyncHttpResponse;
use Hilos\API\Exception\AsyncHttpException;
use Hilos\Auth\Code\DTO\AuthCodeSendSignalData;
use Hilos\Auth\Code\DTO\CodeSendStepSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationWaitHeldSignalData;
use Hilos\Auth\CodeChannel\CodeChannel;
use Hilos\Auth\CodeChannel\CodeChannelProbe;
use Hilos\Auth\MagicLink\MagicLinkService;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Verification\VerificationIssuedCode;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Verification\VerificationType;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
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
 * It is CONCRETE, where the OAuth agent is abstract, and the difference says what this
 * agent does not do: nothing here touches a project table. It mints through
 * {@see VerificationService}, delivers through a {@see CodeChannel} the project
 * registered, and signals an accept key. Whoever the code belongs to is decided later,
 * on the confirm action, by the project.
 *
 * Two framework auth tables it does write, and both for the same reason - only this
 * process knows what became of the send (HIL-486). A number nobody owns is HELD before
 * the mint, so a second person cannot register it while the code travels; and a code
 * that really went out is REMEMBERED against the asking session, so a reload lands back
 * on the code screen instead of an empty form. Neither can be done by the page action:
 * it returned the moment it handed the request over, long before there was a probe
 * verdict, a code, or a delivery.
 *
 * A monopolistic singleton ({@see AuthCodeAgentDaemon}). Ops live only in its memory,
 * so a restart loses in-flight requests - correct for this operation: a code request is
 * seconds long and the person is watching a spinner, so the honest recovery is them
 * pressing the button again, not a resurrected send to a screen that has moved on.
 */
class AuthCodeAgent extends AbstractAgent
{
    /**
     * The three tables a send writes, all of them somebody else's.
     *
     * The class comment above names why the writes are here and not in the page action: only
     * this process learns what became of the send. The claims are the same statement said to
     * the guard, which since HIL-716 asks on every table rather than on the four eager ones.
     *
     * TODO(HIL-630): borrowed claim - the users library owns the challenge and the hold; this
     * agent mints one and takes the other because a code that was never delivered must cost
     * neither.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [
        HilosDbContext::verifications => TruthSourceOperation::BY_KIND,
        HilosDbContext::registrationReservations => TruthSourceOperation::BY_KIND,
    ];

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
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the signal name is not the code-request handoff
     * @throws InvalidArgumentException When the outcome signal cannot be named or queued
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
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
            // The line opened when the person picked the channel, and nothing is going to
            // travel over it - so it stops promising here rather than sitting on "queued"
            // until the next send replaces it (HIL-826).
            $this->reportCodeSendStep(
                $request,
                HilosCodeSendAttempt::STATE_FAILED,
                null,
                HilosCodeSendAttempt::REASON_CHANNEL_UNAVAILABLE,
            );

            return;
        }

        $this->operations[$this->nextId++] = new AuthCodeOperation(
            $request,
            $channel,
            AuthCodeOperation::STAGE_PROBE,
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

    /**
     * Advances one operation by its stage.
     *
     * @param int $id Op id in the pool
     * @param AuthCodeOperation $operation Operation to advance
     * @param float $nowMs Current time in milliseconds
     * @throws AsyncHttpException When a stage request cannot start, times out, or answers malformed
     * @throws SocketException When an underlying socket operation fails
     * @throws InvalidArgumentException When an outcome signal cannot be named or queued
     * @throws EmptyValueException When the identifier the request names is empty
     * @throws DatabaseException When an identity, reservation or verification query fails
     * @throws LogicException When an object collection the hold or the mint needs is unavailable
     * @throws EnvException When a reservation or verification env key is missing, outside the
     *   catalog, or of the wrong type
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
     * @throws InvalidArgumentException When an outcome signal cannot be named or queued
     * @throws EmptyValueException When the identifier the request names is empty
     * @throws DatabaseException When an identity, reservation or verification query fails
     * @throws LogicException When an object collection the hold or the mint needs is unavailable
     * @throws EnvException When a reservation or verification env key is missing, outside the
     *   catalog, or of the wrong type
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
     * The hold on the identifier keeps that company for the same reason - an unreachable
     * channel reserves nothing either (HIL-486).
     *
     * @param int $id Op id in the pool
     * @param AuthCodeOperation $operation Operation whose probe settled
     * @param CodeChannelProbe $probe Probe result carrying reachability and the send's handle
     * @param float $nowMs Current time in milliseconds
     * @throws AsyncHttpException When the send cannot start
     * @throws SocketException When an underlying socket operation fails
     * @throws InvalidArgumentException When an outcome signal cannot be named or queued
     * @throws EmptyValueException When the identifier the request names is empty
     * @throws DatabaseException When an identity, reservation or verification query fails
     * @throws LogicException When an object collection the hold or the mint needs is unavailable
     * @throws EnvException When a reservation or verification env key is missing, outside the
     *   catalog, or of the wrong type
     */
    private function settleProbe(int $id, AuthCodeOperation $operation, CodeChannelProbe $probe, float $nowMs): void
    {
        $operation->closeClient();

        if (!$probe->reachable) {
            $this->finish($id, $operation, HilosCodeSendAttempt::REASON_CHANNEL_UNAVAILABLE);

            return;
        }

        $this->holdIdentifier($operation);

        $issued = $this->issue($operation);
        if ($issued->code === null) {
            $this->reportRefusedIssue($id, $operation, $issued);

            return;
        }

        $operation->probeToken = $probe->token;
        $operation->code = $issued->code;
        $operation->resendAt = $issued->outcome->resendAt();
        $operation->expiresAt = $this->liveExpiresAt($operation);
        $operation->stage = AuthCodeOperation::STAGE_SEND;

        $this->advanceSend($id, $operation, $nowMs);
    }

    /**
     * Holds a free identifier for the registration this code is about to start (HIL-486).
     *
     * The shape {@see MagicLinkService::send()} established, one layer down: an
     * identifier nobody owns is reserved before its code is minted, so a second person
     * cannot take it while this one reads the message, and the confirm has something to
     * check the code against. An identifier that already resolves to an account holds
     * nothing - there is nothing left to reserve - and the same lookup answers the
     * other question this operation needs later: whether a registration is what is
     * waiting on this code.
     *
     * Only a LOGIN code reserves. A code that adds a number to an account somebody is
     * already signed into ({@see VerificationType::SMS_ADD}) proves possession and
     * starts no registration, so a hold there would strand a number nobody is
     * registering behind a wait nobody can finish.
     *
     * The hold is the ASKING BROWSER's, symmetric with the address (HIL-608): a second
     * session asking for a code on the same number gets a hold of its own, its code is
     * refused by the send gate as a cooldown, and the number goes to whoever confirms
     * first. An asymmetry here would mean the capture is closed for mail and open for
     * a number.
     *
     * @param AuthCodeOperation $operation Operation whose identifier is being held
     * @throws EmptyValueException When the identifier the request names is empty
     * @throws DatabaseException When an identity or reservation query fails
     * @throws LogicException When the identities or reservations object collection is unavailable
     * @throws EnvException When the reservation TTL key is missing, outside the catalog, or not an int
     */
    private function holdIdentifier(AuthCodeOperation $operation): void
    {
        $identifier = $operation->request->identifier;
        if ($operation->request->type !== VerificationType::SMS_LOGIN
            || Hilos::$db?->identities->findByIdentity(IdentityType::SMS, $identifier) !== null) {
            return;
        }

        $operation->registration = true;
        new RegistrationReservationService()
            ->hold(IdentityType::SMS, $operation->request->sessionToken, $identifier);
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
            $this->finish($id, $operation, HilosCodeSendAttempt::REASON_CAP_REACHED);

            return;
        }

        // A cooldown refusal still opens the code screen - the code it held back is the
        // one already on its way - so that screen is owed the life of THAT code, which
        // is shorter than a fresh one by however long ago it went out (HIL-486).
        $operation->expiresAt = $this->liveExpiresAt($operation);

        $this->finish(
            $id,
            $operation,
            HilosCodeSendAttempt::REASON_RATE_LIMITED,
            $issued->outcome->resendAt(),
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
     * When the code this operation left live stops being good (HIL-486).
     *
     * Read from the challenge rather than computed from the mint, because the arm
     * that needs it most did not mint anything: a send the cooldown held back leaves
     * an EARLIER code in play, and the screen that is about to ask for it counts down
     * that one's remaining life.
     *
     * @param AuthCodeOperation $operation Operation whose target is asked about
     * @return ?int Epoch milliseconds the live code expires at, or null when none is live
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     * @throws EnvException When the attempt-ceiling env key is missing, outside the catalog,
     *   or not an int
     */
    private function liveExpiresAt(AuthCodeOperation $operation): ?int
    {
        return new VerificationService()->activeExpiresAt(
            $operation->request->type,
            $operation->request->identifier,
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
     * Two of the five arms are not refusals of THIS send and still leave a code on its way,
     * which is why the map is not "sent or failed": a rate-limited request is held back
     * precisely because an earlier code already went out, and the screen the person is looking
     * at is waiting for that one. The other three end with nothing travelling, and the line
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
