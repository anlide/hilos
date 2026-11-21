<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents;

use Demo\EcommerceShop\Agents\Hilos\SessionsLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\CartStateSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartSendSignalData;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Object\Item\SessionProductCart;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Hilos;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\SessionsSweptSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Objects;
use Hilos\HilosException;

/**
 * Monopolistic library of the guests' carts - the one writer of the carts of visitors without an account.
 *
 * A visitor without an account stays nameless; what the shop keeps for one is the cart of its
 * browser session, a line per product, cut by the session's row number. Putting a product in,
 * stepping or setting its quantity and taking it out happen here and nowhere else, under the
 * rules the cart of an account shares. One instance runs per cluster, and the placement policy
 * chooses its node. The library alone holds the carts whole; everybody else reads one row by
 * key or queries the database (docs/agents/architecture/entity-libraries.md). The carts grow
 * with the visitors and are bounded by the sweep of the sessions: when the sessions library
 * removes ended sessions, it tells this library which, and their carts go. It also tells every
 * tab of a session its cart ({@see CartGroup}).
 */
final class GuestCartsLibraryAgent extends AbstractAgent
{
    /**
     * @var array<string, list<TruthSourceOperation>> The guests' carts, owned whole
     */
    public const array OWNS_DB = [
        EcommerceShopDbContext::sessionProductCarts => TruthSourceOperation::BY_KIND,
    ];

    /**
     * @var list<string> The products and the stock a cart line is checked against: their own libraries write them,
     *     and a cart write only reads the kind of its product and the units its warehouses keep
     */
    public const array READS_DB = [
        EcommerceShopDbContext::products,
        EcommerceShopDbContext::productWarehouseStocks,
    ];

    public const string AGENT_TYPE = AgentType::GUEST_CARTS_LIBRARY;

    // The sweep frame is declared HERE and not in the sessions library, which is what routes it
    // to this agent: a destination is taken from whoever names a signal, and the library naming
    // its own outgoing frame would send it back to itself. A frame nobody declares is one the
    // library does not send.
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_SESSIONS_SWEPT => SessionsSweptSignalData::class,
        EcommerceShopSignalConstants::GUEST_CART_SEND => GuestCartSendSignalData::class,
    ];

    /** Cart actions and the sweep arrive in frames; there is no periodic work. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    /**
     * Declares the guests' carts complete in this process.
     *
     * The carts load lazily by key everywhere, and a cart write walks the lines of its session,
     * so the holder of the set reads it in once here. The declaration outlives a db-reset and a
     * restore: a collection that was whole reads itself whole again ({@see Objects::reHydrate()}).
     *
     * @throws LogicException When the collection is not mounted, or its entity collection class is not configured
     * @throws DatabaseException When loading the collection from the database fails
     */
    public function onStart(): void
    {
        (Hilos::$db->sessionProductCarts->getObjectCollection() ?? throw new LogicException('The guest carts collection is not mounted'))
            ->preloadAll();
    }

    /**
     * The carts are durable, so stop cleanup only unregisters truth sources via WorkerManager.
     */
    public function onStop(): void
    {
    }

    /**
     * Removes the carts of the sessions the sessions library swept, and tells a session's tabs its cart when asked.
     *
     * @param AgentSignalData $data Agent signal wrapper with the inner payload to dispatch
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it
     *     (unused: only {@see SessionsLibraryAgent} sends the sweep, and only {@see EcommerceShopAgent} asks for a cart)
     * @param string $name Agent signal name
     * @throws AgentUnknownSignalException When signal name is not supported by this agent
     * @throws InvalidAgentSignalPayloadException When the payload is not the one its name promises
     * @throws InvalidArgumentException When the cart frame cannot be named or queued outside a transaction
     * @throws HilosException When the right, the database or the collection refuses the delete or the read
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_SESSIONS_SWEPT:
                $swept = $data->data;
                if (!$swept instanceof SessionsSweptSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, SessionsSweptSignalData::class, $swept);
                }

                Hilos::$db->sessionProductCarts->actions->deleteForSessions($swept->sessionIds);

                return;

            case EcommerceShopSignalConstants::GUEST_CART_SEND:
                $send = $data->data;
                if (!$send instanceof GuestCartSendSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, GuestCartSendSignalData::class, $send);
                }

                $this->publishCart($send->sessionId);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Sends the whole cart of one session to every tab of it.
     *
     * Every write of this library to a guest's cart ends with this call, and so does a tab put in
     * the session's group. The lines are read now, in the order they were put in, and leave after
     * the commit, as {@see AbstractSessionsLibraryAgent} sends a session's toasts. The sweep does
     * not call it: no browser presents a swept session again
     * ({@see AbstractSessionsLibraryAgent::sweepEndedSessions()}).
     *
     * @param int $sessionId Row of the session whose cart is told
     * @throws InvalidArgumentException When the frame cannot be named or queued outside a transaction
     * @throws HilosException When the collection refuses the read
     */
    private function publishCart(int $sessionId): void
    {
        $lines = [];
        foreach (Hilos::$db->sessionProductCarts->whereColumnIs(SessionProductCart::sessionId, $sessionId) as $line) {
            $lines[$line->id] = [CartStateSignalData::PRODUCT_ID => $line->productId, CartStateSignalData::QUANTITY => $line->quantity];
        }
        ksort($lines);

        $frame = new CartStateSignalData(null, array_values($lines));
        Database::afterCommit(fn () => $this->sendToGroup(EcommerceShopSignalConstants::CART_STATE, CartGroup::forSession($sessionId), $frame));
    }
}
