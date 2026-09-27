<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\Anonymization\AnonymizationCoverageValidator;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Entity\Item\VerifierCircleMember as EntityVerifierCircleMember;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Schema\EntitySchemaMismatch;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Schema\SetOwnershipGuard;
use Hilos\Database\SqlParamCollection;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Tests\Unit\Database\Fixtures\Extension\NotedMemberActions;
use Hilos\Tests\Unit\Database\Fixtures\Extension\NotedMemberEntity;
use Hilos\Tests\Unit\Database\Fixtures\Extension\NotedMemberItem;
use Hilos\Tests\Unit\Database\Fixtures\Extension\NotedMemberObjects;
use Hilos\Tests\Unit\Database\Fixtures\Extension\NotedMembers;
use Hilos\Tests\Unit\Database\Fixtures\Extension\NotedMembersDbContext;

/**
 * A project's chain over a framework table, end to end (HIL-1190): mounted under the framework's
 * key, written and read through the framework's own action and finder with the project's column
 * along, and seen by the three gates that judge a mounted class - the schema audit, the
 * anonymization registry and the set-ownership guard.
 *
 * The chain is the test one over the verifier circle
 * ({@see NotedMembersDbContext}), and the column is added to the stub table here, the way a
 * project's migration would add it: the framework's own migrations are not touched.
 */
final class FrameworkEntityExtensionIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string WRITER_ID = 'framework-entity-extension-test';

    private const string STUB_UP = 'create_hilos_verifier_circle.sql';

    private const string STUB_DOWN = 'create_hilos_verifier_circle_down.sql';

    private const string EMAIL_TYPE = 'password';

    private const string ANN_EMAIL = 'ann@example.test';

    private const string ANN_NOTE = 'named on the day the system came back';

    /** @var ?DbContext Database context to restore after the test */
    private ?DbContext $previousDb = null;

    /** @var ?SignalRouter Signal router to restore after the test */
    private ?SignalRouter $previousSignalRouter = null;

    /**
     * @throws HilosException When the table, the column or the context cannot be set up
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStub(self::STUB_DOWN);
        self::runStub(self::STUB_UP);
        Database::sqlRun(
            'ALTER TABLE `' . EntityVerifierCircleMember::_table . '` ADD COLUMN `' . NotedMemberEntity::note
            . '` VARCHAR(64) NULL',
        );
        Schema::reset();
        Schema::initialize();

        TruthSourceRegistry::register(HilosDbContext::verifierCircle, TruthSourceKeys::all(), self::WRITER_ID);
        $this->previousDb = Hilos::$db;
        Hilos::$db = self::mountedContext();
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::reset();
    }

    /**
     * @throws HilosException When dropping the stub table fails
     */
    protected function tearDown(): void
    {
        SourceChangeBus::reset();
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$db = $this->previousDb;
        TruthSourceRegistry::unregisterAgent(self::WRITER_ID);
        self::runStub(self::STUB_DOWN);
        Schema::reset();

        parent::tearDown();
    }

    public function testTheChainIsMountedUnderTheFrameworkKey(): void
    {
        $this->assertInstanceOf(NotedMembers::class, Hilos::$db->getDbItemCollection(HilosDbContext::verifierCircle));
        $this->assertInstanceOf(
            NotedMemberObjects::class,
            Hilos::$db->mountedObjectCollection(HilosDbContext::verifierCircle),
        );
    }

    /**
     * The framework's own collection action creates the row and hands back the project's item,
     * the project's column is written by the framework's save path and read back by the
     * framework's finder in a fresh context - which is the sign that the column reached the
     * table and not only the object in memory.
     *
     * @throws HilosException When a step against the database fails
     */
    public function testTheFrameworkWritesAndReadsTheProjectColumn(): void
    {
        $member = Hilos::$db->verifierCircle->actions->add(self::EMAIL_TYPE, self::ANN_EMAIL);

        $this->assertInstanceOf(NotedMemberItem::class, $member);
        $this->assertInstanceOf(NotedMemberActions::class, $member->actions);
        $this->assertNull($member->note, 'A fresh row carries the project column empty');

        $object = $member->getObject();
        $object->note = self::ANN_NOTE;
        $object->sync();

        Database::sql(
            'SELECT `' . NotedMemberEntity::note . '` FROM `' . EntityVerifierCircleMember::_table
            . '` WHERE `' . EntityVerifierCircleMember::identifier . '` = ?',
            SqlParamCollection::fromArray([self::ANN_EMAIL]),
        );
        $this->assertSame(
            [[NotedMemberEntity::note => self::ANN_NOTE]],
            Database::rows(),
            'The project column is written by the framework save path',
        );

        $readBack = self::mountedContext()->verifierCircle->findByIdentity(self::EMAIL_TYPE, self::ANN_EMAIL);

        $this->assertInstanceOf(NotedMemberItem::class, $readBack);
        $this->assertSame(self::ANN_NOTE, $readBack->note, 'The framework finder reads the project column back');
    }

    /**
     * @throws HilosException When an introspection query fails
     */
    public function testTheSchemaAuditReadsTheMountedClass(): void
    {
        $this->assertSame(NotedMemberEntity::class, EntitySchemaAudit::mountedClassOf(EntityVerifierCircleMember::class));
        $this->assertSame(
            EntityIdentity::class,
            EntitySchemaAudit::mountedClassOf(EntityIdentity::class),
            'A framework Entity nobody extended answers for itself',
        );

        $mismatches = EntitySchemaAudit::audit([NotedMemberEntity::class]);

        $this->assertSame([], $mismatches, implode(PHP_EOL, array_map(
            static fn(EntitySchemaMismatch $mismatch): string => $mismatch->describe(),
            $mismatches,
        )));
    }

    /**
     * @throws HilosException When the registry cannot be collected or the live schema read
     */
    public function testTheRegistryCarriesTheProjectVerdict(): void
    {
        $registry = PiiRegistry::collect();
        $strategies = $registry->strategiesFor(DatabaseConnectionDefaults::PRIMARY_INDEX, EntityVerifierCircleMember::_table);

        $this->assertNotNull($strategies);
        $this->assertSame(AnonymizationStrategy::NULLIFY, $strategies[NotedMemberEntity::note] ?? null);
        $this->assertSame(
            AnonymizationStrategy::FAKE_EMAIL,
            $strategies[EntityVerifierCircleMember::identifier] ?? null,
            'The base verdicts are inherited beside the project one',
        );

        $schemas = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX);
        AnonymizationCoverageValidator::validateLiveSchema($registry, [
            DatabaseConnectionDefaults::PRIMARY_INDEX => [
                EntityVerifierCircleMember::_table => $schemas[EntityVerifierCircleMember::_table],
            ],
        ]);
        AnonymizationCoverageValidator::validateArchiveTables($registry, [
            DatabaseConnectionDefaults::PRIMARY_INDEX => [EntityVerifierCircleMember::_table],
        ]);
    }

    /**
     * The guard reads the set declaration off the mounted class, which inherits it whole.
     *
     * @throws HilosException When a mounted table declares no set - which is the failure
     */
    public function testTheSetOwnershipGuardIsSilent(): void
    {
        SetOwnershipGuard::assertMountedSetsDeclared();

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return NotedMembersDbContext A configured context with the test chain under the verifier circle key
     * @throws HilosException When the context refuses to configure
     */
    private static function mountedContext(): NotedMembersDbContext
    {
        $context = new NotedMembersDbContext();
        $context->configure();

        return $context;
    }

    /**
     * Runs one of the circle table's stub files.
     *
     * @param string $stub File name of the stub, create or drop
     * @throws HilosException When the stub statement fails
     */
    private static function runStub(string $stub): void
    {
        Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/' . $stub));
    }
}
