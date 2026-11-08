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
        self::assertSame([TruthSourceOperation::Add, TruthSourceOperation::Remove], $claims[ChatDbContext::secondFactors]->asList());
        self::assertSame([TruthSourceOperation::Update], $claims[ChatDbContext::userRenames]->asList());
    }
}
