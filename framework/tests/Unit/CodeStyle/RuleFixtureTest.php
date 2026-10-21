<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\Baseline;
use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Rule\BlockingResolutionRule;
use Hilos\Tests\CodeStyle\Rule\CodeFqnRule;
use Hilos\Tests\CodeStyle\Rule\CollectionItemNarrowingRule;
use Hilos\Tests\CodeStyle\Rule\CollectionObjectListRule;
use Hilos\Tests\CodeStyle\Rule\CollectionSqlReadRule;
use Hilos\Tests\CodeStyle\Rule\EmptyStringSentinelRule;
use Hilos\Tests\CodeStyle\Rule\ErrorSuppressionRule;
use Hilos\Tests\CodeStyle\Rule\FrameworkOrmChainRule;
use Hilos\Tests\CodeStyle\Rule\FsSeamRule;
use Hilos\Tests\CodeStyle\Rule\LineLengthRule;
use Hilos\Tests\CodeStyle\Rule\MagicRepeatRule;
use Hilos\Tests\CodeStyle\Rule\MalformedInputMarkerRule;
use Hilos\Tests\CodeStyle\Rule\MemberIndentRule;
use Hilos\Tests\CodeStyle\Rule\NestableTransactionRule;
use Hilos\Tests\CodeStyle\Rule\ObjectStoreMutationRule;
use Hilos\Tests\CodeStyle\Rule\PayloadSentinelRule;
use Hilos\Tests\CodeStyle\Rule\PhpDocFqnRule;
use Hilos\Tests\CodeStyle\Rule\ProcessForkRule;
use Hilos\Tests\CodeStyle\Rule\RandomSourceRule;
use Hilos\Tests\CodeStyle\Rule\RtStateMutationRule;
use Hilos\Tests\CodeStyle\Rule\RtStateReachRule;
use Hilos\Tests\CodeStyle\Rule\SecretInQueryRule;
use Hilos\Tests\CodeStyle\Rule\SpellingRule;
use Hilos\Tests\CodeStyle\Rule\TestRollbackScopeRule;
use Hilos\Tests\CodeStyle\Rule\TruthSourceClaimRule;
use Hilos\Tests\CodeStyle\Rule\ViewWrapperBindingRule;
use Hilos\Tests\CodeStyle\Rule\WireKeyCaseRule;
use Hilos\Tests\CodeStyle\Rule\WiringRefusalSwallowedRule;
use Hilos\Tests\CodeStyle\RootKind;
use Hilos\Tests\CodeStyle\SourceScanner;
use Hilos\Tests\CodeStyle\Violation;
use PHPUnit\Framework\TestCase;

/**
 * Runs the code-style rules over the deliberately broken fixtures and pins the
 * exact expected report — both what must be caught and what must stay silent.
 *
 * The guard test leaves the fixture directory out of its scan, so this test is the
 * only thing that proves the rules still fire.
 */
final class RuleFixtureTest extends TestCase
{
    /**
     * Segments the fixture tree repeats from the real zone. Not all of them — a
     * segment that only adds another path proves nothing the ones here do not.
     * Each of these four stands for a decision the zone makes: a nested segment,
     * a top-level one, a segment added by a later phase, and `Socket`, taken whole
     * rather than by its subdirectories — the only one whose fixture sits DIRECTLY
     * in the segment, which is what tells a segment match from a prefix match.
     *
     * @var array<int, string>
     */
    private const array ZONE_SEGMENTS = ['Core/Router', 'Tables', 'Core/CLI', 'Socket'];

    /** Fixture root judged with no zone at all, kept out of the scan above. */
    private const string WHOLE_ROOT_FIXTURES = 'WholeRoot';

    public function testRulesReportExactlyTheSeededViolations(): void
    {
        $this->assertSame(
            [
                'CODE-FQN Bad/BlockingResolutionSamples.php:30 — \dns_get_mx is written out in code; a global '
                    . 'function or constant takes the short name and no import '
                    . '(see docs/agents/code-style/qualified-names.md)',
                // Line 32 is the userland function wearing a builtin's name: CODE-FQN judges how it is
                // written and BLOCKING-RESOLUTION says nothing, which is the whole point of seeding it.
                'CODE-FQN Bad/BlockingResolutionSamples.php:32 — '
                    . '\Hilos\Tests\CodeStyle\Fixtures\Bad\Resolver\gethostbyname is written out in code; '
                    . 'import it and use the short name '
                    . '(see docs/agents/code-style/qualified-names.md)',
                'BLOCKING-RESOLUTION Bad/BlockingResolutionSamples.php:26 — gethostbyname() blocks the '
                    . 'process until a nameserver answers; resolve the name outside the loop, or name this '
                    . 'file in the rule\'s list with a reason '
                    . '(see docs/agents/code-style/blocking-resolution.md)',
                'BLOCKING-RESOLUTION Bad/BlockingResolutionSamples.php:27 — gethostbynamel() blocks the '
                    . 'process until a nameserver answers; resolve the name outside the loop, or name this '
                    . 'file in the rule\'s list with a reason '
                    . '(see docs/agents/code-style/blocking-resolution.md)',
                'BLOCKING-RESOLUTION Bad/BlockingResolutionSamples.php:28 — gethostbyaddr() blocks the '
                    . 'process until a nameserver answers; resolve the name outside the loop, or name this '
                    . 'file in the rule\'s list with a reason '
                    . '(see docs/agents/code-style/blocking-resolution.md)',
                'BLOCKING-RESOLUTION Bad/BlockingResolutionSamples.php:29 — dns_get_record() blocks the '
                    . 'process until a nameserver answers; resolve the name outside the loop, or name this '
                    . 'file in the rule\'s list with a reason '
                    . '(see docs/agents/code-style/blocking-resolution.md)',
                'BLOCKING-RESOLUTION Bad/BlockingResolutionSamples.php:30 — dns_get_mx() blocks the '
                    . 'process until a nameserver answers; resolve the name outside the loop, or name this '
                    . 'file in the rule\'s list with a reason '
                    . '(see docs/agents/code-style/blocking-resolution.md)',
                'BLOCKING-RESOLUTION Bad/BlockingResolutionSamples.php:31 — checkdnsrr() blocks the '
                    . 'process until a nameserver answers; resolve the name outside the loop, or name this '
                    . 'file in the rule\'s list with a reason '
                    . '(see docs/agents/code-style/blocking-resolution.md)',
                'MALFORMED-INPUT-MARKER Bad/Cluster/Exception/UnmarkedRefusal.php:18 — UnmarkedRefusal is '
                    . 'declared where input is parsed and carries no MalformedInput; implement it, extend a '
                    . 'marked base, or name the class in the rule\'s exempt list with a reason '
                    . '(see docs/agents/code-style/exceptions.md)',
                'CODE-FQN Bad/CodeFqnSamples.php:12 — \RuntimeException is written out in code; import it '
                    . 'and use the short name (see docs/agents/code-style/qualified-names.md)',
                'CODE-FQN Bad/CodeFqnSamples.php:20 — \Hilos\Tests\CodeStyle\SourceScanner is written out in '
                    . 'code; import it and use the short name (see docs/agents/code-style/qualified-names.md)',
                'CODE-FQN Bad/CodeFqnSamples.php:21 — \Throwable is written out in code; import it '
                    . 'and use the short name (see docs/agents/code-style/qualified-names.md)',
                'CODE-FQN Bad/CodeFqnSamples.php:25 — \Hilos\Tests\CodeStyle\Baseline is written out in '
                    . 'code; import it and use the short name (see docs/agents/code-style/qualified-names.md)',
                'CODE-FQN Bad/CodeFqnSamples.php:37 — Rule\CodeFqnSample is written against the current '
                    . 'namespace; import it and use the short name '
                    . '(see docs/agents/code-style/qualified-names.md)',
                'CODE-FQN Bad/CodeFqnSamples.php:39 — \strlen is written out in code; a global function or '
                    . 'constant takes the short name and no import '
                    . '(see docs/agents/code-style/qualified-names.md)',
                'CODE-FQN Bad/CodeFqnUnresolved.php:21 — JsonException resolves to no class; '
                    . 'the import is missing (see docs/agents/code-style/qualified-names.md)',
                'EMPTY-STRING-SENTINEL Bad/Core/CLI/EmptySentinel.php:19 — ?? \'\' turns a missing value '
                    . 'into an empty string; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Core/Router/EmptySentinel.php:23 — ?? \'\' turns a missing value '
                    . 'into an empty string; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Core/Router/EmptySentinel.php:24 — ?? \'\' turns a missing value '
                    . 'into an empty string; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Core/Router/EmptySentinel.php:26 — ?? \'\' turns a missing value '
                    . 'into an empty string; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Core/Router/MatchDefault.php:23 — match falls back to \'\' '
                    . 'where the value is missing; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Core/Router/TernaryBranch.php:22 — a ternary branch hands back '
                    . '\'\' where the value is missing; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Core/Router/TernaryBranch.php:23 — a ternary branch hands back '
                    . '\'\' where the value is missing; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Core/Router/TernaryBranch.php:24 — a ternary branch hands back '
                    . '\'\' where the value is missing; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'COLLECTION-ITEM-NARROWING Bad/Database/Object/Collection/CollectionItemNarrowingSamples.php:19 — first() '
                    . 'does not name the item ObjectUser; declare `@method ObjectUser|null first()` '
                    . '(see docs/agents/orm/db-collection.md)',
                'COLLECTION-ITEM-NARROWING Bad/Database/Object/Collection/CollectionItemNarrowingSamples.php:19 — last() '
                    . 'does not name the item ObjectUser; declare `@method ObjectUser|null last()` '
                    . '(see docs/agents/orm/db-collection.md)',
                'COLLECTION-ITEM-NARROWING Bad/Database/Object/Collection/CollectionItemNarrowingSamples.php:19 — get() '
                    . 'does not name the item ObjectUser; declare `@method ObjectUser|null get(int|string $key)` '
                    . '(see docs/agents/orm/db-collection.md)',
                'COLLECTION-ITEM-NARROWING Bad/Database/Object/Collection/CollectionItemNarrowingSamples.php:35 — get() '
                    . 'names User instead of the item ObjectUser; declare `@method ObjectUser|null get(int|string $key)` '
                    . '(see docs/agents/orm/db-collection.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:16 — choices @param '
                    . '$choices list<CountryLocaleChoice>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:17 — choices @return '
                    . 'non-empty-list<CountryLocaleChoice>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:24 — suffix @return '
                    . 'CountryLocaleChoice[]: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:30 — keyed @return '
                    . 'array<string, CountryLocaleChoice|null>: use a typed collection (see '
                    . 'docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:36 — nullable @return '
                    . '?list<CountryLocaleChoice>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:39 — union @return '
                    . 'list<CountryLocaleChoice>|null: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:43 — attributed @return '
                    . 'array< int, CountryLocaleChoice|string|null >: use a typed collection (see '
                    . 'docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:51 — intersection @return '
                    . '(CountryLocaleChoice&object)[]: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:54 — genericArray @return '
                    . 'array<CountryLocaleChoice>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:57 — nonemptyArray @return '
                    . 'non-empty-array<int, CountryLocaleChoice>: use a typed collection (see '
                    . 'docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:60 — genericObject @return '
                    . 'list<CountryLocaleChoice<int>>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:63 — bounded @return '
                    . 'list<T>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:68 — localBound @return '
                    . 'list<U>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:72 — objects @return '
                    . 'list<object>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:75 — selves @return '
                    . 'self[]: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:78 — statics @return '
                    . 'list<static>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:81 — instances @return '
                    . 'array<$this>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:84 — lowercase @return '
                    . 'list<countryLocaleChoice>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:89 — fromArray @return '
                    . 'list<CountryLocaleChoice>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:94 — toArray @param '
                    . '$choices list<CountryLocaleChoice>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:99 — toArrayLike @return '
                    . 'list<CountryLocaleChoice>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:102 — fromArrayLike @param '
                    . '$choices list<CountryLocaleChoice>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:105 — parentheses @return '
                    . '(list<CountryLocaleChoice> | null): use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:108 — listIntersection '
                    . '@return list<CountryLocaleChoice> & iterable: use a typed collection (see '
                    . 'docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:111 — reference @param '
                    . '$choices CountryLocaleChoice[]: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:114 — variadic @param '
                    . '$choices list<$this>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:118 — continued @param '
                    . '$choices array<int, CountryLocaleChoice>: use a typed collection (see '
                    . 'docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:121 — continued @return '
                    . 'list<CountryLocaleChoice>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/Object/Collection/CollectionObjectListSamples.php:126 — branches @return '
                    . '(list<int>)|(list<CountryLocaleChoice>): use a typed collection (see '
                    . 'docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:11 — '
                    . 'entityClass()::get() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:12 — '
                    . 'entityClass()::count() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:13 — '
                    . 'entityClass()::get() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:14 — '
                    . 'entityClass()::count() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:15 — '
                    . 'entityClass()::get() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:16 — '
                    . 'entityClass()::count() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:17 — '
                    . 'entityClass()::get() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:21 — '
                    . 'entityClass()::get() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:21 — '
                    . 'entityClass()::count() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:23 — '
                    . 'entityClass()::get() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read; fresh-sql marker has no reason (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:24 — '
                    . 'entityClass()::count() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read; fresh-sql marker has no reason (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:26 — '
                    . 'entityClass()::get() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:27 — '
                    . 'entityClass()::count() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:29 — '
                    . 'entityClass()::get() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:30 — '
                    . 'entityClass()::count() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:31 — '
                    . 'entityClass()::get() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:32 — '
                    . 'entityClass()::count() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:33 — '
                    . 'entityClass()::get() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:36 — '
                    . 'entityClass()::count() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'COLLECTION-SQL-READ Bad/Database/Object/Collection/CollectionSqlReadSamples.php:37 — '
                    . 'entityClass()::get() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'COLLECTION-ITEM-NARROWING Bad/Database/View/Collection/CollectionItemNarrowingSamples.php:24 — '
                    . 'getObjectCollection() names ObjectSessions instead of the item ObjectUsers; '
                    . 'declare `@method ObjectUsers|null getObjectCollection()` (see docs/agents/orm/db-collection.md)',
                'COLLECTION-ITEM-NARROWING Bad/Database/View/Collection/CollectionItemNarrowingSamples.php:24 — first() '
                    . 'does not name the item User; declare `@method User|null first()` (see docs/agents/orm/db-collection.md)',
                'COLLECTION-ITEM-NARROWING Bad/Database/View/Collection/CollectionItemNarrowingSamples.php:24 — offsetGet() '
                    . 'names DbItem instead of the item User; declare `@method User|null offsetGet(mixed $offset)` '
                    . '(see docs/agents/orm/db-collection.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/View/Collection/CollectionObjectListSamples.php:12 — accept @param $choices '
                    . 'array<int, ChoiceDTO>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/View/Collection/CollectionObjectListSamples.php:15 — choices @return '
                    . 'list<ChoiceDTO>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/View/Collection/CollectionObjectListSamples.php:21 — choices @return '
                    . 'ChoiceDTO[]: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/View/Collection/CollectionObjectListSamples.php:28 — collect @param $choices '
                    . 'non-empty-array<string, ChoiceDTO>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-OBJECT-LIST Bad/Database/View/Collection/CollectionObjectListSamples.php:29 — collect @return '
                    . '(ChoiceDTO|null)[]: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'PAYLOAD-SENTINEL Bad/DiffReaderSentinelSamples.php:30 — ?? \'\' mints a stub where the diff '
                    . 'does not carry the key; an absent key means the field did not change, so read it with '
                    . 'patch* (see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/DiffReaderSentinelSamples.php:31 — optionalString() answers null to a '
                    . 'key the diff does not carry and clears a field it never touched; read it with its '
                    . 'patch* twin (see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/DiffReaderSentinelSamples.php:41 — match falls back to 0 where the diff '
                    . 'does not carry the key; an absent key means the field did not change, so read it with '
                    . 'patch* (see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/DiffReaderSentinelSamples.php:50 — ?? 0.0 mints a stub where the diff '
                    . 'does not carry the key; an absent key means the field did not change, so read it with '
                    . 'patch* (see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/DiffReaderSentinelSamples.php:51 — optionalInt() answers null to a key '
                    . 'the diff does not carry and clears a field it never touched; read it with its patch* '
                    . 'twin (see docs/agents/code-style/method-contracts.md)',
                'ERROR-SUPPRESSION Bad/ErrorSuppressionSamples.php:20 — @ silences a warning with no '
                    . '`// warning-suppressed:` marker on the line above '
                    . '(see docs/agents/code-style/error-suppression.md)',
                'ERROR-SUPPRESSION Bad/ErrorSuppressionSamples.php:22 — @ silences a warning with no '
                    . '`// warning-suppressed:` marker on the line above '
                    . '(see docs/agents/code-style/error-suppression.md)',
                'ERROR-SUPPRESSION Bad/ErrorSuppressionSamples.php:25 — the `// warning-suppressed:` marker '
                    . 'above the call names no reason (see docs/agents/code-style/error-suppression.md)',
                'ERROR-SUPPRESSION Bad/ErrorSuppressionSamples.php:29 — @ silences a warning with no '
                    . '`// warning-suppressed:` marker on the line above '
                    . '(see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamBypassSamples.php:28 — a file is opened under @ outside the Fs seam; '
                    . 'read it through Hilos\Fs\FsPath instead '
                    . '(see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamBypassSamples.php:35 — a suppressed file_get_contents() turns its failure '
                    . 'into an exception outside the Fs seam; call Hilos\Fs\FsPath and catch its Fs exception '
                    . '(see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamBypassSamples.php:50 — a suppressed unlink() turns its failure into an '
                    . 'exception outside the Fs seam; call Hilos\Fs\FsPath and catch its Fs exception '
                    . '(see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamBypassSamples.php:62 — a file is opened under @ outside the Fs seam; '
                    . 'read it through Hilos\Fs\FsPath instead '
                    . '(see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamBypassSamples.php:76 — a suppressed unlink() turns its failure into an '
                    . 'exception outside the Fs seam; call Hilos\Fs\FsPath and catch its Fs exception '
                    . '(see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamUncheckedSamples.php:29 — an unsuppressed mkdir() is checked for false, '
                    . 'a branch no Hilos process reaches: its warning ends the process first; call Hilos\Fs\FsPath '
                    . 'and catch its Fs exception (see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamUncheckedSamples.php:41 — an unsuppressed file_put_contents() is checked for false, '
                    . 'a branch no Hilos process reaches: its warning ends the process first; call Hilos\Fs\FsPath '
                    . 'and catch its Fs exception (see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamUncheckedSamples.php:54 — an unsuppressed fopen() is checked for false, '
                    . 'a branch no Hilos process reaches: its warning ends the process first; call Hilos\Fs\FsPath '
                    . 'and catch its Fs exception (see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamUncheckedSamples.php:67 — an unsuppressed scandir() is checked for false, '
                    . 'a branch no Hilos process reaches: its warning ends the process first; call Hilos\Fs\FsPath '
                    . 'and catch its Fs exception (see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamUncheckedSamples.php:76 — an unsuppressed filesize() is checked for false, '
                    . 'a branch no Hilos process reaches: its warning ends the process first; call Hilos\Fs\FsPath '
                    . 'and catch its Fs exception (see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamUncheckedSamples.php:85 — an unsuppressed unlink() is checked for false, '
                    . 'a branch no Hilos process reaches: its warning ends the process first; call Hilos\Fs\FsPath '
                    . 'and catch its Fs exception (see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamUncheckedSamples.php:98 — an unsuppressed mkdir() is checked for false, '
                    . 'a branch no Hilos process reaches: its warning ends the process first; call Hilos\Fs\FsPath '
                    . 'and catch its Fs exception (see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamUncheckedSamples.php:109 — an unsuppressed mkdir() is checked for false, '
                    . 'a branch no Hilos process reaches: its warning ends the process first; call Hilos\Fs\FsPath '
                    . 'and catch its Fs exception (see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamUncheckedSamples.php:121 — an unsuppressed touch() is checked for false, '
                    . 'a branch no Hilos process reaches: its warning ends the process first; call Hilos\Fs\FsPath '
                    . 'and catch its Fs exception (see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamUncheckedSamples.php:133 — an unsuppressed fopen() is checked for false, '
                    . 'a branch no Hilos process reaches: its warning ends the process first; call Hilos\Fs\FsPath '
                    . 'and catch its Fs exception (see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamUncheckedSamples.php:149 — an unsuppressed filesize() is checked for false, '
                    . 'a branch no Hilos process reaches: its warning ends the process first; call Hilos\Fs\FsPath '
                    . 'and catch its Fs exception (see docs/agents/code-style/error-suppression.md)',
                'LINE-LENGTH Bad/LineLengthSamples.php:15 — line is 158 characters, limit 150 '
                    . '(see docs/agents/code-style/line-length.md)',
                'LINE-LENGTH Bad/LineLengthSamples.php:21 — line is 154 characters, limit 150 '
                    . '(see docs/agents/code-style/line-length.md)',
                'LINE-LENGTH Bad/LineLengthSamples.php:23 — line is 167 characters, limit 150 '
                    . '(see docs/agents/code-style/line-length.md)',
                'MAGIC-REPEAT Bad/MagicRepeatSamples.php:21 — 1000 occurs 2 times in this file; name it '
                    . 'with a constant that carries the unit (see docs/agents/code-style/magic-values.md)',
                'MAGIC-REPEAT Bad/MagicRepeatSamples.php:21 — 1000 occurs 2 times in this file; name it '
                    . 'with a constant that carries the unit (see docs/agents/code-style/magic-values.md)',
                'MAGIC-REPEAT Bad/MagicRepeatSamples.php:30 — 2000.0 occurs 2 times in this file; name it '
                    . 'with a constant that carries the unit (see docs/agents/code-style/magic-values.md)',
                'MAGIC-REPEAT Bad/MagicRepeatSamples.php:38 — 2000.0 occurs 2 times in this file; name it '
                    . 'with a constant that carries the unit (see docs/agents/code-style/magic-values.md)',
                'MAGIC-REPEAT Bad/MagicRepeatSamples.php:49 — 3000 occurs 2 times in this file; name it '
                    . 'with a constant that carries the unit (see docs/agents/code-style/magic-values.md)',
                'MAGIC-REPEAT Bad/MagicRepeatSamples.php:49 — 3000 occurs 2 times in this file; name it '
                    . 'with a constant that carries the unit (see docs/agents/code-style/magic-values.md)',
                'MAGIC-REPEAT Bad/MagicRepeatSamples.php:61 — 4000 occurs 2 times in this file; name it '
                    . 'with a constant that carries the unit (see docs/agents/code-style/magic-values.md)',
                'MAGIC-REPEAT Bad/MagicRepeatSamples.php:62 — 4000 occurs 2 times in this file; name it '
                    . 'with a constant that carries the unit (see docs/agents/code-style/magic-values.md)',
                'MEMBER-INDENT Bad/MemberIndentSamples.php:21 — member line is indented 2 spaces; '
                    . 'the class body it sits in asks for 4 (see docs/code-style.md)',
                'MEMBER-INDENT Bad/MemberIndentSamples.php:32 — member line is indented 2 spaces; '
                    . 'the class body it sits in asks for 4 (see docs/code-style.md)',
                'MEMBER-INDENT Bad/MemberIndentSamples.php:34 — member line is indented with a tab; '
                    . 'the class body it sits in asks for 4 spaces (see docs/code-style.md)',
                'MEMBER-INDENT Bad/MemberIndentSamples.php:36 — member line is indented 2 spaces; '
                    . 'the class body it sits in asks for 4 (see docs/code-style.md)',
                'MEMBER-INDENT Bad/MemberIndentSamples.php:38 — member line is indented 6 spaces; '
                    . 'the class body it sits in asks for 4 (see docs/code-style.md)',
                'MEMBER-INDENT Bad/MemberIndentSamples.php:45 — member line is indented 2 spaces; '
                    . 'the class body it sits in asks for 4 (see docs/code-style.md)',
                'MEMBER-INDENT Bad/MemberIndentSamples.php:53 — member line is indented 8 spaces; '
                    . 'the class body it sits in asks for 4 (see docs/code-style.md)',
                'MEMBER-INDENT Bad/MemberIndentSamples.php:57 — member line is indented 2 spaces; '
                    . 'the class body it sits in asks for 4 (see docs/code-style.md)',
                'MEMBER-INDENT Bad/MemberIndentSamples.php:67 — member line is indented 16 spaces; '
                    . 'the class body it sits in asks for 12 (see docs/code-style.md)',
                'MEMBER-INDENT Bad/MemberIndentSamples.php:79 — member line is indented 16 spaces; '
                    . 'the class body it sits in asks for 12 (see docs/code-style.md)',
                'PAYLOAD-SENTINEL Bad/PayloadMarkerWithoutReason.php:28 — the `// external-boundary:` '
                    . 'marker above the fallback names no reason '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/PayloadSentinelSamples.php:33 — ?? \'\' mints a stub where the payload '
                    . 'field is missing; refuse the payload or let the field be null '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/PayloadSentinelSamples.php:34 — ?? 0 mints a stub where the payload '
                    . 'field is missing; refuse the payload or let the field be null '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/PayloadSentinelSamples.php:35 — ?? 0.0 mints a stub where the payload '
                    . 'field is missing; refuse the payload or let the field be null '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/PayloadSentinelSamples.php:47 — a ternary branch hands back "" where '
                    . 'the payload field is missing; refuse the payload or let the field be null '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/PayloadSentinelSamples.php:50 — match falls back to 0 where the payload '
                    . 'field is missing; refuse the payload or let the field be null '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:13 — @property-read references \Hilos\Core\Hilos '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:14 — @method references \Hilos\Tests\CodeStyle\Violation '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:14 — @method references \SplFileInfo '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:15 — @extends references \Hilos\Tests\CodeStyle\Baseline '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:15 — @extends references \SplFileInfo '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:16 — @implements references '
                    . '\Hilos\Tests\CodeStyle\CodeStyleRule '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:21 — {@see} references \Hilos\Tests\CodeStyle\SourceScanner '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:23 — @var references \SplFileInfo '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:28 — @param references \DateTimeImmutable '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:29 — @return references \Hilos\Tests\CodeStyle\Violation '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:30 — @throws references \OutOfBoundsException '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:41 — @return references \Socket '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:41 — @return references \Socket '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:50 — {@see} references Rule\PhpDocFqnRule '
                    . 'relative to the current namespace (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:51 — {@see} references Baseline, which is neither '
                    . 'imported nor declared in this namespace (see docs/agents/code-style/phpdoc.md)',
                'CODE-FQN Bad/ProcessForkSamples.php:26 — \pcntl_fork is written out in code; a global '
                    . 'function or constant takes the short name and no import '
                    . '(see docs/agents/code-style/qualified-names.md)',
                // Line 28 is the userland function wearing a builtin's name: CODE-FQN judges how it is
                // written and PROCESS-FORK says nothing, which is the whole point of seeding it.
                'CODE-FQN Bad/ProcessForkSamples.php:28 — '
                    . '\Hilos\Tests\CodeStyle\Fixtures\Bad\Forker\pcntl_fork is written out in code; '
                    . 'import it and use the short name '
                    . '(see docs/agents/code-style/qualified-names.md)',
                'PROCESS-FORK Bad/ProcessForkSamples.php:25 — pcntl_fork() forks the PHP process, and the '
                    . 'child inherits everything this one holds — the live PHPUnit run, the daemon sockets, '
                    . 'the database connection; start a process of its own through Hilos\Core\Process, '
                    . 'or name this file in the rule\'s list with the leaf where the owner allowed it '
                    . '(see docs/agents/code-style/process-fork.md)',
                'PROCESS-FORK Bad/ProcessForkSamples.php:26 — pcntl_fork() forks the PHP process, and the '
                    . 'child inherits everything this one holds — the live PHPUnit run, the daemon sockets, '
                    . 'the database connection; start a process of its own through Hilos\Core\Process, '
                    . 'or name this file in the rule\'s list with the leaf where the owner allowed it '
                    . '(see docs/agents/code-style/process-fork.md)',
                'PROCESS-FORK Bad/ProcessForkSamples.php:27 — pcntl_rfork() forks the PHP process, and the '
                    . 'child inherits everything this one holds — the live PHPUnit run, the daemon sockets, '
                    . 'the database connection; start a process of its own through Hilos\Core\Process, '
                    . 'or name this file in the rule\'s list with the leaf where the owner allowed it '
                    . '(see docs/agents/code-style/process-fork.md)',
                'CODE-FQN Bad/RandomSourceSamples.php:25 — \Hilos\Utils\Helpers\RandomHelper is written out '
                    . 'in code; import it and use the short name (see docs/agents/code-style/qualified-names.md)',
                'RANDOM-SOURCE Bad/RandomSourceSamples.php:23 — RandomHelper::bytes() falls back to '
                    . 'pseudorandom; a secret takes secureBytes()/secureHex(), and a value that only has to '
                    . 'be unique takes this file into the rule\'s list '
                    . '(see docs/agents/code-style/random-source.md)',
                'RANDOM-SOURCE Bad/RandomSourceSamples.php:24 — RandomHelper::hex() falls back to '
                    . 'pseudorandom; a secret takes secureBytes()/secureHex(), and a value that only has to '
                    . 'be unique takes this file into the rule\'s list '
                    . '(see docs/agents/code-style/random-source.md)',
                'RANDOM-SOURCE Bad/RandomSourceSamples.php:25 — RandomHelper::integer() falls back to '
                    . 'pseudorandom; a secret takes secureBytes()/secureHex(), and a value that only has to '
                    . 'be unique takes this file into the rule\'s list '
                    . '(see docs/agents/code-style/random-source.md)',
                'PAYLOAD-SENTINEL Bad/RowReaderSentinelSamples.php:28 — ?? \'\' mints a stub where the '
                    . 'payload field is missing; refuse the payload or let the field be null '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/RowReaderSentinelSamples.php:38 — ?? 0 mints a stub where the payload '
                    . 'field is missing; refuse the payload or let the field be null '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/RowReaderSentinelSamples.php:46 — a ternary branch hands back "" where '
                    . 'the payload field is missing; refuse the payload or let the field be null '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'RT-STATE-REACH Bad/RtStateReach.php:22 — getStateCollection() reaches backing RT state '
                    . 'outside Database/ and Runtime/ (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-REACH Bad/RtStateReach.php:23 — getStateItem() reaches backing RT state '
                    . 'outside Database/ and Runtime/ (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-REACH Bad/RtStateReach.php:24 — getStateItem() reaches backing RT state '
                    . 'outside Database/ and Runtime/ (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-REACH Bad/RtStateReach.php:25 — $this->stateCollection reaches backing RT state '
                    . 'outside Database/ and Runtime/ (see docs/agents/runtime/rt-state.md)',
                'COLLECTION-ITEM-NARROWING Bad/Runtime/State/Collection/CollectionItemNarrowingSamples.php:21 — get() '
                    . 'does not name the state AuthAttempt; declare it as a method: get(?string $id): ?AuthAttempt, '
                    . 'offsetGet(mixed $offset): AuthAttempt that throws when the row is missing '
                    . '(docs/agents/runtime/rt-state.md) (see docs/agents/orm/db-collection.md)',
                'COLLECTION-ITEM-NARROWING Bad/Runtime/State/Collection/CollectionItemNarrowingSamples.php:21 — offsetGet() '
                    . 'does not name the state AuthAttempt; declare it as a method: get(?string $id): ?AuthAttempt, '
                    . 'offsetGet(mixed $offset): AuthAttempt that throws when the row is missing '
                    . '(docs/agents/runtime/rt-state.md) (see docs/agents/orm/db-collection.md)',
                'COLLECTION-ITEM-NARROWING Bad/Runtime/State/Collection/CollectionItemNarrowingSamples.php:31 — get() '
                    . 'does not name the state AuthAttempt; declare it as a method: get(?string $id): ?AuthAttempt, '
                    . 'offsetGet(mixed $offset): AuthAttempt that throws when the row is missing '
                    . '(docs/agents/runtime/rt-state.md) (see docs/agents/orm/db-collection.md)',
                'COLLECTION-ITEM-NARROWING Bad/Runtime/State/Collection/CollectionItemNarrowingSamples.php:52 — get() '
                    . 'names RtState instead of the state AuthAttempt; declare it as a method: get(?string $id): ?AuthAttempt, '
                    . 'offsetGet(mixed $offset): AuthAttempt that throws when the row is missing '
                    . '(docs/agents/runtime/rt-state.md) (see docs/agents/orm/db-collection.md)',
                'COLLECTION-ITEM-NARROWING Bad/Runtime/State/Collection/CollectionItemNarrowingSamples.php:52 — offsetGet() '
                    . 'names RtState instead of the state AuthAttempt; declare it as a method: get(?string $id): ?AuthAttempt, '
                    . 'offsetGet(mixed $offset): AuthAttempt that throws when the row is missing '
                    . '(docs/agents/runtime/rt-state.md) (see docs/agents/orm/db-collection.md)',
                'SECRET-IN-QUERY Bad/SecretInQuerySamples.php:24 — query param '
                    . 'HilosHttpHeaders::HILOS_SESSION_TOKEN is read from the url; a session token or any '
                    . 'other secret arrives in a cookie or a header '
                    . '(see docs/agents/antipatterns/secret-in-query.md)',
                'SECRET-IN-QUERY Bad/SecretInQuerySamples.php:25 — query param \'token\' is read from the '
                    . 'url; a session token or any other secret arrives in a cookie or a header '
                    . '(see docs/agents/antipatterns/secret-in-query.md)',
                'SECRET-IN-QUERY Bad/SecretInQuerySamples.php:26 — query param \'code\' is read from the '
                    . 'url; a session token or any other secret arrives in a cookie or a header '
                    . '(see docs/agents/antipatterns/secret-in-query.md)',
                'SECRET-IN-QUERY Bad/SecretInQuerySamples.php:27 — query param \'invite\' is read from the '
                    . 'url; a session token or any other secret arrives in a cookie or a header '
                    . '(see docs/agents/antipatterns/secret-in-query.md)',
                'EMPTY-STRING-SENTINEL Bad/Socket/EmptySentinel.php:25 — ?? \'\' turns a missing value '
                    . 'into an empty string; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'MALFORMED-INPUT-MARKER Bad/Socket/WebSocket/WebSocketException.php:19 — WebSocketException '
                    . 'is a base its children inherit the marker through, and its own declaration no longer '
                    . 'implements MalformedInput (see docs/agents/code-style/exceptions.md)',
                'SPELLING Bad/SpellingSamples.php:19 — write "color", not "colour"'
                    . ' (see docs/agents/code-style/spelling.md)',
                'SPELLING Bad/SpellingSamples.php:20 — write "COLOR", not "COLOUR"'
                    . ' (see docs/agents/code-style/spelling.md)',
                'SPELLING Bad/SpellingSamples.php:23 — write "CANCELED", not "CANCELLED"'
                    . ' (see docs/agents/code-style/spelling.md)',
                'SPELLING Bad/SpellingSamples.php:23 — write "canceled", not "cancelled"'
                    . ' (see docs/agents/code-style/spelling.md)',
                'SPELLING Bad/SpellingSamples.php:26 — write "behavior", not "behaviour"'
                    . ' (see docs/agents/code-style/spelling.md)',
                'SPELLING Bad/SpellingSamples.php:27 — write "license", not "licence"'
                    . ' (see docs/agents/code-style/spelling.md)',
                'SPELLING Bad/SpellingSamples.php:27 — write "organized", not "organised"'
                    . ' (see docs/agents/code-style/spelling.md)',
                'SPELLING Bad/SpellingSamples.php:29 — write "serialize", not "serialise"'
                    . ' (see docs/agents/code-style/spelling.md)',
                'SPELLING Bad/SpellingSamples.php:29 — write "Behavior", not "Behaviour"'
                    . ' (see docs/agents/code-style/spelling.md)',
                'SPELLING Bad/SpellingSamples.php:29 — write "behavior", not "behaviour"'
                    . ' (see docs/agents/code-style/spelling.md)',
                'SPELLING Bad/SpellingSamples.php:31 — write "behavioral", not "behavioural"'
                    . ' (see docs/agents/code-style/spelling.md)',
                'SPELLING Bad/SpellingSamples.php:32 — write "Color", not "Colour"'
                    . ' (see docs/agents/code-style/spelling.md)',
                'SPELLING Bad/SpellingSamples.php:32 — write "behavior", not "behaviour"'
                    . ' (see docs/agents/code-style/spelling.md)',
                'SPELLING Bad/SpellingSamples.php:32 — write "serializes", not "serialises"'
                    . ' (see docs/agents/code-style/spelling.md)',
                'SPELLING Bad/SpellingSamples.php:32 — write "COLOR", not "COLOUR"'
                    . ' (see docs/agents/code-style/spelling.md)',
                'SPELLING Bad/SpellingSamples.php:34 — write "Color", not "Colour"'
                    . ' (see docs/agents/code-style/spelling.md)',
                'EMPTY-STRING-SENTINEL Bad/Tables/EmptySentinel.php:20 — ?? \'\' turns a missing value '
                    . 'into an empty string; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Tables/MarkerWithoutReason.php:22 — the `// external-boundary:` '
                    . 'marker above the fallback names no reason '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Tables/MarkerWithoutReason.php:32 — the `// external-boundary:` '
                    . 'marker above the fallback names no reason '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'TRUTH-SOURCE-CLAIM Bad/TruthSourceClaimSamples.php:24 — TruthSourceRegistry::register() '
                    . 'declares ownership in a call; name the collection in'
                    . ' OWNS_DB or OWNS_RT on the class, which the resolver reads before the instance exists '
                    . '(see docs/agents/architecture/truth-source.md)',
                'TRUTH-SOURCE-CLAIM Bad/TruthSourceClaimSamples.php:25 — RtTruthSourceRegistry::register() '
                    . 'declares ownership in a call; name the collection in'
                    . ' OWNS_DB or OWNS_RT on the class, which the resolver reads before the instance exists '
                    . '(see docs/agents/architecture/truth-source.md)',
                'TRUTH-SOURCE-CLAIM Bad/TruthSourceClaimSamples.php:26 — AbstractTruthSourceRegistry::register() '
                    . 'declares ownership in a call; name the collection in'
                    . ' OWNS_DB or OWNS_RT on the class, which the resolver reads before the instance exists '
                    . '(see docs/agents/architecture/truth-source.md)',
                'TRUTH-SOURCE-CLAIM Bad/TruthSourceClaimSamples.php:42 — self::register() '
                    . 'declares ownership in a call; name the collection in'
                    . ' OWNS_DB or OWNS_RT on the class, which the resolver reads before the instance exists '
                    . '(see docs/agents/architecture/truth-source.md)',
                'WIRE-KEY-CASE Bad/WireKeyCaseSamples.php:15 — field key \'created_at\' is not camelCase; '
                    . 'one spelling has to serve PHP, the wire and TS '
                    . '(see docs/agents/code-style/cross-layer-field-names.md)',
                'WIRE-KEY-CASE Bad/WireKeyCaseSamples.php:17 — field key \'ValueSource\' is not camelCase; '
                    . 'one spelling has to serve PHP, the wire and TS '
                    . '(see docs/agents/code-style/cross-layer-field-names.md)',
                'WIRE-KEY-CASE Bad/WireKeyCaseSamples.php:19 — field key \'override_value\' is not camelCase; '
                    . 'one spelling has to serve PHP, the wire and TS '
                    . '(see docs/agents/code-style/cross-layer-field-names.md)',
                'WIRE-KEY-CASE Bad/WireKeyCaseSamples.php:19 — field key \'default_value\' is not camelCase; '
                    . 'one spelling has to serve PHP, the wire and TS '
                    . '(see docs/agents/code-style/cross-layer-field-names.md)',
                'WIRE-KEY-CASE Bad/WireKeyCaseSamples.php:21 — field key \'default_kind\' is not camelCase; '
                    . 'one spelling has to serve PHP, the wire and TS '
                    . '(see docs/agents/code-style/cross-layer-field-names.md)',
                'WIRING-REFUSAL-SWALLOWED Bad/WiringRefusalSamples.php:29 — catch (Throwable) swallows a '
                    . 'read of Hilos::$db / Hilos::$rt; narrow it, rethrow WiringRefusal above it, or '
                    . 'mark it with a reason (see docs/agents/code-style/wiring-refusals.md)',
                'WIRING-REFUSAL-SWALLOWED Bad/WiringRefusalSamples.php:43 — catch (Exception) swallows a '
                    . 'read of Hilos::$db / Hilos::$rt; narrow it, rethrow WiringRefusal above it, or '
                    . 'mark it with a reason (see docs/agents/code-style/wiring-refusals.md)',
                'WIRING-REFUSAL-SWALLOWED Bad/WiringRefusalSamples.php:62 — catch (HilosException) swallows a '
                    . 'read of Hilos::$db / Hilos::$rt; narrow it, rethrow WiringRefusal above it, or '
                    . 'mark it with a reason (see docs/agents/code-style/wiring-refusals.md)',
                'WIRING-REFUSAL-SWALLOWED Bad/WiringRefusalSamples.php:77 — the '
                    . '`// read-refusal-swallowed:` marker above the catch names no reason '
                    . '(see docs/agents/code-style/wiring-refusals.md)',
                'WIRING-REFUSAL-SWALLOWED Bad/WiringRefusalSamples.php:92 — catch (Throwable) swallows a '
                    . 'read of Hilos::$db / Hilos::$rt; narrow it, rethrow WiringRefusal above it, or '
                    . 'mark it with a reason (see docs/agents/code-style/wiring-refusals.md)',
                'WIRING-REFUSAL-SWALLOWED Bad/WiringRefusalSamples.php:108 — catch (Throwable) swallows a '
                    . 'read of Hilos::$db / Hilos::$rt; narrow it, rethrow WiringRefusal above it, or '
                    . 'mark it with a reason (see docs/agents/code-style/wiring-refusals.md)',
                'DB-OBJECT-MUTATE Database/Object/Collection/ObjectStoreMutate.php:26 — $this->objects is '
                    . 'written directly outside Objects; go through $this[$id] = $object for a new row, or '
                    . 'hydrate() for a row read out of storage (see docs/agents/orm/object.md)',
                'DB-OBJECT-MUTATE Database/Object/Collection/ObjectStoreMutate.php:27 — $this->objects is '
                    . 'written directly outside Objects; go through $this[$id] = $object for a new row, or '
                    . 'hydrate() for a row read out of storage (see docs/agents/orm/object.md)',
                'DB-OBJECT-MUTATE Database/Object/Collection/ObjectStoreMutate.php:28 — $this->objects is '
                    . 'written directly outside Objects; go through $this[$id] = $object for a new row, or '
                    . 'hydrate() for a row read out of storage (see docs/agents/orm/object.md)',
                'DB-OBJECT-MUTATE Database/Object/Collection/ObjectStoreMutate.php:29 — unset() drops a row of '
                    . 'the object store directly; use unset($this[$id]), which announces the loss and lets the '
                    . 'view drop its wrapper (see docs/agents/orm/object.md)',
                'DB-OBJECT-MUTATE Database/Object/Collection/ObjectStoreMutate.php:30 — $this->objects is '
                    . 'written directly outside Objects; go through $this[$id] = $object for a new row, or '
                    . 'hydrate() for a row read out of storage (see docs/agents/orm/object.md)',
                'DB-OBJECT-MUTATE Database/Object/Collection/ObjectStoreMutate.php:31 — $this->objects is '
                    . 'written directly outside Objects; go through $this[$id] = $object for a new row, or '
                    . 'hydrate() for a row read out of storage (see docs/agents/orm/object.md)',
                'COLLECTION-OBJECT-LIST Database/Object/Collection/ObjectStoreMutateLookAlikes.php:55 — localNamedLikeTheStore '
                    . '@return array<int, Object_>: use a typed collection (see docs/agents/code-style/internal-backend-api.md)',
                'COLLECTION-SQL-READ Database/Object/Collection/OrmChainLookAlikes.php:39 — '
                    . 'entityClass()::get() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)',
                'CODE-FQN Database/Object/Collection/OrmChainSamples.php:48 — '
                    . '\Hilos\Database\Object\Item\AccountDeletion is written out in code; import it and use the '
                    . 'short name (see docs/agents/code-style/qualified-names.md)',
                'ORM-CHAIN-OPEN Database/Object/Collection/OrmChainSamples.php:27 — OrmChainSamples is final; a '
                    . 'framework ORM class is designed for inheritance, and a project extends its table by '
                    . 'subclassing the whole chain — drop final (see docs/agents/orm/inheritance.md)',
                'ORM-CHAIN-OPEN Database/Object/Collection/OrmChainSamples.php:38 — EntityAccountDeletion::getById() '
                    . 'builds a framework chain class by name; build through the link '
                    . 'constants — static::OBJECT_CLASS, static::entityClass() — so a subclass mounted under the '
                    . 'framework key is what gets built (see docs/agents/orm/inheritance.md)',
                'ORM-CHAIN-OPEN Database/Object/Collection/OrmChainSamples.php:41 — ObjectAccountDeletion::create() '
                    . 'builds a framework chain class by name; build through the link '
                    . 'constants — static::OBJECT_CLASS, static::entityClass() — so a subclass mounted under the '
                    . 'framework key is what gets built (see docs/agents/orm/inheritance.md)',
                'ORM-CHAIN-OPEN Database/Object/Collection/OrmChainSamples.php:42 — ObjectAccountDeletion::fromEntity() '
                    . 'builds a framework chain class by name; build through the link '
                    . 'constants — static::OBJECT_CLASS, static::entityClass() — so a subclass mounted under the '
                    . 'framework key is what gets built (see docs/agents/orm/inheritance.md)',
                'ORM-CHAIN-OPEN Database/Object/Collection/OrmChainSamples.php:43 — EntityAccountDeletion::get() '
                    . 'builds a framework chain class by name; build through the link '
                    . 'constants — static::OBJECT_CLASS, static::entityClass() — so a subclass mounted under the '
                    . 'framework key is what gets built (see docs/agents/orm/inheritance.md)',
                'ORM-CHAIN-OPEN Database/Object/Collection/OrmChainSamples.php:44 — EntityAccountDeletion::getAll() '
                    . 'builds a framework chain class by name; build through the link '
                    . 'constants — static::OBJECT_CLASS, static::entityClass() — so a subclass mounted under the '
                    . 'framework key is what gets built (see docs/agents/orm/inheritance.md)',
                'ORM-CHAIN-OPEN Database/Object/Collection/OrmChainSamples.php:45 — EntityAccountDeletion::getEmpty() '
                    . 'builds a framework chain class by name; build through the link '
                    . 'constants — static::OBJECT_CLASS, static::entityClass() — so a subclass mounted under the '
                    . 'framework key is what gets built (see docs/agents/orm/inheritance.md)',
                'ORM-CHAIN-OPEN Database/Object/Collection/OrmChainSamples.php:46 — EntityAccountDeletion::fromRow() '
                    . 'builds a framework chain class by name; build through the link '
                    . 'constants — static::OBJECT_CLASS, static::entityClass() — so a subclass mounted under the '
                    . 'framework key is what gets built (see docs/agents/orm/inheritance.md)',
                'ORM-CHAIN-OPEN Database/Object/Collection/OrmChainSamples.php:47 — new EntityAccountDeletion() '
                    . 'builds a framework chain class by name; build through the link '
                    . 'constants — static::OBJECT_CLASS, static::entityClass() — so a subclass mounted under the '
                    . 'framework key is what gets built (see docs/agents/orm/inheritance.md)',
                'ORM-CHAIN-OPEN Database/Object/Collection/OrmChainSamples.php:48 — \Hilos\Database\Object\Item\AccountDeletion::create() '
                    . 'builds a framework chain class by name; build through the link '
                    . 'constants — static::OBJECT_CLASS, static::entityClass() — so a subclass mounted under the '
                    . 'framework key is what gets built (see docs/agents/orm/inheritance.md)',
                // Storage first and signatures after, because the rule walks the file twice: the two
                // halves are reported in the order they are checked, not in line order.
                'VIEW-WRAPPER-BIND Database/View/Item/ByRefWrapperSamples.php:25 — $this->_object is bound to '
                    . 'a variable by reference; assign the value, so the wrapper keeps the row it was built '
                    . 'from (see docs/agents/orm/collection-iteration.md)',
                'VIEW-WRAPPER-BIND Database/View/Item/ByRefWrapperSamples.php:36 — $this->_object is bound to '
                    . 'a variable by reference; assign the value, so the wrapper keeps the row it was built '
                    . 'from (see docs/agents/orm/collection-iteration.md)',
                'VIEW-WRAPPER-BIND Database/View/Item/ByRefWrapperSamples.php:23 — $object is declared by '
                    . 'reference in the wrapper layer; take the value, so no caller has to hand over a '
                    . 'variable (see docs/agents/orm/collection-iteration.md)',
                'VIEW-WRAPPER-BIND Database/View/Item/ByRefWrapperSamples.php:34 — $object is declared by '
                    . 'reference in the wrapper layer; take the value, so no caller has to hand over a '
                    . 'variable (see docs/agents/orm/collection-iteration.md)',
                'RT-STATE-MUTATE Runtime/State/Collection/StateMutateSubclass.php:25 — $this->states is written '
                    . 'directly outside RtStates; go through add(), remove() or clear() '
                    . '(see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/State/Collection/StateMutateSubclass.php:26 — $this->states is written '
                    . 'directly outside RtStates; go through add(), remove() or clear() '
                    . '(see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/State/Collection/StateMutateSubclass.php:27 — $this->states is written '
                    . 'directly outside RtStates; go through add(), remove() or clear() '
                    . '(see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/State/Collection/StateMutateSubclass.php:28 — $this->states is written '
                    . 'directly outside RtStates; go through add(), remove() or clear() '
                    . '(see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/State/Collection/StateMutateSubclass.php:29 — $this->states is written '
                    . 'directly outside RtStates; go through add(), remove() or clear() '
                    . '(see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:23 — add() mutates the backing '
                    . 'RT state collection outside the base actions; call the base RtActions method instead '
                    . '(see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:24 — remove() mutates the '
                    . 'backing RT state collection outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:25 — clear() mutates the '
                    . 'backing RT state collection outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:37 — add() mutates the backing '
                    . 'RT state collection outside the base actions; call the base RtActions method instead '
                    . '(see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:38 — unset() drops a key of '
                    . 'the backing RT state collection outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:47 — add() mutates the backing '
                    . 'RT state collection outside the base actions; call the base RtActions method instead '
                    . '(see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:48 — remove() mutates the '
                    . 'backing RT state collection outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:49 — clear() mutates the '
                    . 'backing RT state collection outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:59 — add() mutates the backing '
                    . 'RT state collection outside the base actions; call the base RtActions method instead '
                    . '(see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:60 — remove() mutates the '
                    . 'backing RT state collection outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:61 — clear() mutates the '
                    . 'backing RT state collection outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:62 — a key of the backing RT '
                    . 'state collection is written outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:63 — unset() drops a key of '
                    . 'the backing RT state collection outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:64 — a key of the backing RT '
                    . 'state collection is written outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:78 — add() mutates the backing '
                    . 'RT state collection outside the base actions; call the base RtActions method instead '
                    . '(see docs/agents/runtime/rt-state.md)',
                'VIEW-WRAPPER-BIND Runtime/View/Collection/ByRefFactorySamples.php:27 — '
                    . '$this->_stateCollection is bound to a variable by reference; assign the value, so the '
                    . 'wrapper keeps the row it was built from (see docs/agents/orm/collection-iteration.md)',
                'VIEW-WRAPPER-BIND Runtime/View/Collection/ByRefFactorySamples.php:25 — $stateCollection is '
                    . 'declared by reference in the wrapper layer; take the value, so no caller has to hand '
                    . 'over a variable (see docs/agents/orm/collection-iteration.md)',
                'VIEW-WRAPPER-BIND Runtime/View/Collection/ByRefFactorySamples.php:34 — $state is declared by '
                    . 'reference in the wrapper layer; take the value, so no caller has to hand over a '
                    . 'variable (see docs/agents/orm/collection-iteration.md)',
                // The closure's parameter, seeded to prove that a factory handed over as a callback is
                // read the same way a method is; a `use (&$captured)` clause is not, and Good/ByRefLookAlikes
                // stays absent from this report for that reason.
                'VIEW-WRAPPER-BIND Runtime/View/Collection/ByRefFactorySamples.php:47 — $state is declared by '
                    . 'reference in the wrapper layer; take the value, so no caller has to hand over a '
                    . 'variable (see docs/agents/orm/collection-iteration.md)',
                'CODE-FQN ThrowsTree/Integration/tests/Cases.php:79 — \Hilos\Testing\IntegrationTest is written out in code; '
                    . 'import it and use the short name (see docs/agents/code-style/qualified-names.md)',
                'CODE-FQN ThrowsTree/Integration/tests/Cases.php:82 — \Hilos\Testing\BreaksOrmState is written out in code; '
                    . 'import it and use the short name (see docs/agents/code-style/qualified-names.md)',
                'EMPTY-STRING-SENTINEL WholeRoot/Playground/JudgedAnyway.php:22 — ?? \'\' turns a missing '
                    . 'value into an empty string; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
            ],
            $this->reportFixtureViolations(),
            'Fixture report drifted: a rule either stopped catching a seeded case or started '
                . 'reporting a legitimate one.',
        );
    }

    /**
     * The third sign is withheld from a standalone root by the rule itself: handed that
     * kind, it reports nothing over the file every shape of the sign is seeded in, while
     * the production kind reports each of them.
     */
    public function testAStandaloneRootIsNotJudgedByTheThirdSign(): void
    {
        $relativePath = 'Bad/FsSeamUncheckedSamples.php';
        $tokens = token_get_all((string)file_get_contents($this->fixtureRoot() . '/' . $relativePath));

        $standalone = iterator_to_array(new FsSeamRule(RootKind::Standalone)->check($relativePath, $tokens), false);
        $production = iterator_to_array(new FsSeamRule(RootKind::Production)->check($relativePath, $tokens), false);

        $this->assertSame([], $standalone);
        $this->assertNotSame([], $production);
    }

    /**
     * The nestable mark is judged by root: every call of it in the framework backend is a hit,
     * a demo's backend and the framework's own suite are handed an empty report, and the
     * declaration of the method itself is not a call under any root.
     */
    public function testTheNestableMarkIsAHitInTheFrameworkBackendAlone(): void
    {
        $relativePath = 'Bad/NestableTransactionSamples.php';
        $tokens = token_get_all((string)file_get_contents($this->fixtureRoot() . '/' . $relativePath));

        $framework = iterator_to_array(new NestableTransactionRule('framework/backend')->check($relativePath, $tokens), false);
        $demo = iterator_to_array(new NestableTransactionRule('demo/chat/backend')->check($relativePath, $tokens), false);
        $suite = iterator_to_array(new NestableTransactionRule('framework/tests')->check($relativePath, $tokens), false);

        $this->assertSame(
            [23, 24, 25],
            array_map(static fn(Violation $violation): int => $violation->line, $framework),
            'The static, the instance and the nullsafe call are hits; the declaration below them is not',
        );
        $this->assertSame([], $demo);
        $this->assertSame([], $suite);
    }

    /** Scope entry is admitted by explicit suite kind or the exact common harness path only. */
    public function testRollbackScopeEntryUsesTheRootAndExactHarnessPath(): void
    {
        $bad = token_get_all((string)file_get_contents($this->fixtureRoot() . '/Bad/TestRollbackScopeSamples.php'));
        $good = token_get_all((string)file_get_contents($this->fixtureRoot() . '/Good/TestRollbackScopeSamples.php'));
        foreach ([
            ['framework/backend', RootKind::Production, 'Other.php', [13, 14, 15, 16]],
            ['demo/chat/backend', RootKind::Production, 'Other.php', [13, 14, 15, 16]],
            ['scripts', RootKind::Standalone, 'Other.php', [13, 14, 15, 16]],
            ['framework/tests', RootKind::Suite, 'Other.php', []],
            ['demo/chat/tests', RootKind::Suite, 'Other.php', []],
            ['arbitrary/root', RootKind::Suite, 'Other.php', []],
            ['framework/tests', RootKind::Production, 'Other.php', [13, 14, 15, 16]],
            ['framework/backend', RootKind::Production, 'Testing/RollbackIntegrationTestCase.php', []],
            ['demo/chat/backend', RootKind::Production, 'Testing/RollbackIntegrationTestCase.php', [13, 14, 15, 16]],
            ['framework/backend', RootKind::Production, 'Other/Testing/RollbackIntegrationTestCase.php', [13, 14, 15, 16]],
        ] as [$root, $kind, $path, $lines]) {
            $rule = new TestRollbackScopeRule($root, $kind);
            self::assertSame('TEST-ROLLBACK-SCOPE', $rule->id());
            self::assertSame('docs/agents/testing/integration-tests.md', $rule->doc());
            $hits = iterator_to_array($rule->check($path, $bad), false);
            self::assertSame(array_map(static fn(int $line): string =>
                'TEST-ROLLBACK-SCOPE ' . $path . ':' . $line . ' — beginRollbackScopeForTest() belongs to the rollback harness '
                    . 'or a technical test root (see docs/agents/testing/integration-tests.md)', $lines),
                array_map(static fn(Violation $hit): string => $hit->describe($rule->doc()), $hits), $root . '/' . $path);
            self::assertSame([], iterator_to_array($rule->check($path, $good), false));
        }
    }

    /** The same tokens earn a verdict from their root and whole path segments alone. */
    public function testCollectionObjectListsAreJudgedOnlyInProductionCollectionLayers(): void
    {
        $tokens = token_get_all((string)file_get_contents(
            $this->fixtureRoot() . '/Bad/Database/Object/Collection/CollectionObjectListSamples.php',
        ));
        $lines = [16, 17, 24, 30, 36, 39, 43, 51, 54, 57, 60, 63, 68, 72, 75, 78, 81, 84, 89, 94, 99, 102, 105, 108, 111, 114, 118, 121, 126];
        foreach (['framework/backend', 'demo/example/backend', 'framework/tests', 'demo/example/tests', 'scripts'] as $root) {
            $rule = new CollectionObjectListRule($root);
            foreach (['Database/Object/Collection/Sample.php', 'Nested/Database/View/Collection/Sample.php'] as $path) {
                $hits = iterator_to_array($rule->check($path, $tokens), false);
                $this->assertSame(
                    in_array($root, ['framework/backend', 'demo/example/backend'], true) ? $lines : [],
                    array_map(static fn(Violation $hit): int => $hit->line, $hits),
                    $root . '/' . $path,
                );
            }
            foreach ([
                'Database/Object/CollectionExtra/Sample.php',
                'Database/View/CollectionExtra/Sample.php',
                'OtherDatabase/Object/Collection/Sample.php',
                'Tables/Sample.php',
            ] as $path) {
                $this->assertSame([], iterator_to_array($rule->check($path, $tokens), false), $root . '/' . $path);
            }
        }
    }

    /** Direct SQL chains belong only to whole Object collection path segments in production roots. */
    public function testCollectionSqlReadsAreJudgedOnlyInProductionObjectCollections(): void
    {
        $tokens = token_get_all((string)file_get_contents(
            $this->fixtureRoot() . '/Bad/Database/Object/Collection/CollectionSqlReadSamples.php',
        ));
        foreach ([
            'framework/backend', 'demo/example/backend', 'framework/tests', 'demo/example/tests', 'scripts',
            'framework/backendExtra', 'demo/example/backendExtra', 'demo/nested/example/backend',
        ] as $root) {
            $rule = new CollectionSqlReadRule($root);
            $this->assertSame('COLLECTION-SQL-READ', $rule->id());
            $this->assertSame('docs/agents/orm/object.md', $rule->doc());
            foreach (['Database/Object/Collection/Sample.php', 'Nested/Database/Object/Collection/Sample.php'] as $path) {
                $hits = iterator_to_array($rule->check($path, $tokens), false);
                $this->assertSame(
                    in_array($root, ['framework/backend', 'demo/example/backend'], true)
                        ? [11, 12, 13, 14, 15, 16, 17, 21, 21, 23, 24, 26, 27, 29, 30, 31, 32, 33, 36, 37] : [],
                    array_map(static fn(Violation $hit): int => $hit->line, $hits),
                    $root . '/' . $path,
                );
            }
            foreach ([
                'Database/Object/CollectionExtra/Sample.php',
                'OtherDatabase/Object/Collection/Sample.php',
                'Database/View/Collection/Sample.php',
                'Database/Entity/Collection/Sample.php',
                'Database/Actions/Collection/Sample.php',
                'Database/Object/Objects.php',
                'Database/Object/Item/Sample.php',
                'Tables/Sample.php',
            ] as $path) {
                $this->assertSame([], iterator_to_array($rule->check($path, $tokens), false), $root . '/' . $path);
            }
        }
    }

    /** The detector's real report drives the existing freeze, growth and shrink contract. */
    public function testCollectionSqlReadBaselineRequiresItsActualFrozenCount(): void
    {
        $path = 'Database/Object/Collection/Sample.php';
        $source = "<?php\nstatic::entityClass()::get();\nself::entityClass()::count();\n";
        $key = 'COLLECTION-SQL-READ framework/backend/' . $path;
        $baseline = Baseline::fromText($key . ' 2 # HIL-1533');
        $reported = $this->collectionSqlReadReport($source, $path);
        $this->assertCount(2, $reported[$key]);
        $this->assertSame([], $baseline->reconcile($reported));

        foreach (['get', 'count'] as $method) {
            $grown = $this->collectionSqlReadReport($source . 'parent::entityClass()::' . $method . '();', $path);
            $this->assertCount(3, $grown[$key]);
            $this->assertSame(
                ['COLLECTION-SQL-READ framework/backend/Database/Object/Collection/Sample.php:4 — '
                    . 'entityClass()::' . $method . '() reads SQL directly; use Objects::loadByColumn/countByColumn '
                    . 'or another shared collection read (see docs/agents/orm/object.md)'],
                $baseline->reconcile($grown),
            );
        }
        $newPath = 'Database/Object/Collection/Another.php';
        $this->assertSame(
            ['COLLECTION-SQL-READ framework/backend/Database/Object/Collection/Another.php:2 — '
                . 'entityClass()::get() reads SQL directly; use Objects::loadByColumn/countByColumn '
                . 'or another shared collection read (see docs/agents/orm/object.md)'],
            $baseline->reconcile($reported + $this->collectionSqlReadReport("<?php\nstatic::entityClass()::get();", $newPath)),
        );
        $shrunk = $this->collectionSqlReadReport(str_replace('static::entityClass()::get();', '', $source), $path);
        $this->assertCount(1, $shrunk[$key]);
        $this->assertSame(
            ['baseline record "COLLECTION-SQL-READ framework/backend/Database/Object/Collection/Sample.php" '
                . 'allows 2, only 1 left — lower the count'],
            $baseline->reconcile($shrunk),
        );
        $this->assertSame([], Baseline::fromText($key . ' 1 # HIL-1533')->reconcile($shrunk));
        $paid = $this->collectionSqlReadReport('<?php $this->loadByColumn("id", 1);', $path);
        $this->assertSame(
            ['baseline record "COLLECTION-SQL-READ framework/backend/Database/Object/Collection/Sample.php" '
                . 'is paid off — delete the line'],
            $baseline->reconcile($paid),
        );
        $this->assertSame([], Baseline::fromText('')->reconcile($paid));
    }

    /**
     * The same tokens earn a verdict from their root and whole path segments alone, in each of
     * the three layers. The layer is read off the path, so each layer's own broken sample is what
     * proves the catch there, and every sample proves the silence outside the three segments.
     */
    public function testCollectionItemNarrowingIsJudgedOnlyInProductionCollectionLayers(): void
    {
        $layers = [];
        foreach ([
            'Database/View/Collection' => [24, 24, 24],
            'Database/Object/Collection' => [19, 19, 19, 35],
            'Runtime/State/Collection' => [21, 21, 31, 52, 52],
        ] as $layer => $lines) {
            $layers[$layer] = [
                token_get_all((string)file_get_contents($this->fixtureRoot() . '/Bad/' . $layer . '/CollectionItemNarrowingSamples.php')),
                $lines,
            ];
        }
        foreach (['framework/backend', 'demo/example/backend', 'framework/tests', 'demo/example/tests', 'scripts'] as $root) {
            $rule = new CollectionItemNarrowingRule($root);
            foreach ($layers as $layer => [$tokens, $lines]) {
                foreach ([$layer . '/Sample.php', 'Nested/' . $layer . '/Sample.php'] as $path) {
                    $hits = iterator_to_array($rule->check($path, $tokens), false);
                    $this->assertSame(
                        in_array($root, ['framework/backend', 'demo/example/backend'], true) ? $lines : [],
                        array_map(static fn(Violation $hit): int => $hit->line, $hits),
                        $root . '/' . $path,
                    );
                }
                foreach ([
                    'Database/View/CollectionExtra/Sample.php',
                    'Runtime/View/Collection/Sample.php',
                    'Runtime/State/CollectionExtra/Sample.php',
                    'Tables/Sample.php',
                ] as $path) {
                    $this->assertSame([], iterator_to_array($rule->check($path, $tokens), false), $root . '/' . $path);
                }
            }
        }
    }

    /** A real extra annotation must cross the frozen count through the detector and the shared baseline. */
    public function testCollectionObjectListBaselineRefusesAnAdditionalRealAnnotation(): void
    {
        $path = 'Database/View/Collection/Sample.php';
        $source = (string)file_get_contents($this->fixtureRoot() . '/Good/Tables/CollectionObjectListOutsideLayer.php');
        $reported = $this->collectionObjectListReport($source, $path);
        $key = 'COLLECTION-OBJECT-LIST framework/backend/' . $path;
        $baseline = Baseline::fromText($key . ' 2 # HIL-1531');

        $this->assertCount(2, $reported[$key]);
        $this->assertSame([], $baseline->reconcile($reported));

        $extra = str_replace(
            'public function choices(array $choices): array;',
            "public function choices(array \$choices): array;\n\n"
                . "    /** @return list<ChoiceDTO> A new raw list */\n    public function extra(): array;",
            $source,
        );
        $grown = $this->collectionObjectListReport($extra, $path);
        $this->assertCount(3, $grown[$key]);
        $this->assertSame(
            ['COLLECTION-OBJECT-LIST framework/backend/Database/View/Collection/Sample.php:17 — '
                . 'extra @return list<ChoiceDTO>: use a typed collection '
                . '(see docs/agents/code-style/internal-backend-api.md)'],
            $baseline->reconcile($grown),
        );
    }

    /**
     * @param string $source PHP source to scan without loading its declarations
     * @param string $path File path relative to the production root
     * @return array<string, list<string>> Real SQL detector output in the guard's baseline format
     */
    private function collectionSqlReadReport(string $source, string $path): array
    {
        $rule = new CollectionSqlReadRule('framework/backend');
        $reported = [];
        foreach ($rule->check($path, token_get_all($source)) as $hit) {
            $reported[$rule->id() . ' framework/backend/' . $path][] = $hit->withPathPrefix('framework/backend')->describe($rule->doc());
        }

        return $reported;
    }

    /**
     * @param string $source PHP source to scan without loading its declarations
     * @param string $path File path relative to the production root
     * @return array<string, list<string>> Real detector output in the guard's baseline format
     */
    private function collectionObjectListReport(string $source, string $path): array
    {
        $rule = new CollectionObjectListRule('framework/backend');
        $reported = [];
        foreach ($rule->check($path, token_get_all($source)) as $hit) {
            $reported[$rule->id() . ' framework/backend/' . $path][] = $hit->withPathPrefix('framework/backend')->describe($rule->doc());
        }

        return $reported;
    }

    /**
     * Scans the fixture tree with the same scanner and rules the guard test uses.
     * The whole-root mode of the empty-string rule needs a root where nothing is
     * outside a zone, so it gets a fixture root of its own — judged separately and
     * reported under its own prefix, the way the guard test names its roots.
     *
     * @return array<int, string> Reported lines, ordered by file path and then by occurrence
     */
    private function reportFixtureViolations(): array
    {
        $fixtures = $this->fixtureRoot();
        $lines = $this->reportRoot(new SourceScanner($fixtures, [self::WHOLE_ROOT_FIXTURES]), $this->rules());
        $wholeRoot = $this->reportRoot(
            new SourceScanner($fixtures . '/' . self::WHOLE_ROOT_FIXTURES),
            [EmptyStringSentinelRule::forWholeRoot()],
            self::WHOLE_ROOT_FIXTURES,
        );

        return array_merge($lines, $wholeRoot);
    }

    /**
     * @param SourceScanner $scanner Scanner over one fixture root
     * @param array<int, CodeStyleRule> $rules Rules to run over that root, in report order
     * @param string|null $prefix Path put back in front of every reported file, when the root is not the top one
     * @return array<int, string> Reported lines, ordered by file path and then by occurrence
     */
    private function reportRoot(SourceScanner $scanner, array $rules, ?string $prefix = null): array
    {
        $sources = [];
        foreach ($scanner->files() as $file) {
            $sources[$scanner->relativePath($file)] = (string)file_get_contents($file->getPathname());
        }
        ksort($sources);

        $lines = [];
        foreach ($sources as $relativePath => $contents) {
            $tokens = token_get_all($contents);
            foreach ($rules as $rule) {
                foreach ($rule->check($relativePath, $tokens) as $violation) {
                    $reported = $prefix === null ? $violation : $violation->withPathPrefix($prefix);
                    $lines[] = $reported->describe($rule->doc());
                }
            }
        }

        return $lines;
    }

    /**
     * @return string Absolute path of the fixture root, which the rules that read neighbours are handed
     */
    private function fixtureRoot(): string
    {
        return dirname(__DIR__, 2) . '/CodeStyle/Fixtures';
    }

    /**
     * @return array<int, CodeStyleRule> Rules under test, in report order
     */
    private function rules(): array
    {
        return [
            new CodeFqnRule($this->fixtureRoot()),
            new PhpDocFqnRule($this->fixtureRoot()),
            new CollectionObjectListRule('framework/backend'),
            new CollectionSqlReadRule('framework/backend'),
            new CollectionItemNarrowingRule('framework/backend'),
            new RtStateReachRule(),
            new RtStateMutationRule(),
            new ObjectStoreMutationRule(),
            new ViewWrapperBindingRule(),
            new FrameworkOrmChainRule(),
            new ErrorSuppressionRule(),
            new FsSeamRule(RootKind::Production),
            new RandomSourceRule(),
            new BlockingResolutionRule(),
            new ProcessForkRule('framework/tests/CodeStyle/Fixtures'),
            new NestableTransactionRule('framework/tests/CodeStyle/Fixtures'),
            new TestRollbackScopeRule('framework/tests/CodeStyle/Fixtures', RootKind::Suite),
            new MalformedInputMarkerRule(),
            new TruthSourceClaimRule(),
            new SecretInQueryRule(),
            new MagicRepeatRule(),
            new WiringRefusalSwallowedRule(),
            EmptyStringSentinelRule::forZone(self::ZONE_SEGMENTS),
            new PayloadSentinelRule(),
            new WireKeyCaseRule(),
            new LineLengthRule(),
            new MemberIndentRule(),
            new SpellingRule(),
        ];
    }
}
