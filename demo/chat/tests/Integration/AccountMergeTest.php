<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\SessionsLibraryAgent;
use Demo\Chat\Constants\ChatCommandConstants;
use Demo\Chat\Database\Actions\Collection\EventMessagesActions;
use Demo\Chat\Database\Actions\Item\UserActions;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Entity\Item\EventMessage as EntityEventMessage;
use Demo\Chat\Database\Entity\Item\User as EntityUser;
use Demo\Chat\Hilos;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Object\Collection\Identities;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Users\AccountMergeCommandConstants;
use Hilos\Users\AccountMergeSummary;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for admin/CLI account merge (HIL-378).
 *
 * The merge absorbs a loser account into a survivor in ONE database transaction: the
 * framework identity re-point ({@see Identities::rePointToUser()}), the demo
 * message re-point ({@see EventMessagesActions::rePointAuthor()}),
 * and the loser tombstone ({@see UserActions::tombstone()}).
 * Coverage: the happy path moves both content kinds and tombstones the loser; the
 * validation guards reject a self/unknown/already-merged merge before any write;
 * and a failure of a later transaction step rolls the whole merge back, so the
 * earlier identity re-point leaves no partial write.
 *
 * It is driven at the SESSIONS LIBRARY since HIL-729, over the operator command that is one
 * of its two ways in: the transaction and the forced sign-out are the framework's, and what
 * this demo still answers is the pair of seams on
 * {@see SessionsLibraryAgent::assertMergeable()} and
 * {@see SessionsLibraryAgent::applyAccountMerge()}. A refusal is therefore read off the
 * command reply rather than caught - the handler answers the parked operator instead of
 * letting the failure reach the worker loop - which pins the sentence the operator sees.
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
    private const string SURVIVOR_PASSWORD = 'the passphrase that stays';

    private const string LOSER_PASSWORD = 'the passphrase that gives way';

    /**
     * Gives the case a signal router, which the command reply is queued on.
     *
     * The merge answers the operator through {@see AbstractAgent::replyToCommand()} since
     * HIL-729, and that goes on the router's queue like any other signal - so a case with no
     * router would fail inside the reply rather than on what it asserts.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initSignalRouter(new ChatSignalRouter());
    }

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

        $summary = $this->mergeOk($survivorId, $loserId);

        $this->assertSame(2, $summary->identitiesMoved);
        $this->assertSame([ChatCommandConstants::ROWS_MOVED_MESSAGES => 1], $summary->rowsMoved);
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

        $this->assertSame('Cannot merge a user into itself', $this->mergeRefused($userId, $userId));
    }

    /**
     * Merging into a non-existent survivor is rejected before any write.
     *
     * @throws HilosException When setup fails
     */
    public function testMergeRejectsUnknownSurvivor(): void
    {
        $loserId = (int) Hilos::$db->users->actions->createWithName('Orphan Loser')->id;

        $unknownId = $loserId + 1_000_000;

        $this->assertSame("No such user: {$unknownId}", $this->mergeRefused($unknownId, $loserId));
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

        $this->mergeOk($survivorId, $loserId);

        $this->assertSame(
            "Loser {$loserId} is already merged",
            $this->mergeRefused($secondSurvivorId, $loserId),
        );
    }

    /**
     * A failure of a later transaction step rolls the whole merge back: the
     * earlier identity re-point is undone and the loser stays standalone.
     *
     * The message re-point is forced to fail by taking the survivor's user row out of the
     * database while the collections still hold it, so the guards vouch for an account the
     * moved message can no longer name and `fk_event_message_user` refuses the write
     * mid-transaction — after the identity re-point has already written. A real database
     * refusal rather than a revoked truth-source claim, because the merge asserts none to
     * revoke: since HIL-729 it runs in the sessions library's process, and a collection-wide
     * claim on chat's rows is the chat agent's ({@see EventMessagesActions::rePointAuthor()}).
     * The rollback must leave the identity on the loser, the message on the loser, and the
     * loser un-tombstoned. DB truth is read through the Entity layer (a fresh SELECT) rather
     * than the collections, whose object cache still holds the mutated-then-rolled-back rows.
     *
     * @throws HilosException When setup fails
     */
    public function testMergeRollsBackWhenLaterStepFails(): void
    {
        $survivorId = (int) Hilos::$db->users->actions->createWithName('Survivor')->id;
        $loserId = (int) Hilos::$db->users->actions->createWithName('Loser')->id;

        $email = 'rollback-' . RandomHelper::hex(6) . '@example.test';
        $identityId = (int) Hilos::$db->identities->createMagicLinkIdentity($loserId, $email)->id;
        Hilos::$db->events->actions->addMessage('hello from loser', userId: $loserId);

        EntityUser::get([EntityUser::id => $survivorId])->first()?->delete();

        $this->assertNotSame('', $this->mergeRefused($survivorId, $loserId));

        $identity = EntityIdentity::get([EntityIdentity::id => $identityId])->first();
        $this->assertSame($loserId, $identity?->user_id);

        $this->assertCount(1, EntityEventMessage::get([EntityEventMessage::author_user_id => $loserId]));

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

        $this->assertStringContainsString('--password', $this->mergeRefused($survivorId, $loserId));

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

        $summary = $this->mergeOk($survivorId, $loserId, PasswordFate::SURVIVOR);

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

        $summary = $this->mergeOk($survivorId, $loserId, PasswordFate::LOSER);

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

        $summary = $this->mergeOk($survivorId, $loserId, PasswordFate::NONE);

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

        $summary = $this->mergeOk($survivorId, $loserId, PasswordFate::SURVIVOR);

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

        $summary = $this->mergeOk($survivorId, $loserId);

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

        $this->mergeOk($survivorId, $loserId, PasswordFate::SURVIVOR);

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
     * Runs one merge the way an operator does, and hands back what the socket was told.
     *
     * The command is the sessions library's since HIL-729 - guards, transaction, forced
     * sign-out and reply all - so this is the whole of the path a case can drive. An omitted
     * fate is left out of the payload rather than sent as an empty value, exactly as the
     * `account:merge` command builds it: not naming one is a request, not a blank field.
     *
     * Dispatched INSIDE the library's own execution frame, the way a worker dispatches it,
     * and that is load-bearing rather than tidy: every write below runs as the agent that
     * owns the sessions and not as the one that owns chat's rows, so a truth-source claim
     * asserted somewhere in the merge path fails here exactly as it fails on a live node. A
     * case dispatching with no frame at all asks the registry a different question - "is
     * there ANY collection-wide source" - which this harness answers for every chat
     * collection in setUp, and a guard refusing on every real merge stays green.
     *
     * @param int $survivorId Survivor user id that absorbs the loser
     * @param int $loserId Loser user id folded into the survivor
     * @param ?PasswordFate $passwordFate Whose password to keep, or null when nobody names one
     * @return CommandReplyDTO What the library answered the parked operator with
     * @throws HilosException When the command handler itself fails
     */
    private function runMerge(int $survivorId, int $loserId, ?PasswordFate $passwordFate): CommandReplyDTO
    {
        $payload = [
            AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => $survivorId,
            AccountMergeCommandConstants::FIELD_LOSER_USER_ID => $loserId,
        ];
        if ($passwordFate !== null) {
            $payload[AccountMergeCommandConstants::FIELD_PASSWORD_FATE] = $passwordFate->value;
        }

        $library = $this->sessionsLibrary();
        ExecutionContext::run(
            new ExecutionFrame(agentId: $library->getId()),
            static function () use ($library, $payload): void {
                $library->onSignalCommand(new CommandRequestDTO(
                    correlationId: RandomHelper::hex(8),
                    command: CliCommands::ACCOUNT_MERGE,
                    payload: $payload,
                ), '', '');
            },
        );

        return $this->consumeMergeReply();
    }

    /**
     * Runs a merge that must go through and reads the summary back off the reply.
     *
     * @param int $survivorId Survivor user id that absorbs the loser
     * @param int $loserId Loser user id folded into the survivor
     * @param ?PasswordFate $passwordFate Whose password to keep, or null when nobody names one
     * @return AccountMergeSummary What the merge reported moving
     * @throws HilosException When the command handler fails or the reply is unreadable
     */
    private function mergeOk(int $survivorId, int $loserId, ?PasswordFate $passwordFate = null): AccountMergeSummary
    {
        $reply = $this->runMerge($survivorId, $loserId, $passwordFate);
        $this->assertTrue($reply->isOk(), 'The merge was refused: ' . json_encode($reply->payload));

        return AccountMergeSummary::fromArray($reply->payload);
    }

    /**
     * Runs a merge that must be refused and hands back the sentence the operator sees.
     *
     * @param int $survivorId Survivor user id that would absorb the loser
     * @param int $loserId Loser user id that would be folded in
     * @param ?PasswordFate $passwordFate Whose password to keep, or null when nobody names one
     * @return string The refusal, as it reaches the command line
     * @throws HilosException When the command handler itself fails
     */
    private function mergeRefused(int $survivorId, int $loserId, ?PasswordFate $passwordFate = null): string
    {
        $reply = $this->runMerge($survivorId, $loserId, $passwordFate);
        $this->assertFalse($reply->isOk(), 'The merge went through when it should not have');

        $message = $reply->payload[CommandConstants::FIELD_MESSAGE] ?? null;
        $this->assertIsString($message);

        return $message;
    }

    /**
     * Empties the signal queue and returns the one command reply it held.
     *
     * The whole queue is drained rather than read once: a merge writes rows, and a row this
     * worker owns is announced to the others as a DB-sync signal, so the reply is not alone
     * in there on the paths that succeed.
     *
     * @return CommandReplyDTO The reply the merge queued
     */
    private function consumeMergeReply(): CommandReplyDTO
    {
        $replies = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            }
        }

        $this->assertCount(1, $replies, 'Every merge answers the operator exactly once');

        return $replies[0];
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
