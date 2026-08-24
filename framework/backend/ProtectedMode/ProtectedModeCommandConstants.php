<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\ProtectedModeOperatorTrait;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\ProtectedModeSnapshotSource;
use Hilos\Notification\NotificationCommandConstants;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * ProtectedModeCommandConstants - the wire vocabulary of the protected-mode test commands.
 *
 * The CLI side builds the request payload and reads the reply, the agent side and the
 * master side write it, so every half names its keys from here and they cannot drift
 * apart - the arrangement {@see NotificationCommandConstants} established.
 *
 * Two vocabularies live here because the three commands share one subsystem: the drive
 * pair ({@see CliCommands::PROTECTED_MODE_TEST_ENTER} / {@see CliCommands::PROTECTED_MODE_TEST_LEAVE})
 * speaks the request/reply fields, and {@see CliCommands::PROTECTED_MODE_TEST_INSPECT}
 * speaks the snapshot fields the master fills in {@see DaemonManager::protectedModeSnapshot()}.
 *
 * The snapshot names the {@see ProtectedModeRuntime} row's fields with the row's own
 * spelling, so an assertion reads the same word in the JSON and in the state class -
 * with one deliberate omission. {@see ProtectedModeRuntime::$initiatorAcceptKey} is NOT
 * published: that key is the pass through the lockdown
 * ({@see ProtectedModeRuntime::locksOut()}), and the command socket authenticates
 * nobody, so putting it in a reply would hand every reader of the port the one
 * credential the freeze is built to withhold. Nothing needs it - a test drives the
 * mode through the agent and asserts on the phase.
 */
final class ProtectedModeCommandConstants
{
    /**
     * @var string Request key: operation name the freeze is entered for
     *
     * The accept key the initiator may keep working through has no key of its own here:
     * the drive commands pass it as {@see CommandConstants::FIELD_ACCEPT_KEY}, the same
     * field `test:connection:drop` already puts on this channel.
     */
    public const string FIELD_OPERATION = 'operation';

    /**
     * @var string Request key: session cookie of the browser the freeze is entered on behalf of
     *
     * The browser half of the initiator identity (HIL-655), and the only handle a test has on
     * it: a live restore resolves the session token from the connection roster by the accept
     * key it was asked through, and a browser driving Playwright never learns its own accept
     * key - the welcome frame does not carry one - while the cookie is right there in its jar.
     * Named here rather than derived, so the agent hashes exactly what the master hashed on
     * the 101 and the two sides cannot disagree about which browser asked.
     *
     * Absent means the same thing it has always meant: a freeze nothing with a browser
     * started, which locks out every connection. The value is a session token, so it goes no
     * further than the process that hashes it.
     */
    public const string FIELD_SESSION_TOKEN = 'sessionToken';

    /** @var string Reply key: phase the agent observed when it answered */
    public const string FIELD_PHASE = 'phase';

    /**
     * @var string Reply key: the clear pass minted for the verification window
     *
     * The only way that value ever leaves the process that minted it, which is why this reply
     * travels no further than the command socket the operator opened: the row keeps only the hash
     * ({@see ProtectedModeRuntime::$passHashes}), so nothing in the system can hand the pass back
     * a second time. Where it does become observable is on its way in - a verifier presents it as
     * a query parameter on the socket url, in front of whatever logs request lines
     * ({@see ProtectedModeOperatorTrait}).
     */
    public const string FIELD_PASS = 'pass';

    /** @var string Snapshot key: whether this node has the protected-mode runtime row mounted */
    public const string FIELD_RT_MOUNTED = 'rtMounted';

    /** @var string Snapshot key: agent type of the initiator, or null outside a freeze */
    public const string FIELD_INITIATOR_AGENT_TYPE = 'initiatorAgentType';

    /** @var string Snapshot key: agent index of the initiator, or null for a singleton initiator */
    public const string FIELD_INITIATOR_AGENT_INDEX = 'initiatorAgentIndex';

    /** @var string Snapshot key: node id hosting the initiator, or null off-cluster */
    public const string FIELD_INITIATOR_NODE_ID = 'initiatorNodeId';

    /** @var string Snapshot key: epoch seconds the freeze began activating at, or null */
    public const string FIELD_STARTED_AT = 'startedAt';

    /** @var string Snapshot key: epoch seconds the freeze reached active at, or null */
    public const string FIELD_ACTIVATED_AT = 'activatedAt';

    /**
     * @var string Snapshot key: epoch seconds of the last progress mark behind the freeze, or null
     *
     * Published where the pass hashes deliberately are not: the mark is a timestamp and opens
     * nothing, while what a reader learns from it - whether the operation behind the freeze is
     * still moving - is exactly what the inspector exists to answer.
     */
    public const string FIELD_PROGRESS_AT = 'progressAt';

    /** @var string Snapshot key: agent ids this node stopped for the freeze, empty outside one */
    public const string FIELD_STOPPED_AGENTS = 'stoppedAgents';

    /**
     * @var string Snapshot key: whether the freeze currently refuses agent starts
     *
     * Reported rather than left to be derived from the phase, because it is the master's
     * own answer: the gate ({@see ProtectedModeSnapshotSource}) is what a test asserting
     * "the freeze really took hold on this node" is asking about, and a node that never
     * mounted the row answers false here while its phase reads inactive for a different
     * reason.
     */
    public const string FIELD_AGENT_START_GATE_CLOSED = 'agentStartGateClosed';

    /**
     * @var string Snapshot key: how many passes the verification window has outstanding
     *
     * A count and never a hash, for the same reason {@see FIELD_INITIATOR_AGENT_TYPE}'s
     * neighbour {@see ProtectedModeRuntime::$initiatorAcceptKey} is omitted entirely: this
     * reply goes to an unauthenticated port and, in a test run, into CI output that keeps it
     * forever. How many verifiers may come in is all an assertion ever needs to know.
     */
    public const string FIELD_PASS_COUNT = 'passCount';
}
