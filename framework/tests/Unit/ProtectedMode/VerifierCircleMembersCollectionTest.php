<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Database\Object\Collection\VerifierCircleMembers as ObjectVerifierCircleMembers;
use Hilos\Database\Object\Objects;
use PHPUnit\Framework\TestCase;

/**
 * Tests the DB-free contract of the verifier circle accessor (HIL-643).
 *
 * Half an identity pair names nobody, and the lookup says so before it queries: the pair
 * is what the circle is keyed by, so a missing half cannot match a row and asking the
 * database would only spend a round trip to be told the same. The DB-backed reads and the
 * add/remove writes are exercised at integration / e2e.
 */
final class VerifierCircleMembersCollectionTest extends TestCase
{
    public function testFindByIdentityAnswersNobodyForAnEmptyType(): void
    {
        $circle = ObjectVerifierCircleMembers::initDB(Objects::LAZY_STRATEGY_KEY);

        self::assertNull($circle->findByIdentity('', 'someone@example.com'));
    }

    public function testFindByIdentityAnswersNobodyForAnEmptyIdentifier(): void
    {
        $circle = ObjectVerifierCircleMembers::initDB(Objects::LAZY_STRATEGY_KEY);

        self::assertNull($circle->findByIdentity('password', ''));
    }
}
