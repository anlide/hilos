<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification;

use Hilos\Database\Context\DbContext;
use Hilos\Hilos;
use Hilos\Users\AdminAudience;
use PHPUnit\Framework\TestCase;

/**
 * Guards the admin audience seam against the LSB loss the notification registries hit (HIL-489).
 *
 * Framework call-sites ask for the audience through the bare `Hilos::adminAudienceClass()`,
 * so late static binding has to reach the project facade rather than the abstract base -
 * exactly the resolution that silently answered "no channels, no mandatory types" once
 * before. Here the same mistake would answer "this installation has no administrators",
 * which reads like a legitimate empty audience and would send nobody anything.
 */
final class AdminAudienceLsbResolutionTest extends TestCase
{
    protected function tearDown(): void
    {
        // Restore the captured facade class to the base default for later cases.
        Hilos::initBrowser();
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testBareBaseAccessorResolvesProjectAudienceAfterInit(): void
    {
        // Sanity: without a project facade captured, the base accessor sees the empty base.
        self::assertSame(AdminAudience::class, Hilos::adminAudienceClass());
        self::assertSame([], Hilos::adminAudienceClass()::all());

        AdminAudienceTestHilos::initBrowser();

        self::assertSame(AdminAudienceTestAudience::class, Hilos::adminAudienceClass());
        self::assertSame([7, 12], Hilos::adminAudienceClass()::all());
    }

    public function testAllDeduplicatesAndReindexesWhatTheProjectAnswered(): void
    {
        self::assertSame([4, 9], AdminAudienceRepeatingTestAudience::all());
    }
}

/**
 * Project facade fixture pointing the audience constant at its own subclass.
 */
final class AdminAudienceTestHilos extends Hilos
{
    protected const string ADMIN_AUDIENCE = AdminAudienceTestAudience::class;

    /**
     * Creates a no-op DB context for the abstract facade contract.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new AdminAudienceTestDbContext();
    }
}

/**
 * No-op DB context so the abstract facade fixture is instantiable.
 */
final class AdminAudienceTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for the LSB resolution fixture.
     */
    public function configure(): void
    {
    }
}

/**
 * Project audience naming two administrators.
 */
final class AdminAudienceTestAudience extends AdminAudience
{
    /**
     * @return list<int> Fixture admin user ids
     */
    protected static function userIds(): array
    {
        return [7, 12];
    }
}

/**
 * Project audience naming one administrator twice, as a walk over rows can.
 */
final class AdminAudienceRepeatingTestAudience extends AdminAudience
{
    /**
     * @return list<int> Fixture admin user ids with the first one repeated
     */
    protected static function userIds(): array
    {
        // The repeat sits before the last id on purpose: dropping it leaves a hole in the
        // keys, so a bare array_unique() would answer with something that is not a list.
        return [4, 4, 9];
    }
}
