<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountErasureExpectation;
use Hilos\Auth\AccountOperation\AccountErasureExpectedSet;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Constants\HilosAgentType;
use Hilos\Database\Entity\Item\AccountOperation as EntityAccountOperation;
use Hilos\Database\Entity\Item\AccountOperationAssignment as EntityAccountOperationAssignment;
use Hilos\Database\Object\Item\AccountOperationAssignment as ObjectAccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationAssignment;
use PHPUnit\Framework\TestCase;

/** The expected composition of an erasure, read from a list of its stored assignments. */
final class AccountErasureExpectedSetTest extends TestCase
{
    private const int ROOT_ID = 43;
    private const int FOLDED_ID = 42;

    /** An owner expected for the same participant and account lifts the wait; the owner itself is expected. */
    public function testAnOwnerExpectedForTheSameParticipantAndAccountLiftsTheWait(): void
    {
        $wait = self::stored(1, new AccountErasureExpectation(AccountErasureParticipantCatalog::PERSONAL, self::ROOT_ID, null));
        $owner = self::stored(2, self::personalOwner(self::ROOT_ID));

        $set = new AccountErasureExpectedSet([$wait, $owner]);

        self::assertSame([], $set->waiting());
        self::assertSame([$owner], $set->expected());
    }

    /** An owner of another account or of another participant leaves the wait standing. */
    public function testAnOwnerOfAnotherAccountOrParticipantLeavesTheWait(): void
    {
        $wait = self::stored(1, new AccountErasureExpectation(AccountErasureParticipantCatalog::PERSONAL, self::ROOT_ID, null));
        $otherAccount = self::stored(2, self::personalOwner(self::FOLDED_ID));
        $otherParticipant = self::stored(3, new AccountErasureExpectation(
            AccountErasureParticipantCatalog::USERS_LIBRARY,
            self::ROOT_ID,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USERS_LIBRARY),
        ));

        $set = new AccountErasureExpectedSet([$wait, $otherAccount, $otherParticipant]);

        self::assertSame([$wait], $set->waiting());
        self::assertSame([$otherAccount, $otherParticipant], $set->expected());
    }

    /** Stage duties under keys of their own are neither expected owners nor waits, and lift no wait. */
    public function testAssignmentsUnderOtherKeysAreIgnored(): void
    {
        $owner = self::stored(1, self::personalOwner(self::FOLDED_ID));
        $duty = self::assignment(2, 'erase-person', self::ROOT_ID, '{"participant":"personal"}');
        $expectLookalike = self::assignment(3, 'expected:personal:43', self::ROOT_ID, '{"participant":"personal"}');
        $waitLookalike = self::assignment(4, 'waiting:personal:42', self::FOLDED_ID, '{}');
        $wait = self::stored(5, new AccountErasureExpectation(AccountErasureParticipantCatalog::PERSONAL, self::ROOT_ID, null));

        $set = new AccountErasureExpectedSet([$owner, $duty, $expectLookalike, $waitLookalike, $wait]);

        self::assertSame([$owner], $set->expected());
        self::assertSame([$wait], $set->waiting());
    }

    /** A delivery wait is lifted only by an owner of its own channel, read from the payload rather than the key. */
    public function testDeliveryWaitIsLiftedOnlyByAnOwnerOfItsChannel(): void
    {
        $pigeonWait = self::stored(1, new AccountErasureExpectation('delivery:census_pigeon', self::ROOT_ID, null));
        $letterOwner = self::stored(2, new AccountErasureExpectation(
            'delivery:census_letter',
            self::ROOT_ID,
            new AccountOperationOwnerAddress('census_letter_sender', '2'),
        ));

        self::assertSame([$pigeonWait], (new AccountErasureExpectedSet([$pigeonWait, $letterOwner]))->waiting());

        $pigeonOwner = self::stored(3, new AccountErasureExpectation(
            'delivery:census_pigeon',
            self::ROOT_ID,
            new AccountOperationOwnerAddress('census_pigeon_sender', '1'),
        ));
        $set = new AccountErasureExpectedSet([$pigeonWait, $letterOwner, $pigeonOwner]);

        self::assertSame([], $set->waiting());
        self::assertSame([$letterOwner, $pigeonOwner], $set->expected());
    }

    /**
     * @param int $userId Account the personal owner carries
     * @return AccountErasureExpectation The person's own agent expected for the personal participant
     */
    private static function personalOwner(int $userId): AccountErasureExpectation
    {
        return new AccountErasureExpectation(
            AccountErasureParticipantCatalog::PERSONAL,
            $userId,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId),
        );
    }

    /**
     * @param int $id Assignment id
     * @param AccountErasureExpectation $expectation Census expectation the assignment was written for
     * @return AccountOperationAssignment The assignment the census writes for it
     */
    private static function stored(int $id, AccountErasureExpectation $expectation): AccountOperationAssignment
    {
        return self::assignment($id, $expectation->assignmentKey(), $expectation->userId, $expectation->payload()->toJson());
    }

    /**
     * @param int $id Assignment id
     * @param string $assignmentKey Stored key
     * @param ?int $userId Account of the circle
     * @param string $payloadJson Stored payload
     * @return AccountOperationAssignment Assignment of one erasure at its first stage, never stored
     */
    private static function assignment(int $id, string $assignmentKey, ?int $userId, string $payloadJson): AccountOperationAssignment
    {
        $entity = EntityAccountOperationAssignment::getEmpty();
        $entity->id = $id;
        $entity->operation_id = 7;
        $entity->stage = EntityAccountOperation::INITIAL_STAGE;
        $entity->assignment_key = $assignmentKey;
        $entity->owner_type = HilosAgentType::HILOS_SESSIONS_LIBRARY;
        $entity->user_id = $userId;
        $entity->payload_json = $payloadJson;
        $entity->created_at = 1790416800000;

        return new AccountOperationAssignment(ObjectAccountOperationAssignment::fromEntity($entity));
    }
}
