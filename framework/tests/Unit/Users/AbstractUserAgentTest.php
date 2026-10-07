<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Agent\Exception\InvalidAgentIndexException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\Agent\AbstractUserAgentDaemon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The person agent claims its own row and sets without taking the libraries' creation rights. */
final class AbstractUserAgentTest extends TestCase
{
    protected function tearDown(): void
    {
        $agentId = HilosAgentType::HILOS_USER . ':42';
        TruthSourceRegistry::unregisterAgent($agentId);
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
        SourceInterestRegistry::readsWhatItMounts();
        ExecutionContext::setCurrentAgentId(null);

        parent::tearDown();
    }

    public function testPositiveIndexIsUsedForEveryClaim(): void
    {
        $agent = new TestUserAgent('42');
        $daemon = new TestUserAgentDaemon('42');

        self::assertSame(HilosAgentType::HILOS_USER, $agent->getType());
        self::assertSame('42', $agent->getIndex());
        self::assertSame($agent->getId(), $daemon->getId());
        self::assertSame(['42'], $agent->ownedDbRowKeys(HilosDbContext::users));
        foreach (array_keys(TestUserAgent::OWNS_DB_SET) as $collection) {
            self::assertSame('42', $agent->ownedDbSetKey($collection));
        }
    }

    public function testMissingIndexIsRefused(): void
    {
        $this->expectException(AgentIndexRequiredException::class);

        new TestUserAgent('');
    }

    #[DataProvider('invalidIndices')]
    public function testInvalidIndexIsRefused(string $index): void
    {
        $this->expectException(InvalidAgentIndexException::class);

        new TestUserAgent($index);
    }

    #[DataProvider('invalidIndices')]
    public function testInvalidDaemonIndexIsRefused(string $index): void
    {
        $this->expectException(InvalidAgentIndexException::class);

        new TestUserAgentDaemon($index);
    }

    /**
     * @return iterable<string, array{string}> Invalid agent indices
     */
    public static function invalidIndices(): iterable
    {
        yield 'zero' => ['0'];
        yield 'padded' => ['00042'];
        yield 'negative' => ['-1'];
        yield 'text' => ['person'];
        yield 'decimal' => ['1.5'];
    }

    public function testDeclaredClaimsHaveExactlyTheAgreedWidthsAndOperations(): void
    {
        self::assertSame([HilosDbContext::users], array_keys(TestUserAgent::OWNS_DB_ROWS));
        self::assertSame([TruthSourceOperation::Update], TestUserAgent::OWNS_DB_ROWS[HilosDbContext::users]);
        self::assertSame([
            HilosDbContext::identities,
            HilosDbContext::passkeyCredentials,
            HilosDbContext::secondFactors,
            HilosDbContext::secondFactorBackupCodes,
            HilosDbContext::secondFactorResets,
            HilosDbContext::secondFactorSettings,
            HilosDbContext::secondFactorTrusts,
            HilosDbContext::stepUps,
            HilosDbContext::accountDeletions,
            HilosDbContext::userPhotos,
            HilosDbContext::notifications,
            HilosDbContext::notificationPreferences,
            HilosDbContext::pushSubscriptions,
        ], array_keys(TestUserAgent::OWNS_DB_SET));
        foreach (TestUserAgent::OWNS_DB_SET as $operations) {
            self::assertSame([TruthSourceOperation::Update, TruthSourceOperation::Remove], $operations);
        }
        self::assertSame([], TestUserAgent::OWNS_DB);
        self::assertSame([], TestUserAgent::READS_DB);
        self::assertSame([], TestUserAgent::OWNS_RT);
        self::assertSame([], TestUserAgent::OWNS_RT_ROWS);
        self::assertSame([], TestUserAgent::OWNS_RT_SET);
    }

    public function testRegistryGrantsThePersonButRejectsAnotherSetAndCreation(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();
        $agent = new TestUserAgent('42');
        OwnershipDeclaration::claimDbRows($agent);
        OwnershipDeclaration::claimDbSet($agent);
        ExecutionContext::setCurrentAgentId($agent->getId());

        TruthSourceRegistry::checkCanWriteItem(HilosDbContext::users, '42', static fn (): array => [], TruthSourceOperation::Update);
        foreach (array_keys(TestUserAgent::OWNS_DB_SET) as $collection) {
            TruthSourceRegistry::checkCanWriteItem($collection, '1', static fn (): array => ['42'], TruthSourceOperation::Update);
            TruthSourceRegistry::checkCanWriteItem($collection, '1', static fn (): array => ['42'], TruthSourceOperation::Remove);
            self::assertFalse(TruthSourceRegistry::hasCreateSource($collection));
        }

        $this->expectException(WriteNotAllowedException::class);
        TruthSourceRegistry::checkCanWriteItem(
            HilosDbContext::notifications,
            '2',
            static fn (): array => ['43'],
            TruthSourceOperation::Update,
        );
    }
}

final class TestUserAgent extends AbstractUserAgent
{
}

final class TestUserAgentDaemon extends AbstractUserAgentDaemon
{
}
