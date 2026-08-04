<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Database\Actions\Collection\EventMessagesActions;
use Demo\Chat\Database\Actions\Item\UserActions;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Entity\Item\EventMessage as EntityEventMessage;
use Demo\Chat\Database\Entity\Item\User as EntityUser;
use Demo\Chat\Hilos;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\Identities;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for admin/CLI account merge (HIL-378).
 *
 * {@see ChatAgent::handleAccountMerge()} absorbs a loser account into a survivor
 * in ONE database transaction: the framework identity re-point
 * ({@see Identities::rePointToUser()}), the demo
 * message re-point ({@see EventMessagesActions::rePointAuthor()}),
 * and the loser tombstone ({@see UserActions::tombstone()}).
 * Coverage: the happy path moves both content kinds and tombstones the loser; the
 * validation guards reject a self/unknown/already-merged merge before any write;
 * and a failure of a later transaction step rolls the whole merge back, so the
 * earlier identity re-point leaves no partial write.
 *
 * The rePointToUser "skip a duplicate rather than move it" branch is a defensive
 * guard for a state the schema forbids: (type, identifier) is globally UNIQUE, so
 * a survivor and a loser can never own the same identity pair for the branch to
 * fire, and the constraint refuses to let a test construct one even through raw
 * SQL. It is therefore asserted by construction (unreachable), not by a test.
 *
 * Requires the test DB reset before run (composer run test:db-reset).
 */
final class AccountMergeTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    /**
     * A merge re-points the loser's identities and messages to the survivor and
     * tombstones the loser (merged_into = survivor + blocked).
     *
     * @throws HilosException When setup or the merge fails
     */
    public function testMergeMovesContentAndTombstonesLoser(): void
    {
        $survivorId = (int) Hilos::$db->users->actions->createWithName('Survivor')->id;
        $loserId = (int) Hilos::$db->users->actions->createWithName('Loser')->id;

        $emailA = 'loser-a-' . RandomHelper::hex(6) . '@example.test';
        $emailB = 'loser-b-' . RandomHelper::hex(6) . '@example.test';
        Hilos::$db->identities->createMagicLinkIdentity($loserId, $emailA);
        Hilos::$db->identities->createMagicLinkIdentity($loserId, $emailB);
        Hilos::$db->events->actions->addMessage('hello from loser', userId: $loserId);

        $summary = new ChatAgent()->handleAccountMerge($survivorId, $loserId);

        $this->assertSame(2, $summary->identitiesMoved);
        $this->assertSame(1, $summary->messagesMoved);

        $this->assertSame(
            $survivorId,
            Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, $emailA)?->userId,
        );
        $this->assertSame(
            $survivorId,
            Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, $emailB)?->userId,
        );

        $this->assertCount(0, EntityEventMessage::get([EntityEventMessage::author_user_id => $loserId]));
        $this->assertCount(1, EntityEventMessage::get([EntityEventMessage::author_user_id => $survivorId]));

        $loser = Hilos::$db->users[$loserId];
        $this->assertSame($survivorId, $loser?->mergedInto);
        $this->assertTrue($loser?->block);
    }

    /**
     * Merging a user into itself is rejected before any write.
     *
     * @throws HilosException When setup fails
     */
    public function testMergeRejectsSelfMerge(): void
    {
        $userId = (int) Hilos::$db->users->actions->createWithName('Self')->id;

        $this->expectException(ValidationException::class);
        new ChatAgent()->handleAccountMerge($userId, $userId);
    }

    /**
     * Merging into a non-existent survivor is rejected before any write.
     *
     * @throws HilosException When setup fails
     */
    public function testMergeRejectsUnknownSurvivor(): void
    {
        $loserId = (int) Hilos::$db->users->actions->createWithName('Orphan Loser')->id;

        $this->expectException(ValidationException::class);
        new ChatAgent()->handleAccountMerge($loserId + 1_000_000, $loserId);
    }

    /**
     * Merging an already-tombstoned loser is rejected before any write.
     *
     * @throws HilosException When setup or the first merge fails
     */
    public function testMergeRejectsAlreadyMergedLoser(): void
    {
        $survivorId = (int) Hilos::$db->users->actions->createWithName('Survivor')->id;
        $loserId = (int) Hilos::$db->users->actions->createWithName('Loser')->id;
        $secondSurvivorId = (int) Hilos::$db->users->actions->createWithName('Second Survivor')->id;

        new ChatAgent()->handleAccountMerge($survivorId, $loserId);

        $this->expectException(ValidationException::class);
        new ChatAgent()->handleAccountMerge($secondSurvivorId, $loserId);
    }

    /**
     * A failure of a later transaction step rolls the whole merge back: the
     * earlier identity re-point is undone and the loser stays standalone.
     *
     * The message re-point is forced to fail by revoking the eventMessages truth
     * source, so its truth-source guard throws mid-transaction — after the
     * identity re-point has already written. The rollback must leave the identity
     * on the loser and the loser un-tombstoned. DB truth is read through the Entity
     * layer (a fresh SELECT) rather than the collections, whose object cache still
     * holds the mutated-then-rolled-back identity.
     *
     * @throws HilosException When setup fails
     */
    public function testMergeRollsBackWhenLaterStepFails(): void
    {
        $survivorId = (int) Hilos::$db->users->actions->createWithName('Survivor')->id;
        $loserId = (int) Hilos::$db->users->actions->createWithName('Loser')->id;

        $email = 'rollback-' . RandomHelper::hex(6) . '@example.test';
        $identityId = (int) Hilos::$db->identities->createMagicLinkIdentity($loserId, $email)->id;

        TruthSourceRegistry::unregister(ChatDbContext::eventMessages, self::TEST_AGENT_ID);

        try {
            new ChatAgent()->handleAccountMerge($survivorId, $loserId);
            $this->fail('Expected the revoked message re-point to abort the merge');
        } catch (WriteNotAllowedException) {
            // Expected: the mid-transaction truth-source failure aborts the merge.
        }

        $identity = EntityIdentity::get([EntityIdentity::id => $identityId])->first();
        $this->assertSame($loserId, $identity?->user_id);

        $loser = EntityUser::get([EntityUser::id => $loserId])->first();
        $this->assertNull($loser?->merged_into);
    }
}
