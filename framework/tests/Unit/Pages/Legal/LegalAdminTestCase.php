<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Legal;

use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Constants\HilosPageConstants;
use Hilos\Pages\Legal\AbstractHilosLegalAcceptancesPage;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\View\Collection\AccountDeletions;
use Hilos\Database\View\Collection\LegalAcceptances;
use Hilos\Database\View\Collection\UserMerges;
use Hilos\Database\View\Collection\Users;
use Hilos\Database\View\Item\LegalAcceptance;
use Hilos\Database\View\Item\User;
use Hilos\Hilos;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Users\AccountStandingResolver;
use Generator;
use PHPUnit\Framework\TestCase;

/** Isolates the audience and account standing, with separate aggregate and per-person reads. */
abstract class LegalAdminTestCase extends TestCase
{
    protected LegalAdminReadProbe $reads;
    private ?HilosDbContext $previousDb;
    private ?SignalRouter $previousRouter;
    private ?BrowserContext $previousBrowser;
    private string $previousFacade;

    /** Binds a tiny catalog and counts each histogram read. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousFacade = Hilos::appClass();
        AccountStandingResolver::forgetAll();
        LegalAdminTestHilos::initBrowser();
        $this->reads = new LegalAdminReadProbe();
        $legalAcceptances = $this->createStub(LegalAcceptances::class);
        $legalAcceptances->method('documentsOnRecord')->willReturnCallback($this->reads->documentsOnRecord(...));
        $legalAcceptances->method('heldCounts')->willReturnCallback($this->reads->heldCounts(...));
        $legalAcceptances->method('acceptedCounts')->willReturnCallback($this->reads->acceptedCounts(...));
        $legalAcceptances->method('revisionsOnRecord')->willReturnCallback($this->reads->revisionsOnRecord(...));
        $acceptance = $this->createStub(LegalAcceptance::class);
        $acceptance->method('__get')->willReturnMap([['document', 'terms'], ['revisionId', 'current']]);
        $legalAcceptances->method('ofUser')->willReturnSelf();
        $legalAcceptances->method('getIterator')->willReturnCallback(static function () use ($acceptance): Generator {
            yield $acceptance;
        });
        $user = $this->createStub(User::class);
        $user->method('getIdString')->willReturn('1');
        $user->method('__get')->willReturnMap([['block', false]]);
        $deletions = $this->createStub(AccountDeletions::class);
        $deletions->method('liveOf')->willReturn(null);
        Hilos::$db = new LegalAdminTestDb(
            $legalAcceptances,
            UserMerges::initEmpty(),
            $deletions,
            Users::fromSingleItem($user),
        );
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = $this->createMock(BrowserContext::class);
        Hilos::$browser->method('resolveActionUserId')->willReturn(1);
        Hilos::$browser->method('actsAsAdmin')->willReturn(true);
        LegalAdminAudience::reset();
    }

    /** Restores all process-global bindings. */
    protected function tearDown(): void
    {
        LegalAdminAudience::reset();
        AccountStandingResolver::forgetAll();
        $this->previousFacade::initBrowser();
        Hilos::$db = $this->previousDb;
        Hilos::$sr = $this->previousRouter;
        Hilos::$browser = $this->previousBrowser;
        parent::tearDown();
    }
}

/** Two declarations, with the first acceptance's deadline falling today. */
final class LegalAdminTestCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Catalog fixture */
    public static function revisions(): array
    {
        return ['terms' => [
            new LegalRevision(LegalDocument::TERMS, 'first', '2020-01-01', 1, LegalSignificance::SUBSTANTIAL, '2020-01-01', []),
            new LegalRevision(
                LegalDocument::TERMS,
                'current',
                '2020-02-01',
                1,
                LegalSignificance::SUBSTANTIAL,
                LegalStandingResolver::today(),
                [],
            ),
        ]];
    }
}

/** Catalog binding used only by pure projection tests. */
abstract class LegalAdminTestHilos extends Hilos
{
    public const array PAGES = [HilosPageConstants::HILOS_LEGAL_ACCEPTANCES => LegalAudienceAcceptancesPage::class];

    protected const ?string LEGAL_CATALOG = LegalAdminTestCatalog::class;
}

/** The SQL boundary is counted here; SQL itself is covered in LegalAcceptanceIntegrationTest. */
final class LegalAdminReadProbe
{
    public int $reads = 0;
    /** @var list<string> Recorded document keys */
    public array $documents = ['terms'];
    public array $held = ['first' => 3, 'current' => 2];
    public array $accepted = ['first' => 5, 'current' => 2, 'gone' => 1];
    public int $revisionReads = 0;
    public array $recorded = ['terms' => ['first', 'gone', 'current']];

    /** @return array<string, list<string>> Distinct recorded revision keys */
    public function revisionsOnRecord(): array
    {
        $this->revisionReads++;
        return $this->recorded;
    }

    /** @return list<string> Recorded document keys */
    public function documentsOnRecord(): array
    {
        $this->reads++;
        return $this->documents;
    }

    /**
     * @param string $document Document being read
     * @param list<string> $declaredIds Catalog declaration order
     * @return array<string, int> Fixture holders
     */
    public function heldCounts(string $document, array $declaredIds): array
    {
        $this->reads++;
        TestCase::assertSame('terms', $document);
        TestCase::assertSame(['first', 'current'], $declaredIds);
        return $this->held;
    }

    /**
     * @param string $document Document being read
     * @return array<string, int> Fixture acceptance counts
     */
    public function acceptedCounts(string $document): array
    {
        $this->reads++;
        TestCase::assertSame('terms', $document);
        return $this->accepted;
    }
}

/** Typed account sources and the aggregate boundary, without a DB connection. */
final class LegalAdminTestDb extends HilosDbContext
{
    /**
     * @param LegalAcceptances $legalAcceptances Counted aggregates and the viewer's acceptances
     * @param UserMerges $userMerges Empty merge history
     * @param AccountDeletions $accountDeletions No pending deletion
     * @param Users $users Unblocked viewer
     */
    public function __construct(
        public LegalAcceptances $legalAcceptances,
        public UserMerges $userMerges,
        public AccountDeletions $accountDeletions,
        public Users $users,
    ) {
        parent::__construct();
    }

    /** Sources are supplied directly by the fixture. */
    public function configure(): void
    {
    }
}

/** Page class used by the broadcast's real access-level gate. */
final class LegalAudienceAcceptancesPage extends AbstractHilosLegalAcceptancesPage
{
}
