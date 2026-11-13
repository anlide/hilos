<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountDataOwner;
use Hilos\Auth\AccountOperation\AccountDataOwnerDirectory;
use Hilos\Auth\AccountOperation\AccountErasureExemption;
use Hilos\Auth\AccountOperation\AccountErasureFixedOwner;
use Hilos\Auth\AccountOperation\AccountErasureParticipant;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountErasureProjectParticipant;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The erasure participant catalog covers every framework collection once and refuses an overlap. */
final class AccountErasureParticipantCatalogTest extends TestCase
{
    private const array FRAMEWORK_KEYS = ['personal', 'users_library', 'sessions_holder', 'delivery', 'data_export', 'legal_export'];

    private string $previousApp;

    /** @return iterable<string, array{list<AccountErasureParticipant>, string}> Overlapping project participants and the refusal */
    public static function overlaps(): iterable
    {
        yield 'a framework key again' => [
            [self::projectOwner('personal', ['projectNotes'])],
            'Account erasure participant [personal] is declared twice',
        ];
        yield 'a project key twice' => [
            [self::projectOwner('project_notes', ['projectNotes']), self::projectOwner('project_notes', ['projectTags'])],
            'Account erasure participant [project_notes] is declared twice',
        ];
        yield 'a framework collection again' => [
            [self::projectOwner('project_notes', [HilosDbContext::userPhotos])],
            'Collection [userPhotos] is covered by both [personal] and [project_notes]',
        ];
        yield 'a project collection twice' => [
            [self::projectOwner('project_notes', ['projectNotes']), self::projectOwner('project_tags', ['projectNotes'])],
            'Collection [projectNotes] is covered by both [project_notes] and [project_tags]',
        ];
        yield 'an exempt collection' => [
            [self::projectOwner('project_files', [HilosDbContext::files])],
            'Collection [files] is covered by participant [project_files] and exempt as reference_nullified',
        ];
    }

    /** @return iterable<string, array{class-string<Hilos>, string}> Applications whose directory overlaps something, and the refusal */
    public static function projectOverlaps(): iterable
    {
        yield 'a framework collection exempted again' => [
            AccountErasureCatalogFrameworkExemptHilos::class,
            'Collection [settings] is exempt by the framework and cannot be exempted again by the project',
        ];
        yield 'a covered project collection exempted' => [
            AccountErasureCatalogCoveredExemptHilos::class,
            'Collection [chatMessages] is covered by participant [chat] and exempt as trace',
        ];
    }

    /** Remembers the running application, which some cases replace. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
    }

    /** Returns the previous application. */
    protected function tearDown(): void
    {
        try {
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
        } finally {
            parent::tearDown();
        }
    }

    public function testEveryMountedFrameworkCollectionIsCoveredOrExemptExactlyOnce(): void
    {
        AccountErasureCatalogEveryFeatureHilos::initBrowser();
        $context = new AccountErasureCatalogDbContext();
        $context->configure();
        $mounted = array_map(
            static fn (string $collectionClass): string => $collectionClass::COLLECTION_KEY,
            $context->getObjectCollectionClasses(),
        );

        $covered = [];
        foreach (AccountErasureParticipantCatalog::framework()->participants() as $participant) {
            array_push($covered, ...$participant->collections());
        }
        $declared = [...$covered, ...array_keys(AccountErasureParticipantCatalog::EXEMPT)];

        self::assertCount(55, $mounted);
        self::assertCount(22, $covered);
        self::assertCount(33, AccountErasureParticipantCatalog::EXEMPT);
        foreach ($mounted as $key) {
            self::assertSame(1, count(array_keys($declared, $key, true)), "Collection [{$key}] is covered or exempt exactly once");
        }
        self::assertSame([], array_values(array_diff($declared, $mounted)));
    }

    public function testFrameworkParticipantsComeInCensusOrderAndWithAddsProjectOnesAfterThem(): void
    {
        $framework = AccountErasureParticipantCatalog::framework();
        $project = self::projectOwner('project_notes', ['projectNotes']);

        $extended = $framework->with($project);

        self::assertSame(self::FRAMEWORK_KEYS, self::keys($framework));
        self::assertSame([...self::FRAMEWORK_KEYS, 'project_notes'], self::keys($extended));
        self::assertSame($project, $extended->participants()[6]);
        self::assertSame(
            [HilosDbContext::sessions, HilosDbContext::accessLogEntries],
            $framework->participants()[2]->collections(),
        );
        self::assertSame([HilosDbContext::notificationDeliveries], $framework->participants()[3]->collections());
    }

    /**
     * @param list<AccountErasureParticipant> $participants Project participants overlapping something
     * @param string $refusal Line the refusal names
     */
    #[DataProvider('overlaps')]
    public function testAnOverlappingKeyOrCollectionIsRefused(array $participants, string $refusal): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage($refusal);

        AccountErasureParticipantCatalog::framework()->with(...$participants);
    }

    public function testTheInstallationWithoutADirectoryIsTheFrameworkCatalog(): void
    {
        Hilos::initBrowser();

        $installation = AccountErasureParticipantCatalog::installation();

        self::assertSame(self::FRAMEWORK_KEYS, self::keys($installation));
        self::assertSame(AccountErasureParticipantCatalog::EXEMPT, $installation->exempt());
        self::assertSame(AccountErasureParticipantCatalog::EXEMPT, AccountErasureParticipantCatalog::framework()->exempt());
    }

    public function testTheInstallationTakesTheDirectoryOwnersAfterTheFrameworkWithTheProjectExemptions(): void
    {
        AccountErasureCatalogProjectHilos::initBrowser();
        $project = self::projectOwner('project_notes', ['projectNotes']);

        $installation = AccountErasureParticipantCatalog::installation();
        $extended = $installation->with($project);

        self::assertSame([...self::FRAMEWORK_KEYS, 'chat', 'polls'], self::keys($installation));
        self::assertInstanceOf(AccountErasureProjectParticipant::class, $installation->participants()[6]);
        self::assertSame(['chatMessages', 'chatEvents'], $installation->participants()[6]->collections());
        self::assertSame(['pollVotes'], $installation->participants()[7]->collections());
        $exempt = [
            ...AccountErasureParticipantCatalog::EXEMPT,
            'chatReports' => AccountErasureExemption::Trace,
            'pollTemplates' => AccountErasureExemption::NotPersonal,
        ];
        self::assertSame($exempt, $installation->exempt());
        self::assertSame([...self::FRAMEWORK_KEYS, 'chat', 'polls', 'project_notes'], self::keys($extended));
        self::assertSame($exempt, $extended->exempt());
    }

    /**
     * @param class-string<Hilos> $app Application whose directory overlaps something
     * @param string $refusal Line the refusal names
     */
    #[DataProvider('projectOverlaps')]
    public function testAProjectExemptionOverlappingTheCatalogIsRefused(string $app, string $refusal): void
    {
        $app::initBrowser();

        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage($refusal);

        AccountErasureParticipantCatalog::installation();
    }

    public function testAProjectCollectionCoveredByAnOwnerAndAddedThroughWithIsRefusedAsExempt(): void
    {
        AccountErasureCatalogProjectHilos::initBrowser();

        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('Collection [chatReports] is covered by participant [project_reports] and exempt as trace');

        AccountErasureParticipantCatalog::installation()->with(self::projectOwner('project_reports', ['chatReports']));
    }

    public function testExpectationKeysAndPayloadAreLiteral(): void
    {
        $person = new AccountOperationExpectation('personal', 43, new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, '43'));
        $library = new AccountOperationExpectation('users_library', 43, new AccountOperationOwnerAddress(HilosAgentType::HILOS_USERS_LIBRARY));
        $wait = new AccountOperationExpectation('delivery:sms', 43, null);

        self::assertFalse($person->isWait());
        self::assertSame('expect:personal:hilos_user:43:43', $person->assignmentKey());
        self::assertSame('{"participant":"personal"}', $person->payload()->toJson());
        self::assertSame($person->owner, $person->assignmentOwner());
        self::assertSame('expect:users_library:hilos_users_library:43', $library->assignmentKey());
        self::assertTrue($wait->isWait());
        self::assertSame('wait:delivery:sms:43', $wait->assignmentKey());
        self::assertSame('{"participant":"delivery:sms"}', $wait->payload()->toJson());
        self::assertTrue($wait->assignmentOwner()->equals(new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY)));
    }

    /**
     * @param string $key Participant key
     * @param list<string> $collections Collections it covers
     * @return AccountErasureFixedOwner Project participant with an unregistered owner and no rows
     */
    private static function projectOwner(string $key, array $collections): AccountErasureFixedOwner
    {
        return new AccountErasureFixedOwner($key, $collections, 'project_notes_keeper', false, static fn (int $userId): bool => false);
    }

    /**
     * @param AccountErasureParticipantCatalog $catalog Catalog to read
     * @return list<string> Participant keys in census order
     */
    private static function keys(AccountErasureParticipantCatalog $catalog): array
    {
        return array_map(static fn (AccountErasureParticipant $participant): string => $participant->key(), $catalog->participants());
    }
}

/**
 * A context with no declaration of its own: the framework's collections and nothing else.
 */
final class AccountErasureCatalogDbContext extends HilosDbContext
{
}

/** Every feature that mounts framework collections of its own. */
abstract class AccountErasureCatalogEveryFeatureHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::CHANGE_LOG, HilosFeature::STATUS];
}

/** A project with two owners and two exempt collections of its own. */
abstract class AccountErasureCatalogProjectHilos extends Hilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = AccountErasureCatalogProjectDirectory::class;
}

/** A project that exempts a framework collection again. */
abstract class AccountErasureCatalogFrameworkExemptHilos extends Hilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = AccountErasureCatalogFrameworkExemptDirectory::class;
}

/** A project that exempts a collection its own owner covers. */
abstract class AccountErasureCatalogCoveredExemptHilos extends Hilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = AccountErasureCatalogCoveredExemptDirectory::class;
}

/** A chat kept by one agent, polls kept per person, and two collections left out. */
abstract class AccountErasureCatalogProjectDirectory extends AccountDataOwnerDirectory
{
    protected static function owners(): array
    {
        return [
            new AccountDataOwner('chat', ['chatMessages', 'chatEvents'], 'chat_keeper', false, static fn (int $userId): bool => false),
            new AccountDataOwner('polls', ['pollVotes'], 'polls_keeper', true, static fn (int $userId): bool => false),
        ];
    }

    protected static function exempt(): array
    {
        return ['chatReports' => AccountErasureExemption::Trace, 'pollTemplates' => AccountErasureExemption::NotPersonal];
    }
}

/** Leaves out the settings, which the framework already leaves out. */
abstract class AccountErasureCatalogFrameworkExemptDirectory extends AccountDataOwnerDirectory
{
    protected static function exempt(): array
    {
        return [HilosDbContext::settings => AccountErasureExemption::NotPersonal];
    }
}

/** Covers the chat messages and leaves them out at once. */
abstract class AccountErasureCatalogCoveredExemptDirectory extends AccountDataOwnerDirectory
{
    protected static function owners(): array
    {
        return [new AccountDataOwner('chat', ['chatMessages'], 'chat_keeper', false, static fn (int $userId): bool => false)];
    }

    protected static function exempt(): array
    {
        return ['chatMessages' => AccountErasureExemption::Trace];
    }
}
