<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\IdentityCommands;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\PasskeyCredential as EntityPasskeyCredential;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\Object\Collection\PasskeyCredentials as ObjectPasskeyCredentials;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * A passkey leaves as one thing, anchor and crypto half together (HIL-722).
 *
 * A passkey is two rows in two tables with no foreign key between them, so "the
 * passkey is gone" is a statement about both of them and only a real database can
 * answer it. What is pinned here is that unlinking takes the credential out and not
 * just the anchor, that the anchor cannot be taken out alone through the primitive
 * that used to allow it, and that a refusal — a last sign-in method, somebody
 * else's identity — leaves every row where it found it.
 *
 * The lock that keeps a credential orphaned before this cascade from naming an
 * account is NOT pinned here, and deliberately not: reaching it needs an assertion
 * a real authenticator signed, and every refusal below it answers with the same
 * generic ValidationException, so a case written at this level would pass with the
 * lock taken out. It is covered by reading, and the surface it protects is exercised
 * by the passkey e2e.
 */
final class PasskeyUnlinkCascadeIntegrationTest extends FrameworkIntegrationTestCase
{
    /** @var list<string> Framework tables this case needs */
    private const array TABLES = ['hilos_identity', 'hilos_passkey_credential'];

    private const string PASSWORD = 'anchor-secret-42';

    private const string PUBLIC_KEY_PEM = "-----BEGIN PUBLIC KEY-----\nstub\n-----END PUBLIC KEY-----\n";

    private const string ACCEPT_KEY = 'accept-key-of-the-browser-unlinking';

    private const string SESSION_TOKEN = 'b7c1d2e3f405162738495a6b7c8d9e0f';

    private ?DbContext $previousDb = null;

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRt = null;

    /** @var int Rolling source of user ids; a framework table carries no FK to a project user */
    private int $nextUserId = 1;

    /**
     * @throws HilosException When a stub statement fails or the context cannot be configured
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);

        $this->previousDb = Hilos::$db;
        $this->previousSignalRouter = Hilos::$sr;

        $db = new PasskeyUnlinkTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$sr = new SignalRouter();

        $this->previousRt = Hilos::$rt;
        $rt = new PasskeyUnlinkTestRtContext();
        $rt->configure();
        Hilos::$rt = $rt;
    }

    /**
     * @throws HilosException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$db = $this->previousDb;

        self::runStubs(down: true);

        parent::tearDown();
    }

    /**
     * The crypto half goes when its identity is named, and nobody else's does.
     *
     * @throws HilosException When an identity or credential query or write fails
     */
    public function testDeletingByIdentityTakesOutThatCredentialAlone(): void
    {
        $userId = $this->nextUserId();
        $doomed = $this->seedPasskey($userId);
        $kept = $this->seedPasskey($userId);

        $this->passkeyCredentials()->deleteByIdentity($doomed);

        self::assertNull($this->storedCredentialId($doomed));
        self::assertNotNull($this->storedCredentialId($kept));
    }

    /**
     * An identity that never had a credential is not an error to cascade over.
     *
     * @throws HilosException When an identity or credential query or write fails
     */
    public function testDeletingByIdentityWithoutCredentialDoesNothing(): void
    {
        $identityId = $this->identities()
            ->createPasswordIdentity($this->nextUserId(), $this->uniqueEmail(), self::PASSWORD)
            ->id;
        self::assertNotNull($identityId);

        $this->passkeyCredentials()->deleteByIdentity($identityId);

        self::assertNotNull($this->identities()->get($identityId));
    }

    /**
     * Deleting a passkey anchor around the cascade is refused, so no orphan is minted.
     *
     * @throws HilosException When an identity or credential query or write fails
     */
    public function testDeletingAPasskeyIdentityDirectlyIsRefusedWhileItsCredentialIsStored(): void
    {
        $userId = $this->nextUserId();
        $this->identities()->createPasswordIdentity($userId, $this->uniqueEmail(), self::PASSWORD);
        $identityId = $this->seedPasskey($userId);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            "cannot delete passkey identity {$identityId} directly: its passkey credential is still stored",
        );
        $this->identities()->deleteIdentity($userId, $identityId);
    }

    /**
     * With the credential already gone the same anchor deletes like any other identity.
     *
     * @throws HilosException When an identity or credential query or write fails
     */
    public function testDeletingAPasskeyIdentityPassesOnceItsCredentialIsGone(): void
    {
        $userId = $this->nextUserId();
        $this->identities()->createPasswordIdentity($userId, $this->uniqueEmail(), self::PASSWORD);
        $identityId = $this->seedPasskey($userId);

        $this->passkeyCredentials()->deleteByIdentity($identityId);
        $this->identities()->deleteIdentity($userId, $identityId);

        self::assertCount(1, $this->identities()->listByUser($userId));
    }

    /**
     * The door takes both halves of a passkey out in one call.
     *
     * @throws HilosException When an identity or credential query or write fails
     */
    public function testUnlinkingAPasskeyRemovesTheAnchorAndTheCredential(): void
    {
        $userId = $this->nextUserId();
        $this->identities()->createPasswordIdentity($userId, $this->uniqueEmail(), self::PASSWORD);
        $identityId = $this->seedPasskey($userId);
        $this->connect($userId);

        $this->unlinkCommands()->unlink(self::ACCEPT_KEY, $identityId);

        self::assertNull($this->storedCredentialId($identityId));
        self::assertCount(1, $this->identities()->listByUser($userId));
    }

    /**
     * A method that has no crypto half leaves every stored credential where it was.
     *
     * @throws HilosException When an identity or credential query or write fails
     */
    public function testUnlinkingAPasswordLeavesTheStoredCredentialsAlone(): void
    {
        $userId = $this->nextUserId();
        $passwordId = $this->identities()->createPasswordIdentity($userId, $this->uniqueEmail(), self::PASSWORD)->id;
        self::assertNotNull($passwordId);
        $passkeyId = $this->seedPasskey($userId);
        $this->connect($userId);

        $this->unlinkCommands()->unlink(self::ACCEPT_KEY, $passwordId);

        self::assertNotNull($this->storedCredentialId($passkeyId));
        self::assertCount(1, $this->identities()->listByUser($userId));
    }

    /**
     * The refusal on a last sign-in method is spoken before anything is written.
     *
     * @throws HilosException When an identity or credential query or write fails
     */
    public function testUnlinkingTheOnlySignInMethodRefusesWithoutTouchingARow(): void
    {
        $userId = $this->nextUserId();
        $identityId = $this->seedPasskey($userId);
        $this->connect($userId);

        try {
            $this->unlinkCommands()->unlink(self::ACCEPT_KEY, $identityId);
            self::fail('unlinking the only sign-in method should have been refused');
        } catch (ValidationException) {
            self::assertNotNull($this->storedCredentialId($identityId));
            self::assertCount(1, $this->identities()->listByUser($userId));
        }
    }

    /**
     * Naming somebody else's passkey costs that account nothing.
     *
     * The refusal belongs to the primitive and arrives after the cascade would have run,
     * so the cascade asks whose identity this is first; without that, an id anyone can
     * guess would delete a stranger's credential and refuse afterwards.
     *
     * @throws HilosException When an identity or credential query or write fails
     */
    public function testUnlinkingAForeignPasskeyLeavesItsCredentialStored(): void
    {
        $strangerId = $this->nextUserId();
        $strangerPasskey = $this->seedPasskey($strangerId);

        $userId = $this->nextUserId();
        $this->identities()->createPasswordIdentity($userId, $this->uniqueEmail(), self::PASSWORD);
        $this->seedPasskey($userId);
        $this->connect($userId);

        try {
            $this->unlinkCommands()->unlink(self::ACCEPT_KEY, $strangerPasskey);
            self::fail('unlinking a foreign identity should have been refused');
        } catch (ValidationException) {
            self::assertNotNull($this->storedCredentialId($strangerPasskey));
            self::assertCount(1, $this->identities()->listByUser($strangerId));
        }
    }

    /**
     * Registers a passkey the way the ceremony does: anchor row first, crypto row after.
     *
     * @param int $userId Owning user id
     * @return int Id of the anchor identity row
     * @throws HilosException When an identity or credential write fails
     */
    private function seedPasskey(int $userId): int
    {
        $credentialId = RandomHelper::hex(16);
        $identityId = $this->identities()->createPasskeyIdentity($userId, $credentialId)->id;
        self::assertNotNull($identityId);

        $this->passkeyCredentials()->createFromRegistration(
            $identityId,
            $userId,
            $credentialId,
            self::PUBLIC_KEY_PEM,
            PasskeyAlgorithm::Es256,
            0,
            null,
            null,
            RandomHelper::hex(16),
            null,
        );

        return $identityId;
    }

    /**
     * Reads the credential row of an identity out of the table, past any object in memory.
     *
     * @param int $identityId Owning `hilos_identity` anchor row id
     * @return ?string Stored credential id, or null when the table holds no such row
     * @throws HilosException When the credential query fails
     */
    private function storedCredentialId(int $identityId): ?string
    {
        return EntityPasskeyCredential::get([
            EntityPasskeyCredential::identity_id => $identityId,
        ])->first()?->credential_id;
    }

    /**
     * Puts the acting browser on the runtime, which is where a command reads it from.
     *
     * @param int $userId User signed in on that browser
     */
    private function connect(int $userId): void
    {
        /** @var PasskeyUnlinkTestRtContext $rt */
        $rt = Hilos::$rt;
        $rt->connections()->add(
            PasskeyUnlinkTestConnection::create(self::ACCEPT_KEY, $userId, self::SESSION_TOKEN),
        );
    }

    /**
     * @return IdentityCommands The unlink door, on a library that answers for nothing else
     */
    private function unlinkCommands(): IdentityCommands
    {
        return new IdentityCommands(new PasskeyUnlinkTestLibrary());
    }

    /**
     * @return ObjectIdentities Identity persistence primitives
     * @throws HilosException When the collection is unavailable
     */
    private function identities(): ObjectIdentities
    {
        /** @var ObjectIdentities $collection */
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::identities);

        return $collection;
    }

    /**
     * @return ObjectPasskeyCredentials Passkey sidecar persistence primitives
     * @throws HilosException When the collection is unavailable
     */
    private function passkeyCredentials(): ObjectPasskeyCredentials
    {
        /** @var ObjectPasskeyCredentials $collection */
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::passkeyCredentials);

        return $collection;
    }

    /**
     * @return int A user id no other account in this case uses
     */
    private function nextUserId(): int
    {
        return $this->nextUserId++;
    }

    /**
     * @return string Unique lowercase address for one account
     */
    private function uniqueEmail(): string
    {
        return RandomHelper::hex(8) . '@example.test';
    }

    /**
     * Runs one direction of the stub file of every table this case uses.
     *
     * @param bool $down Run the down (drop) stubs when true, the create stubs when false
     * @throws HilosException When a stub statement fails
     */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        foreach (self::TABLES as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/**
 * The smallest concrete connection row: the framework session triple and nothing else.
 *
 * It stands on the session stage because that is the stage a command resolves its acting
 * browser from, and a row with nothing of its own is what the simple demos declare.
 */
final class PasskeyUnlinkTestConnection extends HilosSessionConnection
{
    /**
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return PasskeyUnlinkTestRtContext::connections;
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
 * @extends HilosSessionConnections<PasskeyUnlinkTestConnection>
 */
final class PasskeyUnlinkTestConnections extends HilosSessionConnections
{
    public const string STATE_CLASS = PasskeyUnlinkTestConnection::class;
}

/**
 * A runtime context whose connections extend the framework base, as demo/chat does.
 */
final class PasskeyUnlinkTestRtContext extends RtContext
{
    public const string connections = 'connections';

    /**
     * Mounts the one collection these cases need: the project's live connections.
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = PasskeyUnlinkTestConnections::init();
    }

    /**
     * @return PasskeyUnlinkTestConnections Live connections of this context
     */
    public function connections(): PasskeyUnlinkTestConnections
    {
        /** @var PasskeyUnlinkTestConnections $connections */
        $connections = $this->_stateCollections[self::connections];

        return $connections;
    }
}

/**
 * A library agent standing in for a project's, carrying no project of its own.
 *
 * The unlink door needs a library because every command group is built on one, and it
 * reads nothing off this one: the acting browser comes from the runtime and the two rows
 * come from the database. The three project seams are therefore unreachable from these
 * cases, and each says so rather than answering with a value nobody chose.
 */
final class PasskeyUnlinkTestLibrary extends AbstractUsersLibraryAgent
{
    /**
     * @return string Collection the project's user rows would live in
     */
    protected function usersCollection(): string
    {
        return 'users';
    }

    /**
     * @param string $displayName Name the new account would be created with
     * @return int Never returns
     * @throws LogicException Always: these cases unlink from accounts that already exist
     */
    public function createUser(string $displayName): int
    {
        throw new LogicException('the unlink cases register nobody');
    }

    /**
     * @param int $userId Account to name
     * @return ?string Never returns
     * @throws LogicException Always: unlink shows no account name
     */
    public function displayNameOf(int $userId): ?string
    {
        throw new LogicException('the unlink cases name nobody');
    }

    /**
     * @return IdentifierDetector Detector over no wired methods: no identifier is detected here
     */
    protected function buildAuthMethods(): IdentifierDetector
    {
        return new IdentifierDetector([]);
    }
}

/**
 * A framework database context with nothing but the framework's own collections.
 *
 * Both tables this case touches are framework-owned, so the smallest honest context
 * for them is {@see HilosDbContext} with no project collections.
 */
final class PasskeyUnlinkTestDbContext extends HilosDbContext
{
}
