<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Item\IdentityActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\View\Collection\Identities;
use Hilos\Database\View\Item\Identity;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The real item guard runs; only ORM persistence is replaced in these database-free cases. */
final class IdentityActionsTest extends TestCase
{
    private const string OWNER = 'identity-metadata-test';

    protected function setUp(): void
    {
        parent::setUp();
        TruthSourceRegistry::register(
            HilosDbContext::identities,
            TruthSourceKeys::all(),
            self::OWNER,
            TruthSourceOperations::of(TruthSourceOperation::Update),
        );
    }

    protected function tearDown(): void
    {
        TruthSourceRegistry::unregister(HilosDbContext::identities, self::OWNER);
        parent::tearDown();
    }

    public function testPasswordChangeRecordsTheCurrentEpochMilliseconds(): void
    {
        $identity = $this->identity(IdentityType::PASSWORD);
        $before = TimeHelper::nowMs();

        $identity->actions->recordPasswordChange();

        $this->assertGreaterThanOrEqual($before, $identity->passwordChangedAt);
        $this->assertLessThanOrEqual(TimeHelper::nowMs(), $identity->passwordChangedAt);
        $this->assertSame(1, $identity->getObject()->syncs);
    }

    public function testAProviderNameIsTrimmedAndSavedOnlyWhenItChanges(): void
    {
        $identity = $this->identity(IdentityType::OAUTH);
        $identity->actions->rememberProviderName('  Reader Name  ');

        $this->assertSame('Reader Name', $identity->providerName);
        $this->assertSame(1, $identity->getObject()->syncs);

        foreach ([null, '', " \t\n ", ' Reader Name '] as $unchanged) {
            $identity->actions->rememberProviderName($unchanged);
        }
        $this->assertSame('Reader Name', $identity->providerName);
        $this->assertSame(1, $identity->getObject()->syncs);

        $longName = str_repeat('Name ', 100) . 'Last';
        $identity->actions->rememberProviderName($longName);
        $this->assertSame($longName, $identity->providerName);
        $this->assertSame(2, $identity->getObject()->syncs);
    }

    /** @return iterable<string, array{string, string}> Action and an incompatible identity type */
    public static function incompatibleTypes(): iterable
    {
        yield 'OAuth has no password moment' => ['recordPasswordChange', IdentityType::OAUTH];
        yield 'password has no provider name' => ['rememberProviderName', IdentityType::PASSWORD];
    }

    /**
     * @param string $action Metadata mutation
     * @param string $type Incompatible identity type
     */
    #[DataProvider('incompatibleTypes')]
    public function testMetadataCannotBeWrittenToAnotherIdentityType(string $action, string $type): void
    {
        $identity = $this->identity($type);
        try {
            $identity->actions->$action(...($action === 'rememberProviderName' ? ['Reader'] : []));
            $this->fail('Metadata must be limited to its identity type');
        } catch (ValidationException) {
            $this->assertNull($identity->passwordChangedAt);
            $this->assertNull($identity->providerName);
            $this->assertSame(0, $identity->getObject()->syncs);
        }
    }

    /** @return iterable<string, array{string, string}> Action and the identity type it edits */
    public static function actions(): iterable
    {
        yield 'password change' => ['recordPasswordChange', IdentityType::PASSWORD];
        yield 'provider name' => ['rememberProviderName', IdentityType::OAUTH];
    }

    /**
     * @param string $action Metadata mutation
     * @param string $type Identity type the action accepts
     */
    #[DataProvider('actions')]
    public function testBothActionsRequireAnUpdateGrant(string $action, string $type): void
    {
        $identity = $this->identity($type);
        TruthSourceRegistry::unregister(HilosDbContext::identities, self::OWNER);
        TruthSourceRegistry::register(
            HilosDbContext::identities,
            TruthSourceKeys::all(),
            self::OWNER,
            TruthSourceOperations::of(TruthSourceOperation::Add),
        );
        try {
            $identity->actions->$action(...($action === 'rememberProviderName' ? ['Reader'] : []));
            $this->fail('An add grant must not permit metadata updates');
        } catch (WriteNotAllowedException) {
            $this->assertNull($identity->passwordChangedAt);
            $this->assertNull($identity->providerName);
            $this->assertSame(0, $identity->getObject()->syncs);
        }
    }

    /**
     * @param string $action Metadata mutation
     * @param string $type Identity type the action accepts
     */
    #[DataProvider('actions')]
    public function testBothActionsRefuseAnUnpersistedIdentity(string $action, string $type): void
    {
        TruthSourceRegistry::unregister(HilosDbContext::identities, self::OWNER);
        TruthSourceRegistry::register(HilosDbContext::identities, TruthSourceKeys::all(), self::OWNER);
        $identity = $this->identity($type, persisted: false);

        $this->expectException(ItemNotFoundForUpdateException::class);
        $identity->actions->$action(...($action === 'rememberProviderName' ? ['Reader'] : []));
    }

    /**
     * @param string $type Sign-in method type
     * @param bool $persisted Whether the row has been stored
     * @return Identity Item attached to a real collection for the write guard
     */
    private function identity(string $type, bool $persisted = true): Identity
    {
        $object = $persisted ? MetadataIdentityObject::fromEntity(EntityIdentity::fromRow([
            'id' => 7,
            'user_id' => 42,
            'type' => $type,
            'identifier' => 'reader@example.test',
            'provider' => null,
            'verified' => true,
            'password_changed_at' => null,
            'provider_name' => null,
        ])) : MetadataIdentityObject::create();
        $object->type = $type;
        $object->userId = 42;
        $collection = Identities::initEmpty();
        $collection->setObjectCollection(ObjectIdentities::initEmpty());
        $identity = new Identity($object);
        $identity->setCollection($collection);
        $identity->setActionsClass(IdentityActions::class);
        return $identity;
    }
}

final class MetadataIdentityObject extends ObjectIdentity
{
    public int $syncs = 0;

    /** Records a persistence attempt without SQL or source announcements. */
    public function sync(): void
    {
        $this->syncs++;
    }
}
