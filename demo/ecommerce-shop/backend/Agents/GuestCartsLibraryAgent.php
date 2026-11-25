<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents;

use Closure;
use Demo\EcommerceShop\CLI\DTO\GuestCartSetQuantityCommandData;
use Demo\EcommerceShop\CLI\DTO\GuestCartSetVoucherCommandData;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductDoneSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartStateSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartSendSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferClearedSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferOfferSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferReceivedSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferWakeSignalData;
use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Demo\EcommerceShop\Database\Actions\Collection\SessionProductCartsActions;
use Demo\EcommerceShop\Database\Actions\CartVoucherRules;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Object\Item\Voucher as ObjectVoucher;
use Demo\EcommerceShop\Database\View\Item\SessionProductCart as CartLine;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Demo\EcommerceShop\Testing\VoucherFixture;
use Hilos\Auth\Session\DTO\SessionsSweptSignalData;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\Cron\Exception\CronRuleNameException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
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
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\Exception\TestRollbackRefusedException;
use Hilos\Database\Exception\Transaction\AnnouncementFailedException;
use Hilos\Database\Exception\Transaction\NestedTransactionRefusedException;
use Hilos\Database\Exception\Transaction\TransactionNotOpenException;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Objects;
use Hilos\Environment\NonProductionGate;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\WiringRefusal;
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
 * removes ended sessions, it tells this library which. Assigned carts survive that
 * sweep until receipt by their account. The library tells each session's tabs its active cart.
 *
 * SCAFFOLD: Browser cart actions will call the voucher methods when the voucher controls are wired.
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
     * @var list<string> Catalog entries, stock, sessions and transfer records checked by cart operations
     */
    public const array READS_DB = [
        EcommerceShopDbContext::products,
        EcommerceShopDbContext::productWarehouseStocks,
        EcommerceShopDbContext::vouchers,
        HilosDbContext::sessions,
        EcommerceShopDbContext::shopGuestCartTransfers,
    ];

    public const array READS_RT = [
        ...parent::READS_RT,
        EcommerceShopRtContext::connections,
    ];

    public const string AGENT_TYPE = AgentType::GUEST_CARTS_LIBRARY;

    public const array AGENT_COMMANDS = [
        EcommerceShopCommandConstants::GUEST_CART_SET_QUANTITY => GuestCartSetQuantityCommandData::class,
        EcommerceShopCommandConstants::GUEST_CART_SET_VOUCHER => GuestCartSetVoucherCommandData::class,
    ];

    // The sweep frame is declared HERE and not in the sessions library, which is what routes it
    // to this agent: a destination is taken from whoever names a signal, and the library naming
    // its own outgoing frame would send it back to itself. A frame nobody declares is one the
    // library does not send.
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_SESSIONS_SWEPT => SessionsSweptSignalData::class,
        EcommerceShopSignalConstants::GUEST_CART_SEND => GuestCartSendSignalData::class,
        EcommerceShopSignalConstants::GUEST_CART_ADD_PRODUCT => CartAddProductSignalData::class,
        EcommerceShopSignalConstants::GUEST_CART_TRANSFER_WAKE => GuestCartTransferWakeSignalData::class,
        EcommerceShopSignalConstants::GUEST_CART_TRANSFER_RECEIVED => GuestCartTransferReceivedSignalData::class,
    ];

    /** Frames and the recovery rule wake the worker. Each scheduled tick takes bounded cart and transfer pages. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    private const string RECOVERY_RULE = 'guest_cart_sweep_recovery';
    private const string RECOVERY_CRON = '* * * * *';
    private const int RECOVERY_ROWS_PER_TABLE = 25;
    private const int TRANSFERS_PER_PAGE = 25;
    private const int RECEIVED_ROWS_PER_ATTEMPT = 25;
    private const string GUEST_SESSION_UNAVAILABLE = 'This guest session is no longer available';
    private const string FIXTURE_VOUCHER_MISMATCH = 'This guest cart holds a different voucher';

    private ?CronRule $recoveryRule = null;
    private bool $recoverOnFirstTick = true;
    private int $recoveryAfterLineId = 0;
    private int $recoveryAfterVoucherId = 0;
    private int $recoveryAfterTransferId = 0;
    private ?int $recoveryThroughTransferId = null;

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
     * Repairs missed cart sweeps and transfer signals, starting with the first tick of this owner.
     *
     * A failed batch leaves the cursors in place. When an owner is replaced, the new owner
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
     * Steps an existing line of the session's guest cart and publishes the committed cart.
     *
     * @param string $acceptKey Connection requesting the step
     * @param int $sessionId Browser session ID
     * @param int $lineId Primary key of the saved line
     * @param int $step One unit up or down
     * @throws ValidationException When the cart line cannot be addressed or the product rules refuse the step
     * @throws InvalidArgumentException When the direction, database read or sync signal is invalid
     * @throws LogicException When a configured collection cannot provide its item class
     * @throws DatabaseException When a read, write or commit fails
     * @throws ObjectGetIdStringNotImplementedException When a cart or stock row cannot provide its key
     * @throws CollectionNotManualException When a stock selection refuses a matched row
     * @throws ItemNotFoundException When a keyed row disappears during its read
     * @throws ObjectCollectionNullException When the line has no backing collection
     * @throws WriteNotAllowedException When this process cannot update the line
     * @throws CreateNotAllowedException When the write guard refuses a new row
     * @throws SourceChangeSubscriberException When a DB change subscriber fails
     * @throws NamedDatabaseException When the primary database is not configured
     * @throws NestedTransactionRefusedException When the primary database already has an application transaction
     * @throws TransactionNotOpenException When the level is no longer open at commit
     * @throws DatabaseConnectionException When the commit cannot reach the database
     * @throws DatabaseRuntimeException When the database refuses the commit
     * @throws AnnouncementFailedException When a commit announcement fails outside a handler
     * @throws HilosException When the configured cart sources cannot be read
     * @throws Throwable When the transaction propagates another failure from the cart operation
     */
    public function stepProductLine(string $acceptKey, int $sessionId, int $lineId, int $step): void
    {
        $connection = Hilos::$rt->connections[$acceptKey];
        $token = $connection?->sessionToken;
        if ($token === null || $connection->staleSince() !== null || $connection->sessionId !== $sessionId || $connection->userId !== null) {
            throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
        }

        SessionProductCartsActions::inPrimaryTransaction(function () use ($lineId, $sessionId, $token, $step): void {
            $cartEpoch = Hilos::$db->sessions->lockActiveGuestCartEpoch($sessionId, $token);
            $line = $cartEpoch === null ? null : Hilos::$db->sessionProductCarts->lockCartLine($sessionId, $cartEpoch, $lineId);
            if ($line === null) {
                throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
            }

            $line->actions->step($step);
            $this->publishCart($sessionId, $cartEpoch);
        });
    }

    /**
     * Sets the exact quantity of a saved line and publishes the cart when the quantity changes.
     *
     * @param string $acceptKey Connection invoking the edit
     * @param int $sessionId Browser session ID
     * @param int $lineId Primary key of the saved line
     * @param string $quantity Original quantity text from the form
     * @param int $expectedQuantity Saved quantity the edit started from
     * @throws ValidationException When the cart line cannot be addressed or the quantity is refused
     * @throws InvalidArgumentException When a database read or sync signal is invalid
     * @throws LogicException When a configured collection cannot provide its item class
     * @throws DatabaseException When a read, write or commit fails
     * @throws ObjectGetIdStringNotImplementedException When a cart or stock row cannot provide its key
     * @throws CollectionNotManualException When a stock selection refuses a matched row
     * @throws ItemNotFoundException When a keyed row disappears during its read
     * @throws ObjectCollectionNullException When the line has no backing collection
     * @throws WriteNotAllowedException When this process cannot update the line
     * @throws CreateNotAllowedException When the write guard refuses a new row
     * @throws SourceChangeSubscriberException When a DB change subscriber fails
     * @throws NamedDatabaseException When the primary database is not configured
     * @throws NestedTransactionRefusedException When the primary database already has an application transaction
     * @throws TransactionNotOpenException When the level is no longer open at commit
     * @throws DatabaseConnectionException When the commit cannot reach the database
     * @throws DatabaseRuntimeException When the database refuses the commit
     * @throws AnnouncementFailedException When a commit announcement fails outside a handler
     * @throws HilosException When the configured cart sources cannot be read
     * @throws Throwable When the transaction propagates another failure from the cart operation
     */
    public function setProductLineQuantity(string $acceptKey, int $sessionId, int $lineId, string $quantity, int $expectedQuantity): void
    {
        $connection = Hilos::$rt->connections[$acceptKey];
        $token = $connection?->sessionToken;
        if ($token === null || $connection->staleSince() !== null || $connection->sessionId !== $sessionId || $connection->userId !== null) {
            throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
        }

        SessionProductCartsActions::inPrimaryTransaction(function () use ($sessionId, $token, $lineId, $quantity, $expectedQuantity): void {
            try {
                $cartEpoch = Hilos::$db->sessions->lockActiveGuestCartEpoch($sessionId, $token, waitForLock: false);
            } catch (DatabaseException $failure) {
                throw new ValidationException(CartLineRules::LINE_UNAVAILABLE, previous: $failure);
            }
            $line = $cartEpoch === null ? null : Hilos::$db->sessionProductCarts->lockCartLine($sessionId, $cartEpoch, $lineId);
            if ($line === null) {
                throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
            }

            $line->actions->setQuantityFrom($expectedQuantity, $quantity);
            if ($line->quantity !== $expectedQuantity) {
                $this->publishCart($sessionId, $cartEpoch);
            }
        });
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
     * @throws NamedDatabaseException When the primary database cannot be resolved
     * @throws TestRollbackRefusedException When a test rollback scope is closing
     * @throws NestedTransactionRefusedException When a transaction cannot be opened
     * @throws TransactionNotOpenException When the transaction fails or loses its level
     * @throws DatabaseConnectionException When the commit cannot reach the database
     * @throws DatabaseRuntimeException When the commit fails
     * @throws AnnouncementFailedException When releasing a committed announcement fails outside a handler
     * @throws HilosException When the binding collection refuses a reload or the cart snapshot cannot be read
     * @throws Throwable When the transaction propagates another failure from the cart operation
     */
    public function applyVoucher(int $sessionId, string $code): void
    {
        SessionProductCartsActions::inPrimaryTransaction(function () use ($sessionId, $code): void {
            $cartEpoch = $this->requireActiveVoucherCartEpoch($sessionId);
            $this->applyVoucherToSlot($sessionId, $cartEpoch, $code, allowReplacement: true);
        });
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
     * @throws ValidationException When the session is no longer an active guest
     * @throws NamedDatabaseException When the primary database cannot be resolved
     * @throws TestRollbackRefusedException When a test rollback scope is closing
     * @throws NestedTransactionRefusedException When a transaction cannot be opened
     * @throws TransactionNotOpenException When the transaction fails or loses its level
     * @throws DatabaseConnectionException When the commit cannot reach the database
     * @throws DatabaseRuntimeException When the commit fails
     * @throws AnnouncementFailedException When releasing a committed announcement fails outside a handler
     * @throws HilosException When the cart snapshot cannot be read
     * @throws Throwable When the transaction propagates another failure from the cart operation
     */
    public function removeVoucher(int $sessionId): void
    {
        SessionProductCartsActions::inPrimaryTransaction(function () use ($sessionId): void {
            $cartEpoch = $this->requireActiveVoucherCartEpoch($sessionId);
            $binding = Hilos::$db->sessionVoucherCarts->storedSlot($sessionId, $cartEpoch);
            if ($binding === null) {
                return;
            }

            $binding->actions->remove();
            $this->publishCart($sessionId, $cartEpoch);
        });
    }

    /**
     * Removes a line from the current guest cart inside the caller's journaled transaction.
     *
     * @param int $sessionId Browser session ID
     * @param int $lineId Product line primary key
     * @param string $token Current connection token
     * @throws ValidationException When the session is no longer a guest or the line is unavailable to it
     * @throws LogicException When the cart collection is misconfigured
     * @throws InvalidArgumentException When a read uses an invalid object or a signal cannot be queued
     * @throws DatabaseException When the session or line read or the delete fails
     * @throws ItemNotFoundException When the line disappears during a keyed read
     * @throws PropertyNotFoundException When the line does not expose a required property
     * @throws ActionsClassException When the line's actions cannot be resolved
     * @throws WriteNotAllowedException When this process may not remove the line
     * @throws CreateNotAllowedException When the ORM refuses a new row at its write door
     * @throws ObjectCollectionNullException When the line has no owning collection
     * @throws ObjectGetIdStringNotImplementedException When the line has no usable key
     * @throws SourceChangeSubscriberException When a change subscriber fails
     * @throws HilosException When a locking read or snapshot fails
     */
    public function removeProductLine(int $sessionId, int $lineId, string $token): void
    {
        $cartEpoch = Hilos::$db->sessions->lockActiveGuestCartEpoch($sessionId, $token);
        if ($cartEpoch === null) {
            throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
        }

        $line = Hilos::$db->sessionProductCarts->lockCartLine($sessionId, $cartEpoch, $lineId);
        if ($line === null) {
            throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
        }

        $line->actions->remove();
        $this->publishCart($sessionId, $cartEpoch);
    }

    /**
     * Reads the current catalog row of the voucher selected for a guest cart.
     *
     * @param int $sessionId Browser session ID
     * @param int $cartEpoch Number of the guest cart
     * @return ?Voucher Current catalog voucher, including inactive vouchers, or null when no voucher is selected
     * @throws LogicException When the catalog or binding collection is misconfigured
     * @throws InvalidArgumentException When a loaded object does not match its collection
     * @throws DatabaseException When the binding or catalog read fails
     * @throws ItemNotFoundException When a saved voucher ID is missing or the binding disappears during a keyed read
     */
    public function cartVoucher(int $sessionId, int $cartEpoch): ?Voucher
    {
        $voucherId = Hilos::$db->sessionVoucherCarts->storedVoucherId($sessionId, $cartEpoch);

        return $voucherId === null ? null : Hilos::$db->vouchers[$voucherId];
    }

    /**
     * Handles guest cart reads, writes, sweeps and transfer delivery.
     *
     * @param AgentSignalData $data Agent signal wrapper with the inner payload to dispatch
     * @param string $sender Sender in full, as {@see SignalSource::describe()} spells it
     * @param string $name Agent signal name
     * @throws AgentUnknownSignalException When signal name is not supported by this agent
     * @throws InvalidAgentSignalPayloadException When the payload is not the one its name promises
     * @throws AgentException When a transfer frame has the wrong sender or the attempt fails
     * @throws InvalidFormatException When the cart add outcome has no original product address
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

                $this->publishCart($send->sessionId, $send->cartEpoch);

                return;

            case EcommerceShopSignalConstants::GUEST_CART_ADD_PRODUCT:
                $ask = $data->data;
                if (!$ask instanceof CartAddProductSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, CartAddProductSignalData::class, $ask);
                }

                $this->addProductToCart($ask);

                return;

            case EcommerceShopSignalConstants::GUEST_CART_TRANSFER_WAKE:
                $wake = $data->data;
                if (!$wake instanceof GuestCartTransferWakeSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, GuestCartTransferWakeSignalData::class, $wake);
                }

                $this->wakeTransfers($wake, $sender);

                return;

            case EcommerceShopSignalConstants::GUEST_CART_TRANSFER_RECEIVED:
                $received = $data->data;
                if (!$received instanceof GuestCartTransferReceivedSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, GuestCartTransferReceivedSignalData::class, $received);
                }

                $this->receiveTransferReceipt($received, $sender);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Answers guest cart fixture commands under the library's ownership.
     * A zero is acknowledged only after storage confirms the line is absent following commit.
     *
     * @param CommandRequestDTO $data Topology-hydrated command
     * @param string $source Command source, unused
     * @param string $name Signal name, unused
     * @throws AgentException When the fixture operation fails
     * @throws InvalidArgumentException When the reply cannot be queued
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        if ($data->command === EcommerceShopCommandConstants::GUEST_CART_SET_VOUCHER) {
            $this->handleFixtureVoucherCommand($data);
            return;
        }
        if ($data->command !== EcommerceShopCommandConstants::GUEST_CART_SET_QUANTITY) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));
            return;
        }
        if (!NonProductionGate::admitted()) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'Setting a guest cart fixture is test-only'));
            return;
        }
        $query = $data->parsedPayload;
        if (!$query instanceof GuestCartSetQuantityCommandData) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'The guest cart fixture request is invalid'));
            return;
        }

        try {
            [$sessionId, $cartEpoch] = SessionProductCartsActions::inPrimaryTransaction(fn (): array => $this->setFixtureQuantity($query));

            Database::afterCommit(function () use ($data, $query, $sessionId, $cartEpoch): void {
                if ($query->quantity === 0 && Hilos::$db->sessionProductCarts->hasCartLine($sessionId, $cartEpoch, $query->productId)) {
                    $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'The guest cart fixture removal could not be confirmed'));

                    return;
                }

                $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [EcommerceShopCommandConstants::quantity => $query->quantity]));
            }, DatabaseConnectionDefaults::PRIMARY_INDEX);
        } catch (ValidationException $refusal) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $refusal->getMessage()));
        } catch (WiringRefusal $refusal) {
            throw new AgentException('The guest cart fixture data sources are unavailable', previous: $refusal);
        } catch (Throwable $failure) {
            throw new AgentException('The guest cart fixture could not be changed', previous: $failure);
        }
    }

    /** @return list<CronRule> Current orphaned cart recovery schedule */
    protected function cronRules(): array
    {
        return $this->recoveryRule === null ? [] : [$this->recoveryRule];
    }

    /**
     * Dispatches the test-only voucher fixture and replies with the resulting applied flag.
     *
     * @param CommandRequestDTO $data Topology-hydrated fixture command
     * @throws AgentException When the fixture operation fails
     * @throws InvalidArgumentException When the reply cannot be queued
     */
    private function handleFixtureVoucherCommand(CommandRequestDTO $data): void
    {
        if (!VoucherFixture::isAllowed()) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, VoucherFixture::ENVIRONMENT_REFUSAL));

            return;
        }
        $query = $data->parsedPayload;
        if (!$query instanceof GuestCartSetVoucherCommandData) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'The guest cart voucher fixture request is invalid'));

            return;
        }

        try {
            $this->setFixtureVoucher($query);
            $reply = CommandReplyDTO::ok($data->correlationId, [EcommerceShopCommandConstants::applied => $query->applied]);
        } catch (ValidationException $refusal) {
            $reply = CommandReplyDTO::error($data->correlationId, $refusal->getMessage());
        } catch (Throwable $failure) {
            throw new AgentException('The guest cart voucher fixture could not be changed', previous: $failure);
        }
        $this->replyToCommand($reply);
    }

    /**
     * Writes the fixture only for an empty slot or its selected voucher.
     *
     * @param GuestCartSetVoucherCommandData $query Voucher and desired association
     * @throws ValidationException When the session, code or existing association refuses the change
     * @throws EmptyValueException When the code is empty
     * @throws ValueTooLongException When the code exceeds the catalog limit
     * @throws LogicException When a collection is misconfigured or the catalog voucher lacks an ID
     * @throws InvalidArgumentException When an object, query or sync signal is invalid
     * @throws DatabaseException When a row cannot be read or written
     * @throws ItemNotFoundException When a keyed read cannot resolve its target
     * @throws ObjectGetIdStringNotImplementedException When a voucher or binding cannot be keyed
     * @throws CollectionNotManualException When the selection cannot accept the matched voucher
     * @throws ItemNotFoundForUpdateException When the binding is no longer persisted
     * @throws WriteNotAllowedException When this process may not change the binding
     * @throws CreateNotAllowedException When this process may not create the binding
     * @throws ExternalWriterException When the binding collection has an external writer
     * @throws ObjectCollectionNullException When the binding has no backing collection
     * @throws SourceChangeSubscriberException When a binding change subscriber fails
     * @throws DuplicateIdException When the collection already holds the binding key
     * @throws TableNameUndeterminedException When the binding table is unknown
     * @throws NamedDatabaseException When the primary database cannot be resolved
     * @throws TestRollbackRefusedException When a test rollback scope is closing
     * @throws NestedTransactionRefusedException When a transaction cannot be opened
     * @throws TransactionNotOpenException When the transaction fails or loses its level
     * @throws DatabaseConnectionException When the commit cannot reach the database
     * @throws DatabaseRuntimeException When the commit fails
     * @throws AnnouncementFailedException When releasing a committed announcement fails outside a handler
     * @throws HilosException When a configured cart source refuses the operation
     * @throws Throwable When the transaction propagates another failure from the cart operation
     */
    private function setFixtureVoucher(GuestCartSetVoucherCommandData $query): void
    {
        $sessionId = Hilos::$db->sessions->findByToken($query->sessionToken)?->id
            ?? throw new ValidationException(self::GUEST_SESSION_UNAVAILABLE);
        SessionProductCartsActions::inPrimaryTransaction(function () use ($sessionId, $query): void {
            $cartEpoch = $this->requireActiveVoucherCartEpoch($sessionId, $query->sessionToken);
            $code = VoucherFixture::code($query->code);
            if ($query->applied) {
                $this->applyVoucherToSlot($sessionId, $cartEpoch, $code, allowReplacement: false);

                return;
            }

            $binding = Hilos::$db->sessionVoucherCarts->storedSlot($sessionId, $cartEpoch);
            if ($binding === null) {
                return;
            }

            $voucher = Hilos::$db->vouchers->lockStoredByCode($code);
            if ($voucher === null || $binding->voucherId !== $voucher[ObjectVoucher::id]) {
                throw new ValidationException(self::FIXTURE_VOUCHER_MISMATCH);
            }

            $binding->actions->remove();
            $this->publishCart($sessionId, $cartEpoch);
        });
    }

    /**
     * Checks and writes the binding with the voucher ID selected under the catalog lock.
     *
     * @param int $sessionId Browser session already locked by the caller
     * @param int $cartEpoch Number of the active guest cart under that lock
     * @param string $code Voucher code as entered
     * @param bool $allowReplacement Whether the operation may replace another voucher
     * @throws EmptyValueException When the trimmed code is empty
     * @throws ValueTooLongException When the code exceeds the catalog limit
     * @throws ValidationException When the session, code or existing binding refuses the change
     * @throws LogicException When the session collection is misconfigured
     * @throws InvalidArgumentException When a session query or sync signal is invalid
     * @throws DatabaseException When the session, catalog or binding read or write fails
     * @throws ItemNotFoundException When the binding disappears during a keyed read
     * @throws ObjectGetIdStringNotImplementedException When the binding cannot be keyed
     * @throws CollectionNotManualException When the selection cannot accept the binding
     * @throws ItemNotFoundForUpdateException When the binding is no longer persisted
     * @throws WriteNotAllowedException When this process may not change the binding
     * @throws CreateNotAllowedException When this process may not create the binding
     * @throws ExternalWriterException When the binding collection has an external writer
     * @throws ObjectCollectionNullException When the binding has no backing collection
     * @throws SourceChangeSubscriberException When a binding change subscriber fails
     * @throws DuplicateIdException When the collection already holds the binding key
     * @throws TableNameUndeterminedException When the binding table is unknown
     * @throws NamedDatabaseException When the primary database cannot be resolved
     * @throws TestRollbackRefusedException When a test rollback scope is closing
     * @throws NestedTransactionRefusedException When a transaction cannot be opened
     * @throws TransactionNotOpenException When the transaction fails or loses its level
     * @throws DatabaseConnectionException When the commit cannot reach the database
     * @throws DatabaseRuntimeException When the commit fails
     * @throws AnnouncementFailedException When releasing a committed announcement fails outside a handler
     * @throws HilosException When the binding collection refuses a reload or the cart snapshot cannot be read
     * @throws Throwable When the transaction propagates another failure from the cart operation
     */
    private function applyVoucherToSlot(int $sessionId, int $cartEpoch, string $code, bool $allowReplacement): void
    {
        CartVoucherRules::applyVoucher($code, function (int $voucherId) use ($sessionId, $cartEpoch, $allowReplacement): void {
            $binding = Hilos::$db->sessionVoucherCarts->storedSlot($sessionId, $cartEpoch);
            if ($binding === null) {
                Hilos::$db->sessionVoucherCarts->actions->create($sessionId, $cartEpoch, $voucherId);
            } elseif ($binding->voucherId === $voucherId) {
                return;
            } elseif ($allowReplacement) {
                $binding->actions->setVoucher($voucherId);
            } else {
                throw new ValidationException(self::FIXTURE_VOUCHER_MISMATCH);
            }
            $this->publishCart($sessionId, $cartEpoch);
        });
    }

    /**
     * @param int $sessionId Browser session ID
     * @param ?string $token The fixture's session token when present
     * @return int Current cart epoch under the session lock
     * @throws ValidationException When the session is no longer available as an active guest
     * @throws HilosException When the locking read fails
     */
    private function requireActiveVoucherCartEpoch(int $sessionId, ?string $token = null): int
    {
        return Hilos::$db->sessions->lockActiveGuestCartEpoch($sessionId, $token)
            ?? throw new ValidationException(self::GUEST_SESSION_UNAVAILABLE);
    }

    /**
     * @param GuestCartSetQuantityCommandData $query Exact quantity to leave for an active guest
     * @return array{int, int} Validated session ID and cart epoch even when the quantity was already reached
     * @throws ValidationException When the session or requested quantity is refused
     * @throws LogicException When a collection or write is misconfigured
     * @throws InvalidArgumentException When a selection or signal cannot be built
     * @throws DatabaseException When a row cannot be read or written
     * @throws ObjectGetIdStringNotImplementedException When a cart line cannot provide a key
     * @throws CollectionNotManualException When a selection refuses a cart line
     * @throws CreateNotAllowedException When this process may not add a line
     * @throws WriteNotAllowedException When this process may not change or remove a line
     * @throws SourceChangeSubscriberException When a store change subscriber fails
     * @throws HilosException When the configured cart sources refuse the operation
     */
    private function setFixtureQuantity(GuestCartSetQuantityCommandData $query): array
    {
        $sessionId = Hilos::$db->sessions->findByToken($query->sessionToken)?->id;
        $cartEpoch = $sessionId === null ? null : Hilos::$db->sessions->lockActiveGuestCartEpoch($sessionId, $query->sessionToken);
        if ($cartEpoch === null) {
            throw new ValidationException(self::GUEST_SESSION_UNAVAILABLE);
        }

        $carts = Hilos::$db->sessionProductCarts;
        $line = $query->quantity === 0
            ? $carts->forCart($sessionId, $cartEpoch)->filter(static fn (CartLine $line): bool => $line->productId === $query->productId)->first()
            : $carts->lockProductLine($sessionId, $cartEpoch, $query->productId);
        if (($line?->quantity ?? 0) === $query->quantity) {
            return [$sessionId, $cartEpoch];
        }

        if ($query->quantity === 0) {
            $carts->actions->remove($sessionId, $cartEpoch, $query->productId);
        } else {
            if ($line === null) {
                $carts->actions->add($sessionId, $cartEpoch, $query->productId);
            }
            if ($line !== null || $query->quantity !== CartLineRules::QUANTITY_MIN) {
                $carts->actions->setQuantity($sessionId, $cartEpoch, $query->productId, $query->quantity);
            }
        }

        $this->publishCart($sessionId, $cartEpoch);

        return [$sessionId, $cartEpoch];
    }

    /**
     * Prompts a bounded read of a session's durable assignments.
     *
     * @param GuestCartTransferWakeSignalData $wake Source session whose state was published
     * @param string $sender Full sender identity
     * @throws AgentException When the wake comes from another sender or its attempt fails
     */
    private function wakeTransfers(GuestCartTransferWakeSignalData $wake, string $sender): void
    {
        if ($sender !== SignalSource::describe(new SignalSource(SignalSource::AGENT, EcommerceShopAgent::AGENT_TYPE))) {
            throw new AgentException('Guest cart transfer wakes require the shop agent as the sender.');
        }

        $this->runTransferAttempt(function () use ($wake): void {
            $this->deliverStoredTransfers(
                Hilos::$db->shopGuestCartTransfers->storedTransferIdsForSession($wake->sessionId, self::TRANSFERS_PER_PAGE),
            );
        });
    }

    /**
     * Accepts a receipt only from the indexed agent of the recipient stored for this assignment.
     *
     * @param GuestCartTransferReceivedSignalData $receipt Report of a committed receipt
     * @param string $sender Full sender identity
     * @throws AgentException When the receipt has the wrong sender or its attempt fails
     */
    private function receiveTransferReceipt(GuestCartTransferReceivedSignalData $receipt, string $sender): void
    {
        $this->runTransferAttempt(function () use ($receipt, $sender): void {
            $stored = Hilos::$db->shopGuestCartTransfers->storedTransfer($receipt->transferId);
            if ($stored === null) {
                return;
            }
            [$sessionId, $cartEpoch, $userId, $received] = $stored;
            if ($receipt->userId !== $userId || $sender !== SignalSource::describe(new SignalSource(
                SignalSource::AGENT,
                HilosAgentType::HILOS_USER,
                (string)$userId,
            ))) {
                throw new AgentException('Guest cart transfer receipts require the assigned user agent as the sender.');
            }
            if ($received) {
                $this->clearTransferSource($receipt->transferId, $userId, $sessionId, $cartEpoch, self::RECEIVED_ROWS_PER_ATTEMPT);
            }
        });
    }

    /**
     * @param Closure(): void $work Attempt that must leave ambiguous writes for durable recovery
     * @throws AgentException When the attempt refuses the frame or fails
     */
    private function runTransferAttempt(Closure $work): void
    {
        try {
            Database::withoutReconnectRetries($work, DatabaseConnectionDefaults::PRIMARY_INDEX, ChangeLogDatabase::CONNECTION_INDEX);
        } catch (AgentException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new AgentException('Failed to deliver a guest cart transfer.', previous: $failure);
        }
    }

    /**
     * Attempts an ordered prefix of assignments within a shared received-source row budget.
     *
     * @param list<int> $transferIds Saved assignments to resume in order
     * @return list<int> Prefix attempted before the budget ran out
     * @throws HilosException When storage, payload construction or delivery fails
     * @throws Throwable When an acknowledged source cannot be cleared
     */
    private function deliverStoredTransfers(array $transferIds): array
    {
        $remaining = self::RECEIVED_ROWS_PER_ATTEMPT;
        foreach ($transferIds as $offset => $transferId) {
            $remaining -= $this->deliverStoredTransfer($transferId, $remaining);
            if ($remaining === 0) {
                return array_slice($transferIds, 0, $offset + 1);
            }
        }

        return $transferIds;
    }

    /**
     * Offers a saved assignment or advances a durable receipt.
     *
     * @param int $transferId Saved assignment to resume
     * @param int $limit Remaining received-source row budget
     * @return int Received source rows locked during this attempt
     * @throws HilosException When storage, payload construction or delivery fails
     * @throws Throwable When an acknowledged source cannot be cleared
     */
    private function deliverStoredTransfer(int $transferId, int $limit): int
    {
        $stored = Hilos::$db->shopGuestCartTransfers->storedTransfer($transferId);
        if ($stored === null) {
            return 0;
        }
        [$sessionId, $cartEpoch, $userId, $received] = $stored;
        if ($received) {
            return $this->clearTransferSource($transferId, $userId, $sessionId, $cartEpoch, $limit);
        }

        $this->sendToAgent(
            EcommerceShopSignalConstants::GUEST_CART_TRANSFER_OFFER,
            new GuestCartTransferOfferSignalData($transferId, $userId),
        );

        return 0;
    }

    /**
     * @param int $transferId Saved assignment whose receipt permits cleanup
     * @param int $userId Assigned recipient
     * @param int $sessionId Source session
     * @param int $cartEpoch Number of the assigned guest cart
     * @param int $limit Remaining received-source row budget
     * @return int Source rows locked from this exact pair
     * @throws HilosException When storage, payload construction or delivery fails
     * @throws Throwable When the cleanup transaction or its announcement fails
     */
    private function clearTransferSource(int $transferId, int $userId, int $sessionId, int $cartEpoch, int $limit): int
    {
        $cleared = new GuestCartTransferClearedSignalData($transferId, $userId);
        return Hilos::$db->sessionProductCarts->actions->clearReceivedCart(
            $sessionId,
            $cartEpoch,
            $transferId,
            $userId,
            $limit,
            fn () => $this->sendToAgent(EcommerceShopSignalConstants::GUEST_CART_TRANSFER_CLEARED, $cleared),
        );
    }

    /**
     * Advances past the attempted keys, including pending assignments whose source is missing.
     *
     * @param list<int> $ids Attempted durable keys in ascending order
     * @param int $pageSize Number of keys selected before applying the line budget
     */
    private function advanceTransferRecovery(array $ids, int $pageSize): void
    {
        $count = count($ids);
        if ($count === $pageSize && ($count < self::TRANSFERS_PER_PAGE || $ids[$count - 1] === $this->recoveryThroughTransferId)) {
            $this->recoveryAfterTransferId = 0;
            $this->recoveryThroughTransferId = null;
        } else {
            $this->recoveryAfterTransferId = $ids[$count - 1];
        }
    }

    /**
     * Reads bounded cart and transfer pages, advancing after the transactions and receipt complete.
     *
     * @param CronRule $rule Current recovery rule
     * @param bool $scheduled Whether the rule fired on this tick
     * @throws HilosException When a read, write, announcement or receipt fails
     * @throws Throwable When a recovery transaction cannot complete
     */
    private function recoverCartPage(CronRule $rule, bool $scheduled): void
    {
        $this->recoveryHorizons ??= Hilos::$db->sessionProductCarts->cartRecoveryHorizons();
        [$throughLineId, $throughVoucherId] = $this->recoveryHorizons;
        [$lineAbsences, $voucherAbsences] = Hilos::$db->sessionProductCarts->cartRowAbsencesAfter(
            $this->recoveryAfterLineId,
            $throughLineId,
            $this->recoveryAfterVoucherId,
            $throughVoucherId,
            self::RECOVERY_ROWS_PER_TABLE,
        );
        $orphanLineIds = array_keys(array_filter($lineAbsences));
        $orphanVoucherIds = array_keys(array_filter($voucherAbsences));
        $transfers = Hilos::$db->shopGuestCartTransfers;
        $this->recoveryThroughTransferId ??= $transfers->latestStoredTransferId();
        $transferIds = $transfers->storedTransferIdsAfter(self::TRANSFERS_PER_PAGE, $this->recoveryAfterTransferId, $this->recoveryThroughTransferId);
        $transferPageSize = count($transferIds);
        $hasOrphans = $orphanLineIds !== [] || $orphanVoucherIds !== [];
        if ($hasOrphans || $transferIds !== []) {
            $receipt = $scheduled
                ? JournalReceiptData::cron($rule->name, $rule->expression, $this->getId())
                : JournalReceiptData::agent(self::RECOVERY_RULE, $this->getId());
            $transferIds = JournalReceiptScope::run(
                $receipt,
                function () use ($hasOrphans, $orphanLineIds, $orphanVoucherIds, $transferIds): array {
                    if ($hasOrphans) {
                        Hilos::$db->sessionProductCarts->actions->sweepRecoveryRows($orphanLineIds, $orphanVoucherIds);
                    }

                    return $this->deliverStoredTransfers($transferIds);
                },
            );
        }

        $this->advanceTransferRecovery($transferIds, $transferPageSize);
        $this->recoveryAfterLineId = count($lineAbsences) === self::RECOVERY_ROWS_PER_TABLE ? array_key_last($lineAbsences) : $throughLineId;
        $this->recoveryAfterVoucherId = count($voucherAbsences) === self::RECOVERY_ROWS_PER_TABLE
            ? array_key_last($voucherAbsences)
            : $throughVoucherId;
        if ($this->recoveryAfterLineId === $throughLineId && $this->recoveryAfterVoucherId === $throughVoucherId) {
            $this->recoveryAfterLineId = 0;
            $this->recoveryAfterVoucherId = 0;
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
        try {
            Hilos::$db->sessionProductCarts->actions->sweepForSessions($sessionIds);
        } catch (HilosException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new LogicException('Failed to sweep guest carts', previous: $failure);
        }
    }

    /**
     * Writes the original guest session's cart and hands back the committed outcome.
     *
     * @param CartAddProductSignalData $ask Product and acting session from the storefront
     * @throws InvalidFormatException When the outcome has no original product address
     * @throws InvalidArgumentException When the outcome cannot be queued
     */
    private function addProductToCart(CartAddProductSignalData $ask): void
    {
        $refusal = null;
        try {
            $connection = Hilos::$rt->connections[$ask->acceptKey];
            $token = $connection?->sessionToken;
            if ($ask->cartEpoch === null || $token === null || $connection->staleSince() !== null
                || $connection->sessionId !== $ask->sessionId || $connection->userId !== null
            ) {
                throw new ValidationException(CartAddProductSignalData::CART_NOT_READY);
            }

            SessionProductCartsActions::inPrimaryTransaction(function () use ($ask, $token): void {
                Hilos::$db->sessionProductCarts->actions->add($ask->sessionId, $ask->cartEpoch, $ask->productId, $token);
                $this->publishCart($ask->sessionId, $ask->cartEpoch);
            });
        } catch (WiringRefusal $failure) {
            $refusal = ActionRefusal::fromThrowable($failure);
        } catch (Throwable $failure) {
            $refusal = ActionRefusal::fromThrowable($failure);
            if ($refusal->isInternal()) {
                $this->logAgentError("Guest cart add failed for session #{$ask->sessionId}: {$failure->getMessage()}");
            }
        }

        $done = new CartAddProductDoneSignalData($ask->productSlug, HandoverAnswerSignalData::to($ask, $refusal));
        Database::afterCommit(fn () => $this->sendToAgent($ask->replySignal, $done), DatabaseConnectionDefaults::PRIMARY_INDEX);
    }

    /**
     * Sends a committed snapshot only while the addressed guest cart is still active.
     *
     * @param int $sessionId Row of the session whose cart is told
     * @param int $cartEpoch Number of the cart joined by those tabs
     * @throws InvalidArgumentException When the frame cannot be named or queued outside a transaction
     * @throws HilosException When the collection refuses the read
     */
    private function publishCart(int $sessionId, int $cartEpoch): void
    {
        Database::afterCommit(function () use ($sessionId, $cartEpoch): void {
            if (Hilos::$db->sessions->activeGuestCartEpoch($sessionId) !== $cartEpoch) {
                return;
            }

            $lines = [];
            foreach (Hilos::$db->sessionProductCarts->storedCartLines($sessionId, $cartEpoch) as [$productId, $quantity]) {
                $lines[] = [CartStateSignalData::PRODUCT_ID => $productId, CartStateSignalData::QUANTITY => $quantity];
            }
            $frame = new CartStateSignalData(null, $lines, Hilos::$db->sessionVoucherCarts->storedVoucherId($sessionId, $cartEpoch));

            if (Hilos::$db->sessions->activeGuestCartEpoch($sessionId) === $cartEpoch) {
                $this->sendToGroup(EcommerceShopSignalConstants::CART_STATE, CartGroup::forSession($sessionId, $cartEpoch), $frame);
            }
        }, DatabaseConnectionDefaults::PRIMARY_INDEX);
    }
}
