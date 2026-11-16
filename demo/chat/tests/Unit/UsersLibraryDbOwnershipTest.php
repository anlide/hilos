<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\Hilos\UsersLibraryAgent;
use Demo\Chat\Database\ChatDbContext;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use PHPUnit\Framework\TestCase;

final class UsersLibraryDbOwnershipTest extends TestCase
{
    public function testFeedAndRegistrationRowsAreCreationOnlyAfterInheritanceExpansion(): void
    {
        $claims = OwnershipDeclaration::dbCollectionsOf(UsersLibraryAgent::class);

        self::assertSame([TruthSourceOperation::Add], $claims[ChatDbContext::events]->asList());
        self::assertSame([TruthSourceOperation::Add], $claims[ChatDbContext::eventUserRegistrations]->asList());
        self::assertArrayNotHasKey(ChatDbContext::secondFactors, $claims);
        self::assertArrayNotHasKey(ChatDbContext::secondFactorBackupCodes, $claims);
        self::assertArrayNotHasKey(ChatDbContext::secondFactorResets, $claims);
        self::assertContains(ChatDbContext::secondFactors, UsersLibraryAgent::READS_DB);
        self::assertContains(ChatDbContext::secondFactorBackupCodes, UsersLibraryAgent::READS_DB);
        self::assertContains(ChatDbContext::secondFactorResets, UsersLibraryAgent::READS_DB);
        self::assertArrayNotHasKey(ChatDbContext::userRenames, $claims);
    }
}
