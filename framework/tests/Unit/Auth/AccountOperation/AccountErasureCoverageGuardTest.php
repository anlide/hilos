<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountDataOwner;
use Hilos\Auth\AccountOperation\AccountDataOwnerDirectory;
use Hilos\Auth\AccountOperation\AccountErasureCoverageGuard;
use Hilos\Auth\AccountOperation\AccountErasureExemption;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\PhpType;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the startup gate that asks who erases a person's rows of every mounted table.
 *
 * Every case mounts the framework's own collections and some of a made-up project's, because the
 * gate reads the collections an installation mounted and the directory its facade names, and
 * nothing else. That the framework's own collections are each answered for exactly once is pinned
 * by {@see AccountErasureParticipantCatalogTest}; here they only have to pass beside the project's.
 */
final class AccountErasureCoverageGuardTest extends TestCase
{
    private string $previousApp;

    /** Remembers the running application, which every case replaces. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
    }

    /** Unmounts the case's context and returns the previous application. */
    protected function tearDown(): void
    {
        try {
            Hilos::$db = null;
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
        } finally {
            parent::tearDown();
        }
    }

    public function testAnInstallationWithoutAccountsIsNotJudged(): void
    {
        $this->expectNotToPerformAssertions();
        CoverageGuardWithoutAccountsHilos::initBrowser();
        $this->mount([CoverageGuardStrays::class]);

        AccountErasureCoverageGuard::assertMountedCollectionsCovered();
    }

    public function testAnInstallationWhoseEveryCollectionIsAnsweredForPasses(): void
    {
        $this->expectNotToPerformAssertions();
        CoverageGuardHilos::initBrowser();
        $this->mount([CoverageGuardNotes::class, CoverageGuardTemplates::class]);

        AccountErasureCoverageGuard::assertMountedCollectionsCovered();
    }

    public function testAMountedProjectCollectionWithNoOwnerAndNoExemptionIsRefused(): void
    {
        CoverageGuardHilos::initBrowser();
        $this->mount([CoverageGuardNotes::class, CoverageGuardTemplates::class, CoverageGuardStrays::class]);

        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('Collection [coverageStrays] has no account erasure participant and no exemption');

        AccountErasureCoverageGuard::assertMountedCollectionsCovered();
    }

    public function testADirectoryNamingACollectionThatIsNotMountedIsRefused(): void
    {
        CoverageGuardHilos::initBrowser();
        $this->mount([CoverageGuardNotes::class]);

        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage(
            'Account data owner directory names collection [coverageTemplates] that this installation does not mount',
        );

        AccountErasureCoverageGuard::assertMountedCollectionsCovered();
    }

    public function testTwoFindingsAreNamedInOneRefusal(): void
    {
        CoverageGuardHilos::initBrowser();
        $this->mount([CoverageGuardNotes::class, CoverageGuardStrays::class]);

        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage(
            "- Collection [coverageStrays] has no account erasure participant and no exemption\n"
            . '- Account data owner directory names collection [coverageTemplates] that this installation does not mount',
        );

        AccountErasureCoverageGuard::assertMountedCollectionsCovered();
    }

    /**
     * Mounts the framework's collections and the given project ones.
     *
     * @param list<class-string<Objects>> $collectionClasses Project collections this installation declares
     */
    private function mount(array $collectionClasses): void
    {
        Hilos::$db = new CoverageGuardDbContext($collectionClasses);
        Hilos::$db->configure();
    }
}

/** A project with accounts, whose directory answers for its notes and its templates. */
abstract class CoverageGuardHilos extends Hilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = CoverageGuardDirectory::class;
    protected const array FEATURES = [HilosFeature::AUTH];
}

/** The same directory in a project without accounts. */
abstract class CoverageGuardWithoutAccountsHilos extends Hilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = CoverageGuardDirectory::class;
}

/** The notes kept by one agent for everybody, and the templates left out. */
abstract class CoverageGuardDirectory extends AccountDataOwnerDirectory
{
    protected static function owners(): array
    {
        return [new AccountDataOwner(
            'coverage_notes',
            [CoverageGuardNotes::COLLECTION_KEY],
            'coverage_keeper',
            false,
            static fn (int $userId): bool => false,
        )];
    }

    protected static function exempt(): array
    {
        return [CoverageGuardTemplates::COLLECTION_KEY => AccountErasureExemption::NotPersonal];
    }
}

/** The framework's collections, then whichever project collections a case names. */
final class CoverageGuardDbContext extends HilosDbContext
{
    /**
     * @param list<class-string<Objects>> $collectionClasses Project collections to mount, in the given order
     */
    public function __construct(private readonly array $collectionClasses)
    {
        parent::__construct();
    }

    /** Mounts the framework's collections, then the named ones under their own keys. */
    public function configure(): void
    {
        parent::configure();
        foreach ($this->collectionClasses as $collectionClass) {
            $this->mountObjectCollection($collectionClass, Objects::LAZY_STRATEGY_KEY);
        }
    }
}

/** A row of a made-up project table; the gate never reads it. */
final class CoverageGuardRow extends Entity
{
    public const string id = 'id';

    public const string _table = 'coverage_guard_row';
    public const string _primary = self::id;
    public const array _columns = [self::id];
    public const array _types = [self::id => PhpType::INTEGER->value];

    public ?int $id = null;
}

/**
 * @extends Object_<CoverageGuardRow>
 */
final class CoverageGuardRowObject extends Object_
{
    public const string ENTITY_CLASS = CoverageGuardRow::class;
}

/**
 * Covered by the directory's one owner.
 *
 * @extends Objects<CoverageGuardRowObject>
 */
final class CoverageGuardNotes extends Objects
{
    public const string OBJECT_CLASS = CoverageGuardRowObject::class;
    public const string COLLECTION_KEY = 'coverageNotes';
}

/**
 * Left out by the directory.
 *
 * @extends Objects<CoverageGuardRowObject>
 */
final class CoverageGuardTemplates extends Objects
{
    public const string OBJECT_CLASS = CoverageGuardRowObject::class;
    public const string COLLECTION_KEY = 'coverageTemplates';
}

/**
 * Named nowhere.
 *
 * @extends Objects<CoverageGuardRowObject>
 */
final class CoverageGuardStrays extends Objects
{
    public const string OBJECT_CLASS = CoverageGuardRowObject::class;
    public const string COLLECTION_KEY = 'coverageStrays';
}
