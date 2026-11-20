<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountDataOwner;
use Hilos\Auth\AccountOperation\AccountDataOwnerDirectory;
use Hilos\Auth\AccountOperation\AccountErasureExemption;
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

    /** @return iterable<string, array{class-string<Hilos>, string}> Applications whose directory overlaps something, and the refusal */
    public static function projectOverlaps(): iterable
    {
        yield 'a framework key again' => [
            AccountErasureCatalogFrameworkKeyAgainHilos::class,
            'Account erasure participant [personal] is declared twice',
        ];
        yield 'a project key twice' => [
            AccountErasureCatalogProjectKeyTwiceHilos::class,
            'Account data owner [project_notes] is declared twice',
        ];
        yield 'a framework collection again' => [
            AccountErasureCatalogFrameworkCollectionAgainHilos::class,
            'Collection [userPhotos] is covered by both [personal] and [project_notes]',
        ];
        yield 'a project collection twice' => [
            AccountErasureCatalogProjectCollectionTwiceHilos::class,
            'Collection [projectNotes] is covered by both [project_notes] and [project_tags]',
        ];
        yield 'an exempt collection' => [
            AccountErasureCatalogExemptCollectionHilos::class,
            'Collection [files] is covered by participant [project_files] and exempt as reference_nullified',
        ];
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

        self::assertCount(58, $mounted);
        self::assertCount(22, $covered);
        self::assertCount(36, AccountErasureParticipantCatalog::EXEMPT);
        foreach ($mounted as $key) {
            self::assertSame(1, count(array_keys($declared, $key, true)), "Collection [{$key}] is covered or exempt exactly once");
        }
        self::assertSame([], array_values(array_diff($declared, $mounted)));
    }

    public function testFrameworkParticipantsComeInCensusOrder(): void
    {
        $framework = AccountErasureParticipantCatalog::framework();

        self::assertSame(self::FRAMEWORK_KEYS, self::keys($framework));
        self::assertSame(
            [HilosDbContext::sessions, HilosDbContext::accessLogEntries],
            $framework->participants()[2]->collections(),
        );
        self::assertSame([HilosDbContext::notificationDeliveries], $framework->participants()[3]->collections());
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

        $installation = AccountErasureParticipantCatalog::installation();

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
    }

    /**
     * @param class-string<Hilos> $app Application whose directory overlaps something
     * @param string $refusal Line the refusal names
     */
    #[DataProvider('projectOverlaps')]
    public function testAnOverlappingDirectoryIsRefused(string $app, string $refusal): void
    {
        $app::initBrowser();

        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage($refusal);

        AccountErasureParticipantCatalog::installation();
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

/** A project declaring the personal key again. */
abstract class AccountErasureCatalogFrameworkKeyAgainHilos extends Hilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = AccountErasureCatalogFrameworkKeyAgainDirectory::class;
}

/** A project declaring the project_notes key twice. */
abstract class AccountErasureCatalogProjectKeyTwiceHilos extends Hilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = AccountErasureCatalogProjectKeyTwiceDirectory::class;
}

/** A project declaring the framework's userPhotos collection again. */
abstract class AccountErasureCatalogFrameworkCollectionAgainHilos extends Hilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = AccountErasureCatalogFrameworkCollectionAgainDirectory::class;
}

/** A project declaring the projectNotes collection twice. */
abstract class AccountErasureCatalogProjectCollectionTwiceHilos extends Hilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = AccountErasureCatalogProjectCollectionTwiceDirectory::class;
}

/** A project declaring a collection that the framework leaves out. */
abstract class AccountErasureCatalogExemptCollectionHilos extends Hilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = AccountErasureCatalogExemptCollectionDirectory::class;
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

/** Covers projectNotes under the framework's personal participant key. */
abstract class AccountErasureCatalogFrameworkKeyAgainDirectory extends AccountDataOwnerDirectory
{
    protected static function owners(): array
    {
        return [
            new AccountDataOwner(
                'personal',
                ['projectNotes'],
                'project_personal_keeper',
                false,
                static fn (int $userId): bool => false,
            ),
        ];
    }
}

/** Declares two owners under the same project_notes key. */
abstract class AccountErasureCatalogProjectKeyTwiceDirectory extends AccountDataOwnerDirectory
{
    protected static function owners(): array
    {
        return [
            new AccountDataOwner('project_notes', ['projectNotes'], 'project_notes_keeper_1', false, static fn (int $userId): bool => false),
            new AccountDataOwner('project_notes', ['projectTags'], 'project_notes_keeper_2', false, static fn (int $userId): bool => false),
        ];
    }
}

/** Covers the framework's userPhotos collection under a project owner. */
abstract class AccountErasureCatalogFrameworkCollectionAgainDirectory extends AccountDataOwnerDirectory
{
    protected static function owners(): array
    {
        return [
            new AccountDataOwner(
                'project_notes',
                [HilosDbContext::userPhotos],
                'project_notes_keeper',
                false,
                static fn (int $userId): bool => false,
            ),
        ];
    }
}

/** Covers projectNotes under two different project owners. */
abstract class AccountErasureCatalogProjectCollectionTwiceDirectory extends AccountDataOwnerDirectory
{
    protected static function owners(): array
    {
        return [
            new AccountDataOwner('project_notes', ['projectNotes'], 'project_notes_keeper', false, static fn (int $userId): bool => false),
            new AccountDataOwner('project_tags', ['projectNotes'], 'project_tags_keeper', false, static fn (int $userId): bool => false),
        ];
    }
}

/** Covers the framework's files collection, which is exempt. */
abstract class AccountErasureCatalogExemptCollectionDirectory extends AccountDataOwnerDirectory
{
    protected static function owners(): array
    {
        return [
            new AccountDataOwner(
                'project_files',
                [HilosDbContext::files],
                'project_files_keeper',
                false,
                static fn (int $userId): bool => false,
            ),
        ];
    }
}
