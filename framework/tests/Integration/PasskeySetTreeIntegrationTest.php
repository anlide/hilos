<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Exception\SqlRuntime\ForeignKeyConstraintException;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\Object\Collection\PasskeyCredentials as ObjectPasskeyCredentials;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * A passkey hangs on its identity anchor, and the anchor on its person (HIL-1111).
 *
 * The credential sits in the set of its identity anchor and the anchor in the set of its person:
 * two floors. The foreign key between the credential and the anchor is what names the credential's
 * parent, so only a real database can say that the key is there and that it holds - and that a
 * claim laid by the person's id reaches the credential through the anchor row as it is stored.
 */
final class PasskeySetTreeIntegrationTest extends FrameworkIntegrationTestCase
{
    /** @var list<string> Framework tables this case needs, each after the table its foreign key names */
    private const array TABLES = ['hilos_identity', 'hilos_passkey_credential'];

    /** Agent id the claim over one person's set is laid under. */
    private const string PERSON_AGENT = 'passkey-set-tree-person-agent';

    private const string PASSWORD = 'anchor-secret-42';

    private const string PUBLIC_KEY_PEM = "-----BEGIN PUBLIC KEY-----\nstub\n-----END PUBLIC KEY-----\n";

    private ?DbContext $previousDb = null;

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
        $db = new PasskeySetTreeTestDbContext();
        $db->configure();
        Hilos::$db = $db;
    }

    /**
     * @throws HilosException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::PERSON_AGENT);
        Hilos::$db = $this->previousDb;

        self::runStubs(down: true);

        parent::tearDown();
    }

    /**
     * A claim laid by the person's id writes their passkey, and not a stranger's, through the anchor.
     *
     * @throws HilosException When an identity or credential query or write fails
     */
    public function testTheClaimOfAPersonWritesTheirPasskeyAndNotAStrangers(): void
    {
        $ownerId = $this->nextUserId();
        $strangerId = $this->nextUserId();
        $this->seedPasskey($ownerId);
        $this->seedPasskey($strangerId);
        [$own] = $this->passkeyCredentials()->listByUser($ownerId);
        [$foreign] = $this->passkeyCredentials()->listByUser($strangerId);

        TruthSourceRegistry::register(
            HilosDbContext::passkeyCredentials,
            TruthSourceKeys::set((string)$ownerId),
            self::PERSON_AGENT,
        );
        ExecutionContext::setCurrentAgentId(self::PERSON_AGENT);

        $own->touchLastUsed();

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("it holds set '{$ownerId}', and the item's set keys are [{$strangerId}].");
        $foreign->touchLastUsed();
    }

    /**
     * The database itself refuses to strand a credential, past every door of the code.
     *
     * @throws HilosException When an identity or credential write fails
     */
    public function testTheDatabaseRefusesToDeleteAnAnchorWhoseCredentialIsStored(): void
    {
        $userId = $this->nextUserId();
        $this->identities()->createPasswordIdentity($userId, $this->uniqueEmail(), self::PASSWORD);
        $identityId = $this->seedPasskey($userId);

        $this->expectException(ForeignKeyConstraintException::class);
        Database::sql('DELETE FROM `' . EntityIdentity::_table . '` WHERE `id` = ?', [$identityId]);
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
     * The drop goes in reverse: a table named by a foreign key cannot be dropped before the
     * table that holds the key.
     *
     * @param bool $down Run the down (drop) stubs when true, the create stubs when false
     * @throws HilosException When a stub statement fails
     */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/**
 * A framework database context with nothing but the framework's own collections.
 *
 * Both tables this case touches are framework-owned, so the smallest honest context
 * for them is {@see HilosDbContext} with no project collections.
 */
final class PasskeySetTreeTestDbContext extends HilosDbContext
{
}
