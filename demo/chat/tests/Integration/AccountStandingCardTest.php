<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Users\UserPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\HilosUser\HilosUsersTable;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Hilos as HilosFacade;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Legal\LegalTally;
use Hilos\Pages\Users\AccountStandingAudience;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Users\AbstractHilosUsersTable;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountStanding;
use Hilos\Users\AccountStandingResolver;
use Hilos\Users\DTO\AccountStandingStateSignalData;

/**
 * The admin card of a person reads the person's standing as one verdict, and follows it (HIL-945).
 *
 * On the chat demo's real card, its real index agent and its real people list: the page answer
 * carries the standing, a block and a scheduled deletion written under the open card reach its
 * administrator on the next tick of the agent serving it, and the list narrowed to the people past
 * a deadline holds exactly the people the legal section counts there.
 */
final class AccountStandingCardTest extends IntegrationTestCase
{
    private const string ACCEPT_KEY = 'account-standing-card';
    private const string TEST_AGENT = 'account-standing-card-test';

    private int $personId;

    protected function setUp(): void
    {
        parent::setUp();
        HilosFacade::$sr = new AccountStandingCardRouter();
        Hilos::initBrowser();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        TruthSourceRegistry::register(HilosDbContext::accountDeletions, TruthSourceKeys::all(), self::TEST_AGENT);
        TruthSourceRegistry::register(HilosDbContext::userMerges, TruthSourceKeys::all(), self::TEST_AGENT);
        Hilos::$rt->connections->actions->clear();
        AccountStandingAudience::reset();
        AccountStandingResolver::forgetAll();
        $this->personId = (int) Hilos::$db->users->actions->createWithName('Taras Shevchuk')->id;
        $this->connectAdmin();
    }

    protected function tearDown(): void
    {
        AccountStandingAudience::reset();
        AccountStandingResolver::forgetAll();
        Hilos::initBrowser();
        Hilos::$rt->connections->actions->clear();
        TruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        HilosFacade::$sr = null;
        parent::tearDown();
    }

    public function testTheCardAnswersWithTheStandingOfItsPerson(): void
    {
        $standing = $this->subscribeToCard();

        self::assertSame('none', $standing[AccountStanding::shown]);
        self::assertFalse($standing[AccountStanding::blocked]);
        self::assertFalse($standing[AccountStanding::frozen]);
        self::assertNull($standing[AccountStanding::deletionEffectiveAt]);
        self::assertSame([], $standing[AccountStanding::lapsed]);
    }

    public function testABlockAndADeletionUnderTheOpenCardReachItOnTheNextTick(): void
    {
        $this->subscribeToCard();
        $agent = new DemoHilosAgent();

        AccountStandingAudience::onAgentTick($agent);
        self::assertSame([], $this->standingFrames(), 'Nothing moved, nothing is sent');

        Hilos::$db->users[$this->personId]->actions->setBlock(true);
        AccountStandingAudience::onAgentTick($agent);
        $frames = $this->standingFrames();
        self::assertCount(1, $frames);
        self::assertSame($this->personId, $frames[0]->userId);
        self::assertSame('blocked', $frames[0]->accountStanding[AccountStanding::shown]);

        $request = Hilos::$db->accountDeletions->actions->request($this->personId, date('Y-m-d H:i:s', time() + TimeConstants::SECONDS_PER_DAY));
        AccountStandingAudience::onAgentTick($agent);
        $frames = $this->standingFrames();
        self::assertCount(1, $frames);
        self::assertSame('blocked', $frames[0]->accountStanding[AccountStanding::shown], 'The block takes more away than the deletion');
        self::assertIsInt($frames[0]->accountStanding[AccountStanding::deletionEffectiveAt]);

        $request->actions->cancel();
        Hilos::$db->users[$this->personId]->actions->setBlock(false);
        AccountStandingAudience::onAgentTick($agent);
        $frames = $this->standingFrames();
        self::assertCount(1, $frames);
        self::assertSame('none', $frames[0]->accountStanding[AccountStanding::shown]);
    }

    /**
     * A merge written under the open card reaches it on the next tick as the merged standing, naming
     * where the account went (HIL-1292); the chain growing past the survivor and the survivor's
     * rename move the card the same way, with nobody telling the resolver to forget anything.
     */
    public function testAMergeUnderTheOpenCardReachesItWithWhereTheAccountWent(): void
    {
        $this->subscribeToCard();
        $agent = new DemoHilosAgent();
        $survivor = Hilos::$db->users->actions->createWithName('Lesya Ukrainka');
        $survivorId = (int) $survivor->id;

        // The tombstone first, the block second - the order the merge writes them in.
        Hilos::$db->userMerges->actions->add($this->personId, $survivorId);
        Hilos::$db->users[$this->personId]->actions->setBlock(true);
        AccountStandingAudience::onAgentTick($agent);
        $frames = $this->standingFrames();
        self::assertCount(1, $frames);
        self::assertSame('merged', $frames[0]->accountStanding[AccountStanding::shown]);
        self::assertTrue($frames[0]->accountStanding[AccountStanding::blocked], 'The block stays named under the merge');
        self::assertSame($survivorId, $frames[0]->accountStanding[AccountStanding::mergedInto]);
        self::assertSame('Lesya Ukrainka', $frames[0]->accountStanding[AccountStanding::mergedIntoName]);

        $survivor->actions->rename('Larysa Kosach');
        AccountStandingAudience::onAgentTick($agent);
        $frames = $this->standingFrames();
        self::assertCount(1, $frames);
        self::assertSame('Larysa Kosach', $frames[0]->accountStanding[AccountStanding::mergedIntoName]);

        $thirdId = (int) Hilos::$db->users->actions->createWithName('Olha Kobylianska')->id;
        Hilos::$db->userMerges->actions->add($survivorId, $thirdId);
        AccountStandingAudience::onAgentTick($agent);
        $frames = $this->standingFrames();
        self::assertCount(1, $frames);
        self::assertSame($thirdId, $frames[0]->accountStanding[AccountStanding::mergedInto], 'The card follows the chain to its live end');
        self::assertSame('Olha Kobylianska', $frames[0]->accountStanding[AccountStanding::mergedIntoName]);
    }

    public function testTheListNarrowedToALapsedDocumentHoldsExactlyThePeopleTheLegalRootCounts(): void
    {
        $other = (int) Hilos::$db->users->actions->createWithName('Lesya Ukrainka')->id;
        foreach ([[$this->personId, 'first'], [$other, 'first'], [$other, 'second']] as [$userId, $revision]) {
            Database::sqlRun(
                'INSERT INTO hilos_legal_acceptance (user_id, document, revision_id, accepted_at) VALUES (?, ?, ?, ?)',
                [$userId, LegalDocument::TERMS->value, $revision, '2026-01-02 00:00:00'],
            );
        }
        AccountStandingCardCatalogHilos::initBrowser();
        try {
            $table = new HilosUsersTable();
            $ids = array_map(
                static fn ($row): int => (int) $row->id,
                $table->getPage(new TableQueryDTO(limit: 500, filter: [AbstractHilosUsersTable::FILTER_LAPSED => 'terms']))->rows,
            );
            $counted = LegalTally::all(LegalStandingResolver::today())[LegalDocument::TERMS->value]->lapsed;
            $none = $table->getPage(new TableQueryDTO(limit: 500, filter: [AbstractHilosUsersTable::FILTER_LAPSED => 'unknown']))->rows;
        } finally {
            Database::sqlRun('DELETE FROM hilos_legal_acceptance WHERE user_id IN (?, ?)', [$this->personId, $other]);
            Hilos::initBrowser();
        }

        self::assertContains($this->personId, $ids);
        self::assertNotContains($other, $ids);
        self::assertCount($counted, $ids);
        self::assertSame([], $none, 'A document nobody declared narrows to nobody');
    }

    /**
     * Opens a connection for an administrator.
     */
    private function connectAdmin(): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Administrator of the card');
        $admin->actions->setAdmin(true);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', $this->name()), 0, 32));
        $session->actions->bindUser((int) $admin->id);
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, (int) $admin->id, $session->token, (int) $session->id);
    }

    /**
     * Subscribes the administrator to the card of the person and reads the standing its answer carries.
     *
     * @return array<string, mixed> The standing in the page data
     */
    private function subscribeToCard(): array
    {
        $params = ['userId' => (string) $this->personId];
        HilosFacade::$sr->subscribeToPage(UserPage::PAGE, new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, UserPage::PAGE, $params));
        ExecutionContext::run(new ExecutionFrame(acceptKey: self::ACCEPT_KEY), static function () use ($params): void {
            new UserPage(new DemoHilosAgent())->onSubscribe(self::ACCEPT_KEY, new PageRouteParams($params));
        });
        $standing = null;
        while (($signal = HilosFacade::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE || !$signal->data instanceof WebSocketSignalData) {
                continue;
            }
            $data = $signal->data->data->toArray()[PageResponseSignalData::payload][PagePayload::data] ?? [];
            $standing ??= $data[UserPage::ACCOUNT_STANDING] ?? null;
        }
        self::assertIsArray($standing, 'The card answered without the standing');

        return $standing;
    }

    /**
     * @return list<AccountStandingStateSignalData> Standing frames queued to the administrator, in order
     */
    private function standingFrames(): array
    {
        $frames = [];
        while (($signal = HilosFacade::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_ACCOUNT_STANDING_STATE) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
            self::assertInstanceOf(AccountStandingStateSignalData::class, $signal->data->data);
            $frames[] = $signal->data->data;
        }

        return $frames;
    }
}

/** Router answering from the chat demo's topology rather than the framework's bare one. */
final class AccountStandingCardRouter extends SignalRouter
{
    /**
     * @return class-string<Hilos> The chat demo's facade
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }
}

/** Terms whose second substantial revision has been in force since March. */
final class AccountStandingCardCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        return [
            LegalDocument::TERMS->value => [
                new LegalRevision(LegalDocument::TERMS, 'first', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
                new LegalRevision(LegalDocument::TERMS, 'second', '2026-02-01', 1, LegalSignificance::SUBSTANTIAL, '2026-03-01', []),
            ],
        ];
    }
}

/** Binds the fixture catalog, the chat's own having no revision anybody could be past. */
abstract class AccountStandingCardCatalogHilos extends HilosFacade
{
    protected const ?string LEGAL_CATALOG = AccountStandingCardCatalog::class;
}
