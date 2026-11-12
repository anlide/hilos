<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountErasureExpectation;
use Hilos\Auth\AccountOperation\AccountErasureFixedOwner;
use Hilos\Auth\AccountOperation\AccountErasureParticipant;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Database\Context\HilosDbContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The erasure participant catalog covers every framework collection once and refuses an overlap. */
final class AccountErasureParticipantCatalogTest extends TestCase
{
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

    public function testEveryMountedFrameworkCollectionIsCoveredOrExemptExactlyOnce(): void
    {
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

        self::assertCount(42, $mounted);
        self::assertCount(22, $covered);
        self::assertCount(20, AccountErasureParticipantCatalog::EXEMPT);
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

        $frameworkKeys = ['personal', 'users_library', 'sessions_holder', 'delivery', 'data_export', 'legal_export'];
        self::assertSame($frameworkKeys, self::keys($framework));
        self::assertSame([...$frameworkKeys, 'project_notes'], self::keys($extended));
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

    public function testExpectationKeysAndPayloadAreLiteral(): void
    {
        $person = new AccountErasureExpectation('personal', 43, new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, '43'));
        $library = new AccountErasureExpectation('users_library', 43, new AccountOperationOwnerAddress(HilosAgentType::HILOS_USERS_LIBRARY));
        $wait = new AccountErasureExpectation('delivery:sms', 43, null);

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
