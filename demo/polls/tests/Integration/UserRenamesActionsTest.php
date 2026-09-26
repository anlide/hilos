<?php

declare(strict_types=1);

namespace Demo\Polls\Tests\Integration;

use Demo\Polls\Database\Entity\Item\UserRename as EntityUserRename;
use Demo\Polls\Database\PollsDbContext;
use Demo\Polls\Hilos;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\HilosException;

/**
 * Integration tests for UserRenamesActions against the live table.
 *
 * HIL-1112: the rename audit is cut into sets by the renamed person, and the owner of one
 * person's set creates that person's rows and no one else's.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class UserRenamesActionsTest extends IntegrationTestCase
{
    /** Agent that holds the rename audit of one person. */
    private const string SET_OWNER_AGENT_ID = 'test-agent:set-owner';

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::SET_OWNER_AGENT_ID);

        parent::tearDown();
    }

    /**
     * The owner of a person's set writes that person's rename, and is refused another person's.
     *
     * @throws HilosException On database error
     */
    public function testTheSetOwnerCreatesARowOfItsSetAndNotOfAnother(): void
    {
        $own = Hilos::$db->users->actions->registerAdmin();
        $other = Hilos::$db->users->actions->registerAdmin();
        $this->assertNotNull($own->id);
        $this->assertNotNull($other->id);

        TruthSourceRegistry::register(PollsDbContext::userRenames, TruthSourceKeys::set((string)$own->id), self::SET_OWNER_AGENT_ID);
        ExecutionContext::setCurrentAgentId(self::SET_OWNER_AGENT_ID);

        $audit = Hilos::$db->userRenames->actions->add($own->id, 'Old name', 'New name');
        $this->assertSame($own->id, $audit->targetUserId);

        try {
            Hilos::$db->userRenames->actions->add($other->id, 'Old name', 'New name');
            $this->fail('Expected a rename of another person to be refused');
        } catch (CreateNotAllowedException $e) {
            $this->assertStringContainsString("it holds set '{$own->id}'", $e->getMessage());
        }

        $this->assertCount(0, EntityUserRename::get([EntityUserRename::target_user_id => $other->id]));
    }
}
