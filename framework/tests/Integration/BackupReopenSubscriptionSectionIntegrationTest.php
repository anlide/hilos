<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Pages\Backup\AbstractHilosBackupPage;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\TestCase;

/**
 * Who is offered the button that ends a verification window (HIL-676).
 *
 * The reopen block is the first thing a framework page answers PERSONALLY: the same page, at the
 * same moment, tells the browser that started the restore it may reopen the system and tells every
 * other browser nothing at all. So what is pinned here is the whole road from the accept key the
 * subscription carries to the verdict in the payload — the connection registry translating that key
 * into a session token, the one hashing door the freeze row compares through, and the row's own
 * {@see StateProtectedModeRuntime::belongsToInitiator()}.
 *
 * The pieces are the real ones rather than a page with the answer handed to it, because every way
 * this can be wrong lives BETWEEN them: an accept key that names no connection, a connection
 * carrying no session, a freeze entered from a terminal that recorded no browser at all. Each of
 * those reads false, and a double asserting `false === false` would prove none of it.
 */
final class BackupReopenSubscriptionSectionIntegrationTest extends TestCase
{
    /** Session cookie of the browser that asked for the restore, in the minted token form. */
    private const string INITIATOR_SESSION_TOKEN = '0123456789abcdef0123456789abcdef';

    /** Session cookie of another browser watching the same node, same form and a different value. */
    private const string STRANGER_SESSION_TOKEN = 'fedcba9876543210fedcba9876543210';

    private const string INITIATOR_ACCEPT_KEY = 'accept-initiator';

    private const string STRANGER_ACCEPT_KEY = 'accept-stranger';

    private ?RtContext $previousRt = null;

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousRt = Hilos::$rt;
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();
        putenv('APP_ENV=test');
    }

    protected function tearDown(): void
    {
        Hilos::$rt = $this->previousRt;
        Hilos::$env = $this->previousEnv;
        putenv('APP_ENV');

        parent::tearDown();
    }

    /**
     * The one case the block exists for: the node stands in the verification window a restore left
     * it in, and the browser asking for the page is the browser that started that restore.
     */
    public function testTheBrowserThatStartedTheRestoreIsOfferedTheBlock(): void
    {
        $this->mount(StateProtectedModeRuntime::PHASE_VERIFYING, self::INITIATOR_SESSION_TOKEN);

        $this->assertTrue($this->offeredTo(self::INITIATOR_ACCEPT_KEY));
    }

    /**
     * The half that makes the section worth being personal: a second admin looking at the same
     * shuttered node subscribes to the same page and is told nothing about reopening it.
     */
    public function testAnotherBrowserOnTheSameNodeIsNotOfferedIt(): void
    {
        $this->mount(StateProtectedModeRuntime::PHASE_VERIFYING, self::INITIATOR_SESSION_TOKEN);

        $this->assertFalse($this->offeredTo(self::STRANGER_ACCEPT_KEY));
    }

    /**
     * Outside the window the block is absent rather than inert, and this is where that is decided:
     * under `active` the very same browser gets `false`, so nothing downstream has to know that a
     * disabled button would have been a lie about an action that does not exist yet.
     */
    public function testTheInitiatorIsNotOfferedItWhileTheNodeIsStillFrozen(): void
    {
        $this->mount(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_SESSION_TOKEN);

        $this->assertFalse($this->offeredTo(self::INITIATOR_ACCEPT_KEY));
    }

    /**
     * A restore started from a terminal or a schedule records no browser session on the row, and
     * then there is nobody to offer the block to - which is also what closes production without a
     * condition of its own, since browser restore does not exist there at all.
     */
    public function testAFreezeWithNoBrowserBehindItOffersTheBlockToNobody(): void
    {
        $this->mount(StateProtectedModeRuntime::PHASE_VERIFYING, initiatorSessionToken: null);

        $this->assertFalse($this->offeredTo(self::INITIATOR_ACCEPT_KEY));
        $this->assertFalse($this->offeredTo(self::STRANGER_ACCEPT_KEY));
    }

    /**
     * An installation that never mounted the freeze row - protected mode not activated as a feature
     * - answers the section rather than omitting it, because the client reads a missing section and
     * a false one the same way and only one of them is a shape the page can promise.
     */
    public function testANodeWithNoFreezeRowAnswersTheSectionAsFalse(): void
    {
        Hilos::$rt = new BackupReopenSectionTestRtContext();
        Hilos::$rt->configure();
        $this->connect(self::INITIATOR_ACCEPT_KEY, self::INITIATOR_SESSION_TOKEN);

        $this->assertFalse($this->offeredTo(self::INITIATOR_ACCEPT_KEY));
    }

    /**
     * The accept key names no connection at all - the row went before the payload was built, or the
     * key belongs to a socket the registry never saw. There is no session to compare, and the
     * answer is the refusal rather than a match against null.
     */
    public function testAnAcceptKeyNamingNoConnectionIsOfferedNothing(): void
    {
        $this->mount(StateProtectedModeRuntime::PHASE_VERIFYING, self::INITIATOR_SESSION_TOKEN);

        $this->assertFalse($this->offeredTo('accept-nobody'));
    }

    /**
     * Builds the page payload for one subscriber and reads the reopen verdict out of it.
     *
     * @param string $acceptKey Accept key the subscription came in on
     * @return bool Whether that subscriber is offered the reopen block
     */
    private function offeredTo(string $acceptKey): bool
    {
        $payload = new BackupReopenSectionTestPage(new BackupReopenSectionTestAgent())
            ->payloadFor($acceptKey);
        $section = $payload->data[AbstractHilosBackupPage::REOPEN_SECTION] ?? null;
        $this->assertIsArray($section, 'The page answers the reopen section on every subscription');

        $offered = $section[AbstractHilosBackupPage::REOPEN_OFFERED] ?? null;
        $this->assertIsBool($offered, 'The section carries one boolean and nothing else');

        return $offered;
    }

    /**
     * Mounts a runtime holding a freeze row and the two browsers of these cases.
     *
     * @param string $phase Phase the freeze row stands in
     * @param ?string $initiatorSessionToken Session token the row records as the initiator's, or null when none
     */
    private function mount(string $phase, ?string $initiatorSessionToken): void
    {
        $rt = new BackupReopenSectionTestRtContext();
        $rt->configure();
        Hilos::$rt = $rt;
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => $phase,
            StateProtectedModeRuntime::operation => 'restore',
            StateProtectedModeRuntime::initiatorAcceptKey => self::INITIATOR_ACCEPT_KEY,
            StateProtectedModeRuntime::initiatorSessionTokenHash => $initiatorSessionToken === null
                ? null
                : StateProtectedModeRuntime::hashSessionToken($initiatorSessionToken),
            StateProtectedModeRuntime::initiatorAgentType => 'backup',
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
        ]));

        $this->connect(self::INITIATOR_ACCEPT_KEY, self::INITIATOR_SESSION_TOKEN);
        $this->connect(self::STRANGER_ACCEPT_KEY, self::STRANGER_SESSION_TOKEN);
    }

    /**
     * Registers one live connection of a browser session in the runtime collection.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $sessionToken Session cookie token the socket belongs to
     */
    private function connect(string $acceptKey, string $sessionToken): void
    {
        /** @var BackupReopenSectionTestRtContext $rt */
        $rt = Hilos::$rt;
        $rt->connections()->add(BackupReopenSectionTestConnection::create($acceptKey, null, $sessionToken));
    }
}

/**
 * The concrete backup page a project binds, with the payload hook opened for reading.
 */
final class BackupReopenSectionTestPage extends AbstractHilosBackupPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'hilos_index';

    /**
     * Builds what this subscriber would receive in its page_response frame.
     *
     * @param string $acceptKey Accept key the subscription came in on
     * @return PagePayload Page-data payload for that one subscriber
     */
    public function payloadFor(string $acceptKey): PagePayload
    {
        return $this->buildPagePayload($acceptKey, new PageRouteParams([]));
    }
}

/**
 * Minimal page agent: the payload build never reaches it, but the page holds one.
 */
final class BackupReopenSectionTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'hilos_index';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'hilos_index');
    }
}

/**
 * The smallest concrete connection row: the framework session triple and nothing else.
 */
final class BackupReopenSectionTestConnection extends HilosSessionConnection
{
    /**
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return BackupReopenSectionTestRtContext::connections;
    }

    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row (nothing of its own to read)
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> Always empty: the row is the framework base
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Partial update (nothing of its own to apply)
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/**
 * A project connections collection, as every project that has sessions declares one.
 *
 * @extends HilosSessionConnections<BackupReopenSectionTestConnection>
 */
final class BackupReopenSectionTestConnections extends HilosSessionConnections
{
    public const string STATE_CLASS = BackupReopenSectionTestConnection::class;
}

/**
 * A runtime context carrying the project's live connections; the freeze row is mounted by the
 * framework half, exactly as it is in a real installation.
 */
final class BackupReopenSectionTestRtContext extends RtContext
{
    public const string connections = 'connections';

    /**
     * Mounts the one collection these cases need: the project's live connections.
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = BackupReopenSectionTestConnections::init();
    }

    /**
     * @return BackupReopenSectionTestConnections Live connections of this context
     */
    public function connections(): BackupReopenSectionTestConnections
    {
        /** @var BackupReopenSectionTestConnections $connections */
        $connections = $this->_stateCollections[self::connections];

        return $connections;
    }
}
