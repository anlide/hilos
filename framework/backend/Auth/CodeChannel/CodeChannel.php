<?php

declare(strict_types=1);

namespace Hilos\Auth\CodeChannel;

use Hilos\API\DTO\AsyncHttpRequest;
use Hilos\API\DTO\AsyncHttpResponse;
use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Verification\VerificationType;
use Hilos\HilosException;

/**
 * CodeChannel - the descriptor a one-time-code delivery channel implements (HIL-492).
 *
 * Delivery of a login code stopped being "SMS, and one day maybe something else":
 * a channel is a descriptor a project registers in its {@see CodeChannelRegistry},
 * and adding one is a class plus a registry line - never an edit to the auth surface
 * or to the page that starts the flow. The frontend half of this is
 * `CodeChannelDescriptor` in `@hilos/core`; the two agree on {@see name()},
 * {@see label()}, {@see identifierKinds()} and {@see isPrimary()}, and nothing else
 * about a channel crosses to the browser.
 *
 * A channel is a TRANSPORT and not a code authority: {@see VerificationService} mints
 * the code, decides whether the send gate allows it, and verifies what comes back.
 * The channel is handed a finished code and asked to move it. That division is what
 * lets a channel be swapped without touching the security of the flow.
 *
 * Two steps, in this order, both driven by the code agent off the master process:
 *  - PROBE ({@see probeRequest()} / {@see readProbe()}) - can this identifier be
 *    reached at all. A channel that knows the answer without the network returns null
 *    from {@see probeRequest()} and is asked {@see reaches()} instead. A failed probe
 *    mints nothing, so an unreachable channel costs the person neither a challenge
 *    row nor a cooldown.
 *  - SEND ({@see sendRequest()} / {@see readSend()}), or {@see handoff()} for a
 *    channel with no HTTP call of its own.
 *
 * The HTTP methods hand back descriptors and read responses; they never open a
 * socket. The sockets belong to the agent, which is what keeps a channel testable
 * without a network and keeps blocking I/O out of the process that owns them.
 */
abstract class CodeChannel
{
    /**
     * The channel name - its registry key, the value stored in the challenge's
     * `channel` column, and the key the browser sends back when a person picks it.
     *
     * @return string Stable channel name (e.g. sms, telegram)
     */
    abstract public function name(): string;

    /**
     * The channel's human label, shown beside its icon and in "Sent to … via …".
     *
     * The framework default title-cases {@see name()}; a channel whose label is not
     * its name capitalized (SMS) overrides it.
     *
     * @return string Human channel label (e.g. Telegram)
     */
    public function label(): string
    {
        return ucfirst($this->name());
    }

    /**
     * The identifier kinds this channel can serve at all.
     *
     * A static property of the channel, not of the person in front of it: every
     * channel that exists today addresses a phone number, so that is the default.
     * A future channel that delivers to an email address narrows it here, and the
     * surface stops offering it the moment a phone is typed - which is the whole
     * mechanism, since the page never learns what any particular channel is.
     *
     * @return list<string> Identifier kinds (see IdentifierDetection KIND_*)
     */
    public function identifierKinds(): array
    {
        return [IdentifierDetection::KIND_PHONE];
    }

    /**
     * Whether this channel is the default one the primary button sends over.
     *
     * At most one channel should answer true; when several do, the first applicable
     * one in registry order wins, and when none does the surface promotes the first
     * applicable channel anyway - so a registry can never end up with no way to send.
     *
     * @return bool True for the default channel, false for the rest
     */
    public function isPrimary(): bool
    {
        return false;
    }

    /**
     * Whether this channel delivers codes for a verification type.
     *
     * The guard that keeps a channel out of a flow it was never meant for: the
     * profile add-phone flow stays a plain SMS, and an email confirmation is not a
     * channel's business at all. Asked before anything is minted.
     *
     * @param string $type Verification type (see VerificationType)
     * @return bool True when this channel delivers codes of that type
     */
    abstract public function supportsType(string $type): bool;

    /**
     * Builds the reachability probe request, or null when no network call is needed.
     *
     * Null is the honest answer for a channel whose reachability is decided by the
     * identifier alone (an SMS gateway takes any well-formed number); the agent then
     * asks {@see reaches()} and never opens a socket.
     *
     * @param string $identifier Normalized identifier the code would go to
     * @return ?AsyncHttpRequest Probe request for the agent to replay, or null when none is needed
     * @throws HilosException Whatever reading the channel's own transport configuration raises;
     *   the agent reports it as a refused send rather than letting it out of the tick loop
     */
    public function probeRequest(string $identifier): ?AsyncHttpRequest
    {
        return null;
    }

    /**
     * Reads the probe response the agent collected.
     *
     * Only called for a channel that returned a request from {@see probeRequest()};
     * the default refuses loudly, because reaching it means a channel asked for a
     * network call it cannot interpret.
     *
     * @param AsyncHttpResponse $response Completed probe response
     * @return CodeChannelProbe Whether the target is reachable, and the handle the send quotes back
     * @throws LogicException When a channel that issues no probe request is asked to read one
     * @throws HilosException Whatever reading the channel's own transport configuration raises
     */
    public function readProbe(AsyncHttpResponse $response): CodeChannelProbe
    {
        throw new LogicException(static::class . ' issues no probe request and cannot read a probe response');
    }

    /**
     * Whether this channel reaches an identifier, decided without the network.
     *
     * The counterpart of {@see probeRequest()} returning null. The default accepts
     * every identifier; a channel that can rule one out on its shape alone (a number
     * that is not E.164) narrows it here.
     *
     * @param string $identifier Normalized identifier the code would go to
     * @return bool True when the channel can deliver to this identifier
     */
    public function reaches(string $identifier): bool
    {
        return true;
    }

    /**
     * Builds the send request carrying the code, or null for a channel that hands off.
     *
     * Null means this channel does not send over HTTP itself; the agent then calls
     * {@see handoff()}, which gives the code to another subsystem that owns the
     * transport.
     *
     * @param string $identifier Normalized identifier the code goes to
     * @param string $code Plaintext code to deliver
     * @param ?string $probeToken Handle the probe returned, or null when it returned none
     * @return ?AsyncHttpRequest Send request for the agent to replay, or null when this channel hands off
     * @throws HilosException Whatever reading the channel's own transport configuration raises
     */
    public function sendRequest(string $identifier, string $code, ?string $probeToken): ?AsyncHttpRequest
    {
        return null;
    }

    /**
     * Reads the send response the agent collected.
     *
     * Only called for a channel that returned a request from {@see sendRequest()};
     * the default refuses loudly, for the same reason {@see readProbe()} does.
     *
     * @param AsyncHttpResponse $response Completed send response
     * @return CodeChannelSend Whether the transport took the code
     * @throws LogicException When a channel that issues no send request is asked to read one
     * @throws HilosException Whatever reading the channel's own transport configuration raises
     */
    public function readSend(AsyncHttpResponse $response): CodeChannelSend
    {
        throw new LogicException(static::class . ' issues no send request and cannot read a send response');
    }

    /**
     * The per-request network timeout this channel's transport needs, in milliseconds.
     *
     * Null means "the agent's default", which is right for every channel whose
     * provider has no opinion. A channel whose provider is configured with its own
     * timeout answers it here, because the agent owns the sockets but knows nothing
     * about what is at the other end of them - and a knob a project can set has to
     * reach the request it names, or it is documentation for behavior that does not
     * exist.
     *
     * @return ?float Timeout in milliseconds, or null to use the agent's default
     * @throws HilosException Whatever reading the channel's own transport configuration raises
     */
    public function timeoutMs(): ?float
    {
        return null;
    }

    /**
     * Hands the code to a subsystem that owns the transport, for a channel with no HTTP call.
     *
     * The door for a channel that is a NAME over delivery someone else already does:
     * SMS codes ride the SMS subsystem's own sharded pool, and duplicating that here
     * would mean a second SMS sender with its own retries and its own idea of a
     * segment. The default refuses, so a channel that neither sends over HTTP nor
     * hands off fails loudly instead of silently delivering nothing.
     *
     * The handoff is queued, not awaited: the subsystem it goes to owns the outcome,
     * so a channel that hands off reports the code sent once the handoff is accepted.
     *
     * @param string $identifier Normalized identifier the code goes to
     * @param string $type Verification type the code was minted for (see VerificationType)
     * @param string $code Plaintext code to deliver
     * @throws LogicException When a channel drives neither an HTTP send nor a handoff
     * @throws HilosException Whatever the subsystem the handoff goes to raises; the agent
     *   reports it as a refused send rather than letting it out of the tick loop
     */
    public function handoff(string $identifier, string $type, string $code): void
    {
        throw new LogicException(static::class . ' drives neither an HTTP send nor a handoff');
    }
}
