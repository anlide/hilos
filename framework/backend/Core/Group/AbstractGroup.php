<?php

declare(strict_types=1);

namespace Hilos\Core\Group;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Group\Config\GroupAddressSource;
use Hilos\Core\Group\DTO\GroupJoinSignalData;
use Hilos\Core\Group\DTO\GroupResponseSignalData;
use Hilos\Core\Group\Exception\GroupSubscriptionException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;

/**
 * Base class for WebSocket group subscription ownership declarations.
 *
 * The group's twin of {@see AbstractPage}: concrete groups declare their name, the kind of
 * address they answer to, and the agent type that owns their subscription signals, then
 * override two hooks - who may join, and what a join is answered with. Register group
 * classes in the project Hilos facade GROUPS registry.
 *
 * A join has exactly three outcomes - content, no content, a refusal - and the frame is sent
 * by the framework in all three ({@see self::onSubscribe()} is final). A hook never sends
 * anything itself, which is what makes silence structurally impossible rather than a matter
 * of every group author remembering to answer.
 *
 * Membership is recorded only after admission passes, and the content is built only after
 * membership is recorded. That order is deliberate: a default of DENY means nothing if the
 * connection is already on the fan-out list while the verdict is being taken, and an event
 * that reaches the group between the two lands in the durable store the content is read
 * from - a duplicate the client dedupes, rather than a loss no one can see.
 */
abstract class AbstractGroup
{
    /** Group name WITHOUT a param, overridden by concrete groups; the param travels after a colon. */
    public const string GROUP = '';

    /** Agent type that owns subscription signals for this group. */
    public const string SUBSCRIPTION_AGENT_TYPE = '';

    /** What names the entity this group belongs to, and therefore who may name it. */
    public const GroupAddressSource ADDRESS = GroupAddressSource::SINGLETON;

    /** Agent instance that owns this group handler. */
    protected PageAgentInterface $agent;

    /**
     * Full group name this instance was resolved for, once a join has reached it.
     *
     * Empty until {@see self::onSubscribe()} runs, because a group instance is built for one
     * join and the name is what that join resolved to. It is the verdict of the address rules
     * ({@see GroupAddressSource}) written down, which is why the hooks read the entity they
     * answer for off it rather than asking the identity seam a second time: two reads of a
     * live seam can disagree between the admission and the answer, and the name cannot.
     */
    private string $fullGroupName = '';

    /**
     * Creates a group bound to its owning agent.
     *
     * @param PageAgentInterface $agent Agent instance
     */
    public function __construct(PageAgentInterface $agent)
    {
        $this->agent = $agent;
    }

    /**
     * Admits a connection into this group and answers it with one frame.
     *
     * Final, and the only path a join takes: admission, then membership, then content, then
     * the answer. The full group name is passed in rather than read off the class, because
     * for a group the server addresses itself ({@see GroupAddressSource::SESSION_USER}) the
     * name the client sent and the name it joins are different, and the client opens its
     * group scope under the latter.
     *
     * @param string $group Full group name the framework resolved for this connection
     * @param string $acceptKey WebSocket accept key of the joining connection
     * @param array<string, string> $params Subscription params carried by the join frame
     * @throws GroupSubscriptionException When this group refuses the connection
     * @throws InvalidArgumentException When the response signal cannot be named
     * @throws HilosException Whatever else this group's admission or content build raises
     */
    final public function onSubscribe(string $group, string $acceptKey, array $params): void
    {
        $this->fullGroupName = $group;
        $this->assertSubscribable($acceptKey, $params);
        $this->join($group, $acceptKey, $params);
        $this->sendToUser(
            SignalTypeConstants::GROUP_RESPONSE,
            $acceptKey,
            new GroupResponseSignalData($group, $this->buildGroupPayload($params)),
        );
    }

    /**
     * Judges a change of params on a membership this connection already holds.
     *
     * Final, and answers with no frame: an update is not a join, and the client is not
     * waiting on anything - the same shape as {@see AbstractPage::onUpdateSubscription()},
     * whose default body is empty too. What it does carry over from the join is the
     * admission: without it, a connection that would be refused at the door could re-aim a
     * membership it holds at something the group would not have let it into.
     *
     * @param string $group Full group name the membership is held under
     * @param string $acceptKey WebSocket accept key of the connection
     * @param array<string, string> $params Params the update carries
     * @throws GroupSubscriptionException When this group refuses the connection
     * @throws HilosException Whatever else this group's admission raises
     */
    final public function onUpdateSubscription(string $group, string $acceptKey, array $params): void
    {
        $this->fullGroupName = $group;
        $this->assertSubscribable($acceptKey, $params);
    }

    /**
     * Returns the full group name the join being served resolved to.
     *
     * @return string Full group name, empty outside a join
     */
    final protected function fullGroupName(): string
    {
        return $this->fullGroupName;
    }

    /**
     * Decides whether this connection may join.
     *
     * The default REFUSES, which is the framework's standing answer to a group that has not
     * said otherwise: a group is a fan-out channel, and one that admits by default leaks the
     * moment somebody registers it and forgets this method. Override to admit - a group
     * addressed by "my" entity may admit unconditionally, because the address itself already
     * says the connection is asking about its own.
     *
     * Not a bool: a refusal carries a reason, and the reason is what the client reads off the
     * error frame.
     *
     * @param string $acceptKey WebSocket accept key of the joining connection
     * @param array<string, string> $params Subscription params carried by the join frame
     * @throws GroupSubscriptionException When this group refuses the connection
     * @throws HilosException Whatever else the override's own read of domain state raises
     */
    protected function assertSubscribable(string $acceptKey, array $params): void
    {
        throw new GroupSubscriptionException('Group declares no admission');
    }

    /**
     * Builds what the join is answered with.
     *
     * Default returns null: a group that carries no content is a legitimate outcome, and the
     * frame goes out either way. Override to answer with a snapshot; an override that reads
     * domain state should raise a {@see GroupSubscriptionException} on failure so the client
     * is told rather than left holding an empty answer.
     *
     * @param array<string, string> $params Subscription params carried by the join frame
     * @return ?SignalDataInterface Content of the answer, or null when the group carries none
     * @throws GroupSubscriptionException When the override refuses the join on its own terms
     * @throws HilosException Whatever else the override's read of domain state raises
     */
    protected function buildGroupPayload(array $params): ?SignalDataInterface
    {
        return null;
    }

    /**
     * Records the membership this worker just admitted, here and on the master.
     *
     * Two writes, because there are two registries and each answers a different question: the
     * worker-local mirror is what this worker's own fan-out reads, and the master's is what
     * every fan-out from anywhere reads. The master is told rather than left to infer it from
     * the client frame it forwarded - it knows neither who is behind the socket nor the full
     * name that identity builds, and it is forbidden to read a session to find out.
     *
     * @param string $group Full group name the connection is being let into
     * @param string $acceptKey WebSocket accept key of the joining connection
     * @param array<string, string> $params Subscription params carried by the join frame
     * @throws InvalidArgumentException When the join announcement cannot be named
     */
    private function join(string $group, string $acceptKey, array $params): void
    {
        Hilos::$sr?->subscribeToGroup($group, new WebSocketGroupSubscribeSignalDTO(
            acceptKey: $acceptKey,
            group: $group,
            params: $params,
        ));

        Hilos::$sr?->queueSignal(
            signalSource: $this->agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::GROUP_JOIN),
            signalName: new SignalName(SignalTypeConstants::GROUP_JOIN),
            signalData: new GroupJoinSignalData($group, $acceptKey, $params),
        );
    }

    /**
     * Queues a signal to the joining connection by accept key.
     *
     * Uses the owning agent signal source for routing context without depending on the
     * agent's concrete class, the way {@see AbstractPage::sendToUser()} does.
     *
     * @param string $signalName Signal name
     * @param string $acceptKey Target connection acceptKey
     * @param SignalDataInterface $data Signal payload
     * @throws InvalidArgumentException When the signal name is empty
     */
    private function sendToUser(string $signalName, string $acceptKey, SignalDataInterface $data): void
    {
        Hilos::$sr?->queueSignal(
            signalSource: $this->agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, targetAcceptKey: $acceptKey),
        );
    }
}
