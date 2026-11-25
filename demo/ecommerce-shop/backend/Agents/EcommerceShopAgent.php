<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents;

use Demo\EcommerceShop\Agents\Hilos\SessionsLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\AccountCartSendSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartSendSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferWakeSignalData;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Auth\Session\DTO\SessionRotateSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Group\GroupMembership;
use LogicException as NativeLogicException;
use Hilos\Core\Page\PageAccessReassessment;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\RelayedActionReplyDTO;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;

/**
 * Monopolistic ecommerce-shop worker that owns the main page subscription and the
 * WebSocket lifecycle signals.
 *
 * Session identity is the framework's, and a library's too: the cookie is resolved by
 * {@see SessionsLibraryAgent}, which says what the session is in one frame, and this agent
 * tracks the socket as a runtime connection of that session. A visitor without an account is
 * an anonymous session and stays nameless - the demo sends no guest identity, and the one thing
 * of its own on a session row is the guest's cart, held by {@see GuestCartsLibraryAgent}; an
 * account is created by signing up or minted by {@see CliCommands::ADMIN_CREATE}.
 * The name the home page shows is the session's own, so this agent holds no person rows,
 * and it puts each socket in its cart's group ({@see CartGroup}).
 */
final class EcommerceShopAgent extends AbstractAgent
{
    /** @var array<string, list<TruthSourceOperation>> Who is on the wire */
    public const array OWNS_RT = [EcommerceShopRtContext::connections => TruthSourceOperation::BY_KIND];

    public const array READS_DB = [HilosDbContext::sessions];

    public const string AGENT_TYPE = AgentType::ECOMMERCE_SHOP;

    // The session frame is declared HERE and not in the library, which is what routes it to
    // this agent: a destination is taken from whoever names a signal, and the library naming
    // its own outgoing frame would send it back to itself. The sweep frame is not declared
    // here: the one thing of this demo's own on a session row is the guest's cart, so the guest
    // carts library declares it, and the carts of swept sessions go there.
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_SESSION_STATE => SessionStateSignalData::class,
    ];

    /**
     * Says out loud what the sessions library concluded about one session (HIL-710).
     *
     * The project half of the seam, and the ONE handler behind every ending a session can
     * reach here: a handshake, and the operator's admin:create binding an account to a live
     * browser. It replaces what used to be four hooks of the session-host trait
     * (`onSignalHandshake`, `bindConnectionUser`, `markConnectionAck` and the response signal
     * name), and it can be one handler precisely because the library states the session's
     * whole state rather than the change it made.
     *
     * THE ORDER IS THE MECHANISM: the connection rows are written first, so that everything
     * read against them - the page re-decision above all - sees who the socket belongs to
     * now; the identity follows, stamped by the framework with the server clock; each socket
     * is then put in the group of the cart it holds now. A change of person or replacement
     * of an anonymous session ends the old memberships before the identity is sent. The cart's
     * owner is asked to tell the group the cart, behind every join and the rotation ticket;
     * the action that was waiting is answered last, behind the identity it announces (HIL-622).
     *
     * @param SessionStateSignalData $frame What the session is now, and whom to answer
     * @throws InvalidArgumentException When a signal of the answer cannot be named
     * @throws HilosException On database or runtime failure
     */
    private function applySessionState(SessionStateSignalData $frame): void
    {
        $acceptKeys = Hilos::$rt->connections->acceptKeysForSessionFrame($frame->acceptKeys, $frame->sessionToken);
        if ($acceptKeys === []) {
            return;
        }

        $userId = $frame->userId;
        $sessionId = $frame->sessionId;
        $guestCart = null;
        $cartGroup = null;
        if ($userId !== null) {
            $cartGroup = CartGroup::forUser($userId);
        } elseif ($sessionId !== null) {
            $cartEpoch = Hilos::$db->sessions->activeGuestCartEpoch($sessionId, $frame->sessionToken);
            if ($cartEpoch === null) {
                return;
            }
            $guestCart = new GuestCartSendSignalData($sessionId, $cartEpoch);
            $cartGroup = CartGroup::forSession($guestCart->sessionId, $guestCart->cartEpoch);
        }

        // One identity for the whole frame: every socket it reaches belongs to the one session
        // it is about - the ones it names and the rest of the session's, which only this
        // register knows of yet.
        $identity = $this->handshakeIdentity($frame);

        foreach ($acceptKeys as $acceptKey) {
            $this->settleConnection($acceptKey, $frame);
            $previousCart = Hilos::$sr?->groupSubscriptionName($acceptKey, CartGroup::NAME);
            if ($previousCart !== null && $previousCart !== $cartGroup) {
                GroupMembership::leaveAll($acceptKey);
            }
            $this->sendHandshakeResponse(EcommerceShopSignalConstants::HANDSHAKE_RESPONSE, $acceptKey, $identity, $frame);
            if ($cartGroup !== null) {
                GroupMembership::join($acceptKey, $cartGroup, $this->getAgentSignalSource());
            }
        }

        $this->handOverRotationTicket($frame);

        // Whatever page these browsers had open was answered for whoever they were a moment
        // ago (HIL-652). A bind can ask by user, because the identity has just appeared; a
        // sign-out has to ask by connection, because the identity it would ask under is the
        // one being erased.
        if ($userId !== null) {
            PageAccessReassessment::forUser($userId);
        } else {
            PageAccessReassessment::forConnections($frame->acceptKeys);
        }

        $this->askForCart($userId, $guestCart);
        $this->answerLibraryAction($frame);
    }

    /**
     * Brings one live connection row to the state the frame states.
     *
     * A socket the collection does not hold yet is a HANDSHAKE and nothing else: every other
     * frame names sockets the library read out of this very collection. Each write happens
     * only where it changes something - a row written is a row synced to every reader of it,
     * and a frame that merely restates an unchanged session has no business telling the rest
     * of the node that the connections moved.
     *
     * @param string $acceptKey Accept key of the connection being brought up to date
     * @param SessionStateSignalData $frame What the session is now
     * @throws HilosException On runtime failure
     * @throws InvalidArgumentException When the runtime sync or group leave cannot be named
     */
    private function settleConnection(string $acceptKey, SessionStateSignalData $frame): void
    {
        $connection = Hilos::$rt->connections[$acceptKey] ?? null;
        if ($connection === null) {
            Hilos::$rt->connections->actions->register(
                $acceptKey,
                $frame->userId,
                $frame->sessionToken,
                $frame->sessionId,
            );
        } else {
            $sessionChanged = $connection->sessionId !== $frame->sessionId;
            if ($connection->sessionToken !== $frame->sessionToken || $sessionChanged) {
                Hilos::$rt->connections->actions->repointSessionToken(
                    $acceptKey,
                    $frame->sessionToken,
                    $frame->sessionId,
                );
            }
            if ($connection->userId !== $frame->userId) {
                $connection->actions->bindUser($frame->userId);
            } elseif ($frame->userId === null && $sessionChanged) {
                GroupMembership::leaveAll($acceptKey);
            }
        }
    }

    /**
     * Hands a browser its rotated-cookie ticket after sign-in, sign-out, remote session
     * termination or replacement of a dead cookie (HIL-582, HIL-1126).
     *
     * Sent from here so that it leaves behind the identity above: receiving the ticket
     * immediately reconnects its one holder, so the browser must learn its identity first.
     *
     * @param SessionStateSignalData $frame Session state that may carry a rotation
     * @throws InvalidArgumentException When the rotation signal cannot be named
     */
    private function handOverRotationTicket(SessionStateSignalData $frame): void
    {
        $ticket = $frame->rotationTicket;
        $acceptKey = $frame->initiatorAcceptKey();
        if ($ticket === null || $acceptKey === null) {
            return;
        }

        $this->sendToUser(
            HilosSignalConstants::HILOS_SESSION_ROTATE,
            $acceptKey,
            new SessionRotateSignalData($ticket),
        );
    }

    /**
     * Asks the owner of the cart the session holds now to tell its group the whole cart.
     *
     * The owner sends it, not this agent: the owner is the one writer of the cart's rows, and a
     * snapshot sent from here would not be ordered against the ones it sends after each change.
     * The person's agent answers for a signed-in session, the guest carts library for any
     * other; a frame naming neither a person nor a session row asks nobody.
     *
     * @param ?int $userId Signed-in cart owner
     * @param ?GuestCartSendSignalData $guestCart Guest cart joined by the connections
     * @throws InvalidArgumentException When the request signal cannot be named
     */
    private function askForCart(?int $userId, ?GuestCartSendSignalData $guestCart): void
    {
        if ($userId !== null) {
            $this->sendToAgent(EcommerceShopSignalConstants::ACCOUNT_CART_SEND, new AccountCartSendSignalData($userId));
        } elseif ($guestCart !== null) {
            $this->sendToAgent(EcommerceShopSignalConstants::GUEST_CART_SEND, $guestCart);
        }
    }

    /**
     * Answers the action the frame finished, when somebody is waiting on it.
     *
     * The library deferred its own ack so that the answer would leave from behind the
     * identity it announces (HIL-622), and since the split that identity is sent from here -
     * so the answer is sent from here too.
     *
     * The reply goes on exactly as the library serialized it ({@see RelayedActionReplyDTO}): a
     * sign-in outcome, or the code-send reply of a profile window (HIL-1186), which is not one.
     *
     * @param SessionStateSignalData $frame Session state that may end a tracked action
     * @throws InvalidArgumentException When the reply signal cannot be named
     */
    private function answerLibraryAction(SessionStateSignalData $frame): void
    {
        $action = $frame->action;
        $requestId = $frame->requestId;
        $acceptKey = $frame->initiatorAcceptKey();
        if ($action === null || $requestId === null || $acceptKey === null) {
            return;
        }

        $outcome = $frame->outcome;
        $this->sendActionSuccess(
            $acceptKey,
            $action,
            $requestId,
            $outcome === null ? null : new RelayedActionReplyDTO($outcome),
        );
    }

    /**
     * Routes the session state frame this agent owns.
     *
     * @param AgentSignalData $data Agent signal wrapper with the inner payload to dispatch
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it (unused)
     * @param string $name Agent signal name
     * @throws AgentUnknownSignalException When signal name is not supported by this agent
     * @throws InvalidAgentSignalPayloadException When the payload is not the one its name promises
     * @throws InvalidArgumentException When a signal of the answer cannot be named
     * @throws NativeLogicException When the runtime item factory rejects a connection
     * @throws HilosException On database or runtime failure
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_SESSION_STATE:
                $state = $data->data;
                if (!$state instanceof SessionStateSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, SessionStateSignalData::class, $state);
                }

                $this->applySessionState($state);
                if ($state->sessionId !== null) {
                    $this->sendToAgent(
                        EcommerceShopSignalConstants::GUEST_CART_TRANSFER_WAKE,
                        new GuestCartTransferWakeSignalData($state->sessionId),
                    );
                }

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Unregisters the closed WebSocket connection from runtime presence.
     *
     * @param WebSocketCloseSignalDTO $data Closed WebSocket connection
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException On runtime cleanup failure
     */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        Hilos::$rt->connections[$data->acceptKey]?->actions->unregister();
    }

    /**
     * Nothing to clean up on shutdown: this agent owns no runtime state of its own.
     *
     * It used to empty the connections here, and that was the defect (HIL-664): who is on the
     * wire is the truth of the node holding the sockets, not of the agent, and a stop closes no
     * socket. The rows outlive the agent now, and the sockets that died while it was down are
     * struck against the master's roster when it comes back.
     */
    public function onStop(): void
    {
    }

}
