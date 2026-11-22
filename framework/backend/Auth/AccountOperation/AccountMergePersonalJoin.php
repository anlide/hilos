<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\HilosException;
use Hilos\Users\Agent\AbstractUserAgent;

/**
 * The project's subclass of {@see AbstractUserAgent} that joins the rows of its personal sets
 * ({@see AccountDataOwnerDirectory::personal()}) of a merge loser into the survivor's.
 *
 * A row of a personal set belongs to one person's agent, and no agent writes into another person's
 * set: the loser's agent gives its rows away, the survivor's agent writes them into its own sets by
 * the project's rule, and the loser's agent then deletes its own. The rows travel between the two
 * in one frame ({@see HilosSignalConstants::HILOS_USER_ACCOUNT_MERGE_PERSONAL_HANDOVER}), as the
 * project's JSON object; the framework checks only that it is an object whose keys are named.
 *
 * The part runs only when the class registered under {@see HilosAgentType::HILOS_USER} implements
 * this interface and the project declares a personal set this installation carries
 * ({@see AccountMergePersonalStep::isActive()}). Without it the rows of those sets stay with the
 * loser.
 *
 * The agent records both results through the generic participant step, under the participant
 * result claim it already holds for its other merge duties. No method opens a transaction of its
 * own.
 */
interface AccountMergePersonalJoin
{
    /**
     * The loser's agent reads, and only reads, its rows of the personal sets.
     *
     * The framework puts the answer into the frame to the survivor as it is.
     *
     * @param AccountMergePart $part Operation, survivor and loser of the merge
     * @return array<string, mixed> The rows to give as a JSON object with named keys, empty when there is nothing to give;
     *     a key PHP reads as an integer is refused, so the top level carries names, not row ids
     * @throws HilosException When the agent cannot read its rows
     */
    public function personalRowsToGive(AccountMergePart $part): array;

    /**
     * The survivor's agent writes the given rows into its own personal sets by the project's rule,
     * inside the transaction of the participant step.
     *
     * @param AccountMergePart $part Operation, survivor and loser of the merge
     * @param array<string, mixed> $given The loser's answer of {@see personalRowsToGive()}, as the frame carried it
     * @return array<string, int> Rows joined per family the project names, empty when nothing was joined
     * @throws HilosException When the agent cannot read the given rows or write its own
     */
    public function joinPersonalRows(AccountMergePart $part, array $given): array;

    /**
     * The loser's agent deletes its rows of the personal sets once the survivor has joined them,
     * inside the transaction of the participant step. Files of the registry are not touched.
     *
     * @param AccountMergePart $part Operation, survivor and loser of the merge
     * @return array<string, int> Rows deleted per family the project names, empty when nothing was deleted
     * @throws HilosException When the agent cannot delete its rows
     */
    public function deleteGivenPersonalRows(AccountMergePart $part): array;
}
