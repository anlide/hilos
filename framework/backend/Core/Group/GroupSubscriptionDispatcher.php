<?php

declare(strict_types=1);

namespace Hilos\Core\Group;

use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Group\Config\GroupAddressSource;
use Hilos\Core\Group\DTO\GroupSubscriptionErrorSignalData;
use Hilos\Core\Group\Exception\GroupSubscriptionException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUpdateSubscriptionSignalDTO;
use Hilos\Utils\Logger;
use Throwable;

/**
 * GroupSubscriptionDispatcher - turns one group_subscribe frame into one answer.
 *
 * The worker-side entry of a join. It resolves which registered class answers the name,
 * checks the name was addressed the way that class declares, builds the full name for a
 * group the server addresses itself, and hands the rest to the class
 * ({@see AbstractGroup::onSubscribe()}). Every path out of here ends in a frame: the answer
 * the group built, or a {@see GroupSubscriptionErrorSignalData} saying why there is none.
 *
 * Built per frame rather than kept, because it holds nothing between frames: the group
 * instance belongs to one join, and everything else it reads is the facade.
 */
final class GroupSubscriptionDispatcher
{
    /**
     * @param PageAgentInterface $agent Agent that owns the subscription signals of this group
     */
    public function __construct(
        private readonly PageAgentInterface $agent,
    ) {
    }

    /**
     * Judges one join and answers it.
     *
     * @param WebSocketGroupSubscribeSignalDTO $data Join frame as it arrived from the client
     * @param string $group Group name the client asked for
     * @throws InvalidArgumentException When the answer or the refusal cannot be named
     */
    public function dispatchSubscribe(WebSocketGroupSubscribeSignalDTO $data, string $group): void
    {
        try {
            // Through the router, never through the facade class by name: the registry lives on
            // the PROJECT facade, and a static read here would resolve to the framework's own
            // empty one ({@see SignalRouter::resolveGroupName()}).
            $match = Hilos::$sr?->resolveGroupName($group);
            if ($match === null) {
                throw new GroupSubscriptionException(
                    "No group class serves '{$group}'",
                    HttpConstants::HTTP_NOT_FOUND,
                    GroupErrorCode::NOT_SERVED,
                );
            }

            $groupClass = $match->groupClass;
            $fullName = $this->resolveFullName($groupClass, $group, $match->param, $data->acceptKey);

            (new $groupClass($this->agent))->onSubscribe($fullName, $data->acceptKey, $data->params);
        } catch (GroupSubscriptionException $e) {
            $this->refuse($group, $data->acceptKey, $e->httpCode, $e->errorCode, $e->getMessage());
        } catch (Throwable $e) {
            Logger::error("Unexpected group subscription error: group={$group}, exception={$e->getMessage()}");
            $this->refuse(
                $group,
                $data->acceptKey,
                HttpConstants::HTTP_INTERNAL_ERROR,
                GroupErrorCode::INTERNAL_ERROR,
                'Internal error during group subscription',
            );
        }
    }

    /**
     * Judges a change of params on a membership a connection already holds.
     *
     * The gate the page twin has on its own update ({@see PageSignalRouter::dispatchPageUpdateSubscription()}):
     * a connection the group would refuse at the door must not be able to re-aim a membership
     * it holds. Nothing is answered when it passes - an update is not a join - and the refusal
     * frame goes out when it does not, the way a refused page update answers too.
     *
     * @param WebSocketGroupUpdateSubscriptionSignalDTO $data Update frame as it arrived from the client
     * @param string $group Group name the client asked for
     * @return ?string Full group name the update may be applied to, or null when it was refused
     * @throws InvalidArgumentException When the refusal cannot be named
     */
    public function dispatchUpdateSubscription(
        WebSocketGroupUpdateSubscriptionSignalDTO $data,
        string $group,
    ): ?string {
        $held = Hilos::$sr?->groupSubscriptionName($data->acceptKey, $group);
        try {
            if ($held === null) {
                throw new GroupSubscriptionException(
                    "This connection holds no membership of '{$group}'",
                    HttpConstants::HTTP_NOT_FOUND,
                    GroupErrorCode::NOT_SERVED,
                );
            }

            $match = Hilos::$sr?->resolveGroupName($held);
            if ($match === null) {
                throw new GroupSubscriptionException(
                    "No group class serves '{$held}'",
                    HttpConstants::HTTP_NOT_FOUND,
                    GroupErrorCode::NOT_SERVED,
                );
            }

            $groupClass = $match->groupClass;
            (new $groupClass($this->agent))->onUpdateSubscription($held, $data->acceptKey, $data->params);

            return $held;
        } catch (GroupSubscriptionException $e) {
            $this->refuse($group, $data->acceptKey, $e->httpCode, $e->errorCode, $e->getMessage());
        } catch (Throwable $e) {
            Logger::error("Unexpected group subscription update error: group={$group}, exception={$e->getMessage()}");
            $this->refuse(
                $group,
                $data->acceptKey,
                HttpConstants::HTTP_INTERNAL_ERROR,
                GroupErrorCode::INTERNAL_ERROR,
                'Internal error during group subscription update',
            );
        }

        return null;
    }

    /**
     * Builds the full group name this connection joins, refusing a name addressed wrongly.
     *
     * The address a group declares is what decides who may name it. A group of "my" entity is
     * named by the server out of the identity behind the connection, so a name that arrived
     * WITH a param is refused outright rather than checked against that identity: the client
     * has no business naming that entity at all, and refusing the shape of the frame closes
     * the hole for good rather than one id at a time. A group of a named foreign entity is
     * the mirror case - the param IS the address, and a name without one addresses nothing.
     *
     * @param class-string<AbstractGroup> $groupClass Registered class answering the name
     * @param string $group Group name the client asked for
     * @param ?string $param Param the name carried after the colon, or null when it carried none
     * @param string $acceptKey WebSocket accept key of the joining connection
     * @return string Full group name the connection is let into
     * @throws GroupSubscriptionException When the name is addressed the wrong way, or nobody is behind the connection
     */
    private function resolveFullName(string $groupClass, string $group, ?string $param, string $acceptKey): string
    {
        $declared = $groupClass::GROUP;

        return match ($groupClass::ADDRESS) {
            GroupAddressSource::SINGLETON => $param === null
                ? $declared
                : throw $this->addressMismatch($declared, $group),
            GroupAddressSource::PARAM => $param === null || $param === ''
                ? throw $this->addressMismatch($declared, $group)
                : $group,
            GroupAddressSource::SESSION_USER => $param === null
                ? $declared . ':' . $this->requireUserId($acceptKey, $declared)
                : throw $this->addressMismatch($declared, $group),
            // No group declares the session address yet, and what identifies a session inside a
            // group name is HIL-111's question rather than one to answer in passing here. A
            // registration that declares it is refused at topology validation, so this arm is
            // reached only where that validation was skipped - and it refuses rather than
            // inventing a name a later leaf would have to keep.
            GroupAddressSource::SESSION => throw new GroupSubscriptionException(
                "Group '{$declared}' is addressed by session, which no node serves yet (HIL-111)",
                HttpConstants::HTTP_NOT_FOUND,
                GroupErrorCode::NOT_SERVED,
            ),
        };
    }

    /**
     * Builds the refusal of a name addressed the wrong way.
     *
     * @param string $declared Name the group class declares, without a param
     * @param string $group Group name the client asked for
     * @return GroupSubscriptionException Refusal carrying the address-mismatch code
     */
    private function addressMismatch(string $declared, string $group): GroupSubscriptionException
    {
        return new GroupSubscriptionException(
            "Group '{$declared}' does not answer to the name '{$group}'",
            HttpConstants::HTTP_BAD_REQUEST,
            GroupErrorCode::ADDRESS_MISMATCH,
        );
    }

    /**
     * Reads the durable user behind the connection, or refuses the join.
     *
     * Through the seam the access guards are judged with
     * ({@see BrowserContext::connectionIdentity}), and an answer that has not arrived yet is
     * refused like an absent one: a group join is not parked until identification the way a
     * page subscribe is, so "not yet known" has nowhere to wait here. The client holds its
     * join back until the handshake has answered and retries on its next connect.
     *
     * @param string $acceptKey WebSocket accept key of the joining connection
     * @param string $declared Name the group class declares, without a param
     * @return int Durable user id behind the connection
     * @throws GroupSubscriptionException When no settled user is behind the connection
     */
    private function requireUserId(string $acceptKey, string $declared): int
    {
        $userId = Hilos::$browser?->connectionIdentity($acceptKey)->userId;
        if ($userId === null) {
            throw new GroupSubscriptionException(
                "Group '{$declared}' belongs to the user behind the connection, and none is known",
                HttpConstants::HTTP_UNAUTHORIZED,
                GroupErrorCode::UNAUTHENTICATED,
            );
        }

        return $userId;
    }

    /**
     * Answers a join that will not happen.
     *
     * The name echoed back is the one the CLIENT sent: a refused join never reached the full
     * name the server would have built, and what the client is waiting on is what it asked for.
     *
     * @param string $group Group name the client asked for
     * @param string $acceptKey WebSocket accept key of the joining connection
     * @param int $httpCode HTTP status code for the refusal
     * @param string $errorCode Machine-readable refusal code
     * @param string $message Human-readable refusal message
     * @throws InvalidArgumentException When the refusal signal cannot be named
     */
    private function refuse(string $group, string $acceptKey, int $httpCode, string $errorCode, string $message): void
    {
        Logger::info("Group subscription to '{$group}' refused for '{$acceptKey}': {$errorCode} - {$message}");

        Hilos::$sr?->queueSignal(
            signalSource: $this->agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName(SignalConstants::SUBSCRIPTION_GROUP_ERROR),
            signalData: new WebSocketSignalData(
                data: new GroupSubscriptionErrorSignalData(
                    group: $group,
                    httpCode: $httpCode,
                    errorCode: $errorCode,
                    message: $message,
                ),
                targetAcceptKey: $acceptKey,
            ),
        );
    }
}
