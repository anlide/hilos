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
use Hilos\Database\Identity\PasswordFate;
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
 * The password half is HIL-692, and the case worth the setup cost is the last one: two
 * accounts that each have a password, merged, and then reached through BOTH of their
 * addresses. That is the sentence the leaf exists for, and until the merge could be told
 * whose password to keep there was no way to write it down.
 *
 * Requires the test DB reset before run (composer run test:db-reset).
 */
final class AccountMergeTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    private const string SURVIVOR_PASSWORD = 'the passphrase that stays';

    private const string LOSER_PASSWORD = 'the passphrase that gives way';

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

        $summary = new ChatAgent()->handleAccountMerge($survivorId, $loserId, null);

        $this->assertSame(2, $summary->identitiesMoved);
        $this->assertSame(1, $summary->messagesMoved);
        $this->assertSame(PasswordFate::NONE, $summary->passwordKept);

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
        new ChatAgent()->handleAccountMerge($userId, $userId, null);
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
        new ChatAgent()->handleAccountMerge($loserId + 1_000_000, $loserId, null);
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

        new ChatAgent()->handleAccountMerge($survivorId, $loserId, null);

        $this->expectException(ValidationException::class);
        new ChatAgent()->handleAccountMerge($secondSurvivorId, $loserId, null);
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
            new ChatAgent()->handleAccountMerge($survivorId, $loserId, null);
            $this->fail('Expected the revoked message re-point to abort the merge');
        } catch (WriteNotAllowedException) {
            // Expected: the mid-transaction truth-source failure aborts the merge.
        }

        $identity = EntityIdentity::get([EntityIdentity::id => $identityId])->first();
        $this->assertSame($loserId, $identity?->user_id);

        $loser = EntityUser::get([EntityUser::id => $loserId])->first();
        $this->assertNull($loser?->merged_into);
    }

    /**
     * Two passwords with nothing said is refused before a transaction opens.
     *
     * @throws HilosException When setup fails
     */
    public function testMergeRefusesTwoPasswordsUntilTheirFateIsNamed(): void
    {
        [$survivorId, $loserId, $survivorEmail, $loserEmail] = $this->seedTwoPasswordedAccounts();

        try {
            new ChatAgent()->handleAccountMerge($survivorId, $loserId, null);
            $this->fail('Expected a merge of two passworded accounts to refuse');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('--password', $e->getMessage());
        }

        $this->assertSame(
            $loserId,
            Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $loserEmail)?->userId,
            'A refused merge writes nothing at all',
        );
        $this->assertNotNull(Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $survivorEmail));
    }

    /**
     * Keeping the survivor's password leaves the loser's address as a link address.
     *
     * @throws HilosException When setup or the merge fails
     */
    public function testMergeKeepingTheSurvivorsPassword(): void
    {
        [$survivorId, $loserId, $survivorEmail, $loserEmail] = $this->seedTwoPasswordedAccounts();

        $summary = new ChatAgent()->handleAccountMerge($survivorId, $loserId, PasswordFate::SURVIVOR);

        $this->assertSame(PasswordFate::SURVIVOR, $summary->passwordKept);
        $this->assertSame($survivorEmail, Hilos::$db->identities->findPasswordByUser($survivorId)?->identifier);
        $this->assertSame(
            $survivorId,
            Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, $loserEmail)?->userId,
        );
    }

    /**
     * Keeping the loser's password moves it across and demotes the survivor's own.
     *
     * @throws HilosException When setup or the merge fails
     */
    public function testMergeKeepingTheLosersPassword(): void
    {
        [$survivorId, $loserId, $survivorEmail, $loserEmail] = $this->seedTwoPasswordedAccounts();

        $summary = new ChatAgent()->handleAccountMerge($survivorId, $loserId, PasswordFate::LOSER);

        $this->assertSame(PasswordFate::LOSER, $summary->passwordKept);
        $this->assertSame($loserEmail, Hilos::$db->identities->findPasswordByUser($survivorId)?->identifier);
        $this->assertNotNull(Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, $survivorEmail));
    }

    /**
     * Keeping neither leaves the person both addresses and no password at all.
     *
     * @throws HilosException When setup or the merge fails
     */
    public function testMergeKeepingNeitherPassword(): void
    {
        [$survivorId, $loserId, $survivorEmail, $loserEmail] = $this->seedTwoPasswordedAccounts();

        $summary = new ChatAgent()->handleAccountMerge($survivorId, $loserId, PasswordFate::NONE);

        $this->assertSame(PasswordFate::NONE, $summary->passwordKept);
        $this->assertNull(Hilos::$db->identities->findPasswordByUser($survivorId));
        foreach ([$survivorEmail, $loserEmail] as $email) {
            $this->assertSame(
                $survivorId,
                Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, $email)?->userId,
            );
        }
    }

    /**
     * Naming a password the account has not got reports what really happened.
     *
     * @throws HilosException When setup or the merge fails
     */
    public function testMergeReportsTheOutcomeAndNotTheWordThatWasTyped(): void
    {
        $survivorId = (int) Hilos::$db->users->actions->createWithName('Survivor')->id;
        $loserId = (int) Hilos::$db->users->actions->createWithName('Loser')->id;
        $this->seedPassword($loserId, $this->uniqueMergeEmail());

        $summary = new ChatAgent()->handleAccountMerge($survivorId, $loserId, PasswordFate::SURVIVOR);

        $this->assertSame(PasswordFate::NONE, $summary->passwordKept);
        $this->assertNull(Hilos::$db->identities->findPasswordByUser($survivorId));
    }

    /**
     * The one password of two accounts survives a merge nobody had to decide.
     *
     * @throws HilosException When setup or the merge fails
     */
    public function testMergeWithOnePasswordNeedsNoDecision(): void
    {
        $survivorId = (int) Hilos::$db->users->actions->createWithName('Survivor')->id;
        $loserId = (int) Hilos::$db->users->actions->createWithName('Loser')->id;
        $loserEmail = $this->uniqueMergeEmail();
        $this->seedPassword($loserId, $loserEmail);

        $summary = new ChatAgent()->handleAccountMerge($survivorId, $loserId, null);

        $this->assertSame(PasswordFate::LOSER, $summary->passwordKept);
        $this->assertSame($loserEmail, Hilos::$db->identities->findPasswordByUser($survivorId)?->identifier);
    }

    /**
     * The sentence the leaf exists for: merge two passworded accounts, then reach the
     * account and its one secret through either address.
     *
     * The lookup is asked of the identity layer rather than driven through the sign-in
     * page, because what is under test is which secret an address leads to - the session
     * plumbing around it has its own cases in {@see MainPageLoginTest}, including a
     * sign-in through an address that arrived by a merge.
     *
     * @throws HilosException When setup, the merge, or a lookup fails
     */
    public function testEitherAddressReachesTheSurvivingPasswordAfterAMerge(): void
    {
        [$survivorId, $loserId, $survivorEmail, $loserEmail] = $this->seedTwoPasswordedAccounts();

        new ChatAgent()->handleAccountMerge($survivorId, $loserId, PasswordFate::SURVIVOR);

        foreach ([$survivorEmail, $loserEmail] as $email) {
            $this->assertSame(
                $survivorId,
                Hilos::$db->identities->findAccountIdByEmail($email),
                "{$email} must lead to the surviving account",
            );
            $this->assertTrue(
                Hilos::$db->identities->findPasswordByUser($survivorId)?->verifyPassword(self::SURVIVOR_PASSWORD),
                "the password behind {$email} must be the one that stayed",
            );
        }
    }

    /**
     * Creates two accounts that each hold a password, which is what forces a decision.
     *
     * @return array{int, int, string, string} Survivor id, loser id, survivor email, loser email
     * @throws HilosException When user creation or an identity write fails
     */
    private function seedTwoPasswordedAccounts(): array
    {
        $survivorId = (int) Hilos::$db->users->actions->createWithName('Survivor')->id;
        $loserId = (int) Hilos::$db->users->actions->createWithName('Loser')->id;
        $survivorEmail = $this->uniqueMergeEmail();
        $loserEmail = $this->uniqueMergeEmail();
        $this->seedPassword($survivorId, $survivorEmail, self::SURVIVOR_PASSWORD);
        $this->seedPassword($loserId, $loserEmail, self::LOSER_PASSWORD);

        return [$survivorId, $loserId, $survivorEmail, $loserEmail];
    }

    /**
     * Attaches a verified password identity to an account.
     *
     * @param int $userId Owning user id
     * @param string $email Address the password is written on
     * @param string $password Plaintext to store hashed
     * @throws HilosException When the identity write fails
     */
    private function seedPassword(int $userId, string $email, string $password = self::LOSER_PASSWORD): void
    {
        Hilos::$db->identities->createPasswordIdentity($userId, $email, $password)->markVerified();
    }

    /**
     * @return string Unique lowercase address for one account
     */
    private function uniqueMergeEmail(): string
    {
        return 'merge-' . RandomHelper::hex(6) . '@example.test';
    }
}
