<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents;

use Demo\EcommerceShop\Agents\Hilos\SessionsLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\CartStateSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartSendSignalData;
use Demo\EcommerceShop\Database\Actions\CartVoucherRules;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Object\Item\SessionProductCart;
use Demo\EcommerceShop\Database\Object\Item\SessionVoucherCart;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Hilos;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\SessionsSweptSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\Cron\Exception\CronRuleNameException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\ExternalWriterException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\DuplicateIdException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Exception\TableNameUndeterminedException;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\Exception\Transaction\AnnouncementFailedException;
use Hilos\Database\Exception\Transaction\NestedTransactionRefusedException;
use Hilos\Database\Exception\Transaction\TransactionNotOpenException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Objects;
use Hilos\HilosException;
use LogicException as NativeLogicException;
use Throwable;

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
 *
 * SCAFFOLD: The browser cart actions and payload will use the voucher methods when voucher controls are wired.
 */
final class GuestCartsLibraryAgent extends AbstractAgent
{
    /**
     * @var array<string, list<TruthSourceOperation>> Guest cart lines and voucher bindings, owned whole
     */
    public const array OWNS_DB = [
        EcommerceShopDbContext::sessionProductCarts => TruthSourceOperation::BY_KIND,
        EcommerceShopDbContext::sessionVoucherCarts => TruthSourceOperation::BY_KIND,
    ];

    /**
     * @var list<string> Catalog entries, stock and sessions checked by cart operations
     */
    public const array READS_DB = [
        EcommerceShopDbContext::products,
        EcommerceShopDbContext::productWarehouseStocks,
        EcommerceShopDbContext::vouchers,
        HilosDbContext::sessions,
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

    /** Frames and the recovery rule wake the worker. Each scheduled tick takes one bounded page per cart table. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    private const string RECOVERY_RULE = 'guest_cart_sweep_recovery';
    private const string RECOVERY_CRON = '* * * * *';
    private const int RECOVERY_ROWS_PER_TABLE = 25;

    private ?CronRule $recoveryRule = null;
    private bool $recoverOnFirstTick = true;
    private int $recoveryAfterLineId = 0;
    private int $recoveryAfterVoucherSessionId = 0;

    /** @var array{int, int}|null Fixed product and voucher high-water marks for this owner's current pass */
    private ?array $recoveryHorizons = null;

    /**
     * Declares the guests' carts complete in this process.
     *
     * The carts load lazily by key everywhere, and a cart write walks the lines of its session,
     * so the holder of the set reads it in once here. The declaration outlives a db-reset and a
     * restore: a collection that was whole reads itself whole again ({@see Objects::reHydrate()}).
     * The worker keeps the agent after a failed start hook, so its schedule must already be in place.
     *
     * @throws LogicException When the collection is not mounted, or its entity collection class is not configured
     * @throws DatabaseException When loading the collection from the database fails
     * @throws CronRuleNameException When the recovery rule name is reserved
     */
    public function onStart(): void
    {
        $this->recoveryRule = new CronRule(self::RECOVERY_RULE, self::RECOVERY_CRON);
        (Hilos::$db->sessionProductCarts->getObjectCollection() ?? throw new LogicException('The guest carts collection is not mounted'))
            ->preloadAll();
    }

    /**
     * Repairs carts left by a failed or undelivered sweep, starting with the first tick of this owner.
     *
     * A failed batch leaves both cursors in place. When an owner is replaced, the new owner
     * starts from the beginning because the cart rows themselves are the evidence of unfinished work.
     *
     * @throws HilosException When the schedule, recovery page, sweep transaction or receipt fails
     * @throws NativeLogicException When a row factory refuses a returned row
     */
    public function onTick(): void
    {
        $rule = $this->recoveryRule;
        if ($rule === null) {
            return;
        }
        $scheduled = $rule->shouldRun();
        if (!$scheduled && !$this->recoverOnFirstTick) {
            return;
        }
        $this->recoverOnFirstTick = false;

        try {
            Database::withoutReconnectRetries(
                fn () => $this->recoverCartPage($rule, $scheduled),
                DatabaseConnectionDefaults::PRIMARY_INDEX,
                ChangeLogDatabase::CONNECTION_INDEX,
            );
        } catch (HilosException | NativeLogicException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new LogicException('Failed to recover guest carts', previous: $failure);
        }
    }

    /**
     * The carts are durable, so stop cleanup only unregisters truth sources via WorkerManager.
     */
    public function onStop(): void
    {
    }

    /**
     * Assigns an active voucher to a guest cart, even when the cart has no product lines.
     *
     * @param int $sessionId Browser session ID
     * @param string $code Voucher code as entered
     * @throws EmptyValueException When the trimmed code is empty
     * @throws ValueTooLongException When the code exceeds the catalog limit
     * @throws ValidationException When the session is no longer a guest or the code is malformed, unknown or inactive
     * @throws LogicException When the catalog or cart collection is misconfigured or the matching voucher lacks an ID
     * @throws InvalidArgumentException When a read uses an invalid column or object or the sync signal cannot be queued
     * @throws DatabaseException When the session, catalog or binding read or write fails
     * @throws ItemNotFoundException When the binding disappears during a keyed read
     * @throws ObjectGetIdStringNotImplementedException When the voucher or its binding cannot be keyed
     * @throws CollectionNotManualException When the selection cannot accept the matched voucher
     * @throws ItemNotFoundForUpdateException When the binding is no longer persisted
     * @throws WriteNotAllowedException When this process may not change the binding
     * @throws CreateNotAllowedException When this process may not create the binding
     * @throws ExternalWriterException When the binding collection has an external writer
     * @throws ObjectCollectionNullException When the binding has no backing collection
     * @throws SourceChangeSubscriberException When a binding change subscriber fails
     * @throws DuplicateIdException When the collection already holds the binding key
     * @throws TableNameUndeterminedException When the binding table is unknown
     * @throws HilosException When the binding collection refuses a reload after database replacement
     */
    public function applyVoucher(int $sessionId, string $code): void
    {
        if (!Hilos::$db->sessions->isStoredGuest($sessionId)) {
            throw new ValidationException('This guest session is no longer available');
        }

        $voucherId = CartVoucherRules::voucherForCode($code)->id
            ?? throw new LogicException('A catalog voucher must have an ID');
        $binding = Hilos::$db->sessionVoucherCarts[$sessionId] ?? null;
        if ($binding === null) {
            Hilos::$db->sessionVoucherCarts->actions->create($sessionId, $voucherId);

            return;
        }

        $binding->actions->setVoucher($voucherId);
    }

    /**
     * Clears the guest cart's voucher slot if it is occupied.
     *
     * @param int $sessionId Browser session ID
     * @throws LogicException When the binding collection is misconfigured
     * @throws InvalidArgumentException When an object does not match the collection or the sync signal cannot be queued
     * @throws DatabaseException When the binding read or delete fails
     * @throws ItemNotFoundException When the binding disappears during a keyed read
     * @throws WriteNotAllowedException When this process may not remove the binding
     * @throws CreateNotAllowedException When the ORM refuses a new row at its write door
     * @throws ObjectCollectionNullException When the binding has no backing collection
     * @throws ObjectGetIdStringNotImplementedException When the binding cannot be keyed
     * @throws SourceChangeSubscriberException When a binding change subscriber fails
     */
    public function removeVoucher(int $sessionId): void
    {
        $binding = Hilos::$db->sessionVoucherCarts[$sessionId] ?? null;
        $binding?->actions->remove();
    }

    /**
     * Reads the current catalog row of the voucher selected for a guest cart.
     *
     * @param int $sessionId Browser session ID
     * @return ?Voucher Current catalog voucher, including inactive vouchers, or null when no voucher is selected
     * @throws LogicException When the catalog or binding collection is misconfigured
     * @throws InvalidArgumentException When a loaded object does not match its collection
     * @throws DatabaseException When the binding or catalog read fails
     * @throws ItemNotFoundException When a saved voucher ID is missing or the binding disappears during a keyed read
     */
    public function cartVoucher(int $sessionId): ?Voucher
    {
        $binding = Hilos::$db->sessionVoucherCarts[$sessionId] ?? null;

        return $binding === null ? null : Hilos::$db->vouchers[$binding->voucherId];
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
     * @throws NamedDatabaseException When the sweep database is not configured
     * @throws NestedTransactionRefusedException When an application transaction is already open
     * @throws TransactionNotOpenException When the sweep transaction cannot commit its level
     * @throws DatabaseConnectionException When the sweep commit cannot reach its connection
     * @throws DatabaseRuntimeException When the sweep commit fails
     * @throws AnnouncementFailedException When an announcement fails after commit outside a handler
     * @throws LogicException When unexpected code fails while sweeping the carts
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_SESSIONS_SWEPT:
                $swept = $data->data;
                if (!$swept instanceof SessionsSweptSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, SessionsSweptSignalData::class, $swept);
                }

                $this->sweepCarts($swept->sessionIds);

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

    /** @return list<CronRule> Current orphaned cart recovery schedule */
    protected function cronRules(): array
    {
        return $this->recoveryRule === null ? [] : [$this->recoveryRule];
    }

    /**
     * Reads and repairs one page, then advances its cursors after the transaction and receipt complete.
     *
     * @param CronRule $rule Current recovery rule
     * @param bool $scheduled Whether the rule fired on this tick
     */
    private function recoverCartPage(CronRule $rule, bool $scheduled): void
    {
        $this->recoveryHorizons ??= Hilos::$db->sessionProductCarts->cartRecoveryHorizons();
        [$throughLineId, $throughVoucherSessionId] = $this->recoveryHorizons;
        [$lineAbsences, $voucherAbsences] = Hilos::$db->sessionProductCarts->cartRowAbsencesAfter(
            $this->recoveryAfterLineId,
            $throughLineId,
            $this->recoveryAfterVoucherSessionId,
            $throughVoucherSessionId,
            self::RECOVERY_ROWS_PER_TABLE,
        );
        $orphanLineIds = array_keys(array_filter($lineAbsences));
        $orphanVoucherSessionIds = array_keys(array_filter($voucherAbsences));
        if ($orphanLineIds !== [] || $orphanVoucherSessionIds !== []) {
            $receipt = $scheduled
                ? JournalReceiptData::cron($rule->name, $rule->expression, $this->getId())
                : JournalReceiptData::agent(self::RECOVERY_RULE, $this->getId());
            JournalReceiptScope::run($receipt, static function () use ($orphanLineIds, $orphanVoucherSessionIds): void {
                Database::transaction(DatabaseNames::PRIMARY, static function () use ($orphanLineIds, $orphanVoucherSessionIds): void {
                    Hilos::$db->sessionProductCarts->actions->deleteRecoveryLines($orphanLineIds);
                    foreach ($orphanVoucherSessionIds as $sessionId) {
                        Hilos::$db->sessionVoucherCarts[$sessionId]->actions->remove();
                    }
                });
            });
        }

        $this->recoveryAfterLineId = count($lineAbsences) === self::RECOVERY_ROWS_PER_TABLE ? array_key_last($lineAbsences) : $throughLineId;
        $this->recoveryAfterVoucherSessionId = count($voucherAbsences) === self::RECOVERY_ROWS_PER_TABLE
            ? array_key_last($voucherAbsences)
            : $throughVoucherSessionId;
        if ($this->recoveryAfterLineId === $throughLineId && $this->recoveryAfterVoucherSessionId === $throughVoucherSessionId) {
            $this->recoveryAfterLineId = 0;
            $this->recoveryAfterVoucherSessionId = 0;
            $this->recoveryHorizons = null;
        }
    }

    /**
     * Removes product lines and voucher bindings together for the swept sessions.
     *
     * @param list<int> $sessionIds Swept session IDs
     * @throws NamedDatabaseException When the sweep database is not configured
     * @throws NestedTransactionRefusedException When an application transaction is already open
     * @throws TransactionNotOpenException When the sweep transaction cannot commit its level
     * @throws DatabaseConnectionException When the sweep commit cannot reach its connection
     * @throws DatabaseRuntimeException When the sweep commit fails
     * @throws AnnouncementFailedException When an announcement fails after commit outside a handler
     * @throws LogicException When unexpected code fails inside the sweep transaction
     * @throws HilosException When a cart read or removal fails
     */
    private function sweepCarts(array $sessionIds): void
    {
        if ($sessionIds === []) {
            return;
        }

        try {
            Database::transaction(DatabaseNames::PRIMARY, static function () use ($sessionIds): void {
                Hilos::$db->sessionProductCarts->actions->deleteForSessions($sessionIds);
                foreach (Hilos::$db->sessionVoucherCarts->whereColumnIn(SessionVoucherCart::sessionId, ...$sessionIds) as $binding) {
                    $binding->actions->remove();
                }
            });
        } catch (HilosException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new LogicException('Failed to sweep guest carts', previous: $failure);
        }
    }

    /**
     * Sends the whole cart of one session to every tab of it.
     *
     * The lines are read in the order they were put in and leave after the commit, as
     * {@see AbstractSessionsLibraryAgent} sends a session's toasts.
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
