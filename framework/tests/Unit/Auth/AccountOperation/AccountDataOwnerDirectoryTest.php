<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountDataOwner;
use Hilos\Auth\AccountOperation\AccountDataOwnerDirectory;
use Hilos\Auth\AccountOperation\AccountErasureExemption;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A project's account data owner directory answers what it declared and refuses a malformed declaration. */
final class AccountDataOwnerDirectoryTest extends TestCase
{
    /** @return iterable<string, array{class-string<AccountDataOwnerDirectory>, string}> Malformed directories and the refusal */
    public static function malformed(): iterable
    {
        yield 'a key with a colon' => [
            AccountDataOwnerColonKeyDirectory::class,
            'Account data owner key [chat:rooms] does not match /^[a-z][a-z0-9_]*$/',
        ];
        yield 'a key with a capital' => [
            AccountDataOwnerCapitalKeyDirectory::class,
            'Account data owner key [Chat] does not match /^[a-z][a-z0-9_]*$/',
        ];
        yield 'a key twice' => [
            AccountDataOwnerKeyTwiceDirectory::class,
            'Account data owner [chat] is declared twice',
        ];
        yield 'an agent type twice' => [
            AccountDataOwnerTypeTwiceDirectory::class,
            'Agent type [chat_keeper] owns for both [chat] and [polls]',
        ];
        yield 'a framework owner type' => [
            AccountDataOwnerFrameworkTypeDirectory::class,
            'Account data owner [chat] names the framework owner type [hilos_user]',
        ];
        yield 'no collection' => [
            AccountDataOwnerNoCollectionDirectory::class,
            'Account data owner [chat] covers no collection',
        ];
        yield 'owners not a list' => [
            AccountDataOwnerMapDirectory::class,
            AccountDataOwnerMapDirectory::class . ' must declare its account data owners as a list',
        ];
        yield 'an element of a foreign type' => [
            AccountDataOwnerForeignElementDirectory::class,
            AccountDataOwnerForeignElementDirectory::class . ' declares an account data owner at position 1 that is string',
        ];
        yield 'an exemption that is no reason' => [
            AccountDataOwnerForeignExemptionDirectory::class,
            AccountDataOwnerForeignExemptionDirectory::class . ' exempts [chatReports] for string, not an ' . AccountErasureExemption::class,
        ];
    }

    public function testTheDefaultDeclaresNothing(): void
    {
        self::assertSame([], AccountDataOwnerDirectory::declaredOwners());
        self::assertSame([], AccountDataOwnerDirectory::declaredExemptions());
    }

    public function testDeclaredOwnersAndExemptionsComeBackAsDeclared(): void
    {
        $owners = AccountDataOwnerDeclaredDirectory::declaredOwners();

        self::assertSame(['chat', 'polls'], array_map(static fn (AccountDataOwner $owner): string => $owner->key, $owners));
        self::assertSame(['chatMessages', 'chatEvents'], $owners[0]->collections);
        self::assertSame('chat_keeper', $owners[0]->ownerType);
        self::assertFalse($owners[0]->perPerson);
        self::assertTrue($owners[1]->perPerson);
        self::assertTrue(($owners[1]->hasRowsOf)(43));
        self::assertSame(
            ['chatReports' => AccountErasureExemption::Trace, 'pollTemplates' => AccountErasureExemption::NotPersonal],
            AccountDataOwnerDeclaredDirectory::declaredExemptions(),
        );
    }

    /**
     * @param class-string<AccountDataOwnerDirectory> $directory Directory with one mistake
     * @param string $refusal Line the refusal names
     */
    #[DataProvider('malformed')]
    public function testAMalformedDeclarationIsRefused(string $directory, string $refusal): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage($refusal);

        $directory::declaredOwners();
    }

    public function testEveryMistakeIsNamedAtOnceAndRefusesTheExemptionsToo(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage(
            'Invalid Hilos topology in ' . Hilos::appClass() . ":\n"
            . "- Account data owner key [chat:rooms] does not match /^[a-z][a-z0-9_]*$/\n"
            . "- Account data owner [chat:rooms] names the framework owner type [hilos_users_library]\n"
            . "- Account data owner [chat:rooms] covers no collection\n"
            . '- ' . AccountDataOwnerManyMistakesDirectory::class . ' exempts [chatReports] for null, not an '
            . AccountErasureExemption::class,
        );

        AccountDataOwnerManyMistakesDirectory::declaredExemptions();
    }
}

/** Builds the owners of the fixture directories below. */
abstract class AccountDataOwnerFixtureDirectory extends AccountDataOwnerDirectory
{
    /**
     * @param string $key Owner key
     * @param string $ownerType Agent type of the owner
     * @param list<string> $collections Collections it covers
     * @return AccountDataOwner Owner kept by one agent, with rows for everybody
     */
    protected static function owner(string $key, string $ownerType, array $collections = ['chatMessages']): AccountDataOwner
    {
        return new AccountDataOwner($key, $collections, $ownerType, false, static fn (int $userId): bool => true);
    }
}

/** Two owners, one per person, and two exempt collections. */
abstract class AccountDataOwnerDeclaredDirectory extends AccountDataOwnerFixtureDirectory
{
    protected static function owners(): array
    {
        return [
            self::owner('chat', 'chat_keeper', ['chatMessages', 'chatEvents']),
            new AccountDataOwner('polls', ['pollVotes'], 'polls_keeper', true, static fn (int $userId): bool => true),
        ];
    }

    protected static function exempt(): array
    {
        return ['chatReports' => AccountErasureExemption::Trace, 'pollTemplates' => AccountErasureExemption::NotPersonal];
    }
}

/** A key carrying the separator of assignment keys. */
abstract class AccountDataOwnerColonKeyDirectory extends AccountDataOwnerFixtureDirectory
{
    protected static function owners(): array
    {
        return [self::owner('chat:rooms', 'chat_keeper')];
    }
}

/** A key that is not lower case. */
abstract class AccountDataOwnerCapitalKeyDirectory extends AccountDataOwnerFixtureDirectory
{
    protected static function owners(): array
    {
        return [self::owner('Chat', 'chat_keeper')];
    }
}

/** One key for two owners. */
abstract class AccountDataOwnerKeyTwiceDirectory extends AccountDataOwnerFixtureDirectory
{
    protected static function owners(): array
    {
        return [self::owner('chat', 'chat_keeper'), self::owner('chat', 'polls_keeper', ['pollVotes'])];
    }
}

/** One agent type for two owners. */
abstract class AccountDataOwnerTypeTwiceDirectory extends AccountDataOwnerFixtureDirectory
{
    protected static function owners(): array
    {
        return [self::owner('chat', 'chat_keeper'), self::owner('polls', 'chat_keeper', ['pollVotes'])];
    }
}

/** A project owner under the person's own agent type. */
abstract class AccountDataOwnerFrameworkTypeDirectory extends AccountDataOwnerFixtureDirectory
{
    protected static function owners(): array
    {
        return [self::owner('chat', HilosAgentType::HILOS_USER)];
    }
}

/** An owner of nothing. */
abstract class AccountDataOwnerNoCollectionDirectory extends AccountDataOwnerFixtureDirectory
{
    protected static function owners(): array
    {
        return [self::owner('chat', 'chat_keeper', [])];
    }
}

/** Owners keyed by name instead of listed. */
abstract class AccountDataOwnerMapDirectory extends AccountDataOwnerFixtureDirectory
{
    protected static function owners(): array
    {
        return ['chat' => self::owner('chat', 'chat_keeper')];
    }
}

/** A string where an owner should be. */
abstract class AccountDataOwnerForeignElementDirectory extends AccountDataOwnerFixtureDirectory
{
    protected static function owners(): array
    {
        return [self::owner('chat', 'chat_keeper'), 'polls'];
    }
}

/** An exemption whose reason is a bare string. */
abstract class AccountDataOwnerForeignExemptionDirectory extends AccountDataOwnerFixtureDirectory
{
    protected static function exempt(): array
    {
        return ['chatReports' => 'trace'];
    }
}

/** Three mistakes in one owner and one in the exemptions. */
abstract class AccountDataOwnerManyMistakesDirectory extends AccountDataOwnerFixtureDirectory
{
    protected static function owners(): array
    {
        return [self::owner('chat:rooms', HilosAgentType::HILOS_USERS_LIBRARY, [])];
    }

    protected static function exempt(): array
    {
        return ['chatReports' => null];
    }
}
