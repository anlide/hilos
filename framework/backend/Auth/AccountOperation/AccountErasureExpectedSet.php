<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Hilos;
use Hilos\HilosException;
use JsonException;

/**
 * The expected composition of one erasure, read back from its assignments of every stage: the
 * owners expected to carry a part, and the waits no owner has lifted yet.
 *
 * A wait is lifted when an owner is expected for the same participant and account; the
 * participant is the one the payload names. Assignments under any other key — the stage duties of
 * the operation — are not part of the composition and are passed over.
 * SCAFFOLD: no live caller until the erasure coordinator opens its operation (HIL-1952).
 */
final readonly class AccountErasureExpectedSet
{
    /** @param list<AccountOperationAssignment> $assignments Assignments of one operation, every stage, in primary id order */
    public function __construct(private array $assignments)
    {
    }

    /**
     * @param int $operationId Erasure whose composition is read
     * @return self Its composition across every stage
     * @throws HilosException When the storage reader or item wrapper refuses the lookup
     */
    public static function of(int $operationId): self
    {
        return new self(array_values(iterator_to_array(Hilos::$db->accountOperationAssignments->forOperation($operationId))));
    }

    /** @return list<AccountOperationAssignment> Expected owners, `expect:` keys, in primary id order */
    public function expected(): array
    {
        return array_values(array_filter(
            $this->assignments,
            static fn (AccountOperationAssignment $assignment): bool => AccountErasureExpectation::isExpectKey($assignment->assignmentKey),
        ));
    }

    /**
     * @return list<AccountOperationAssignment> Waits, `wait:` keys, with no owner expected for their participant and account
     * @throws JsonException When a stored payload cannot be decoded
     * @throws InvalidFormatException When a stored payload names no participant
     */
    public function waiting(): array
    {
        $expected = [];
        foreach ($this->expected() as $assignment) {
            $expected[AccountErasureExpectation::participantOf($assignment->payload)][$assignment->userId] = true;
        }
        $waiting = [];
        foreach ($this->assignments as $assignment) {
            if (!AccountErasureExpectation::isWaitKey($assignment->assignmentKey)) {
                continue;
            }
            if (!isset($expected[AccountErasureExpectation::participantOf($assignment->payload)][$assignment->userId])) {
                $waiting[] = $assignment;
            }
        }

        return $waiting;
    }
}
