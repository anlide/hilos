<?php

declare(strict_types=1);

namespace Hilos\Users\Agent;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentIndexException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Item\UserActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\View\Item\User;
use Hilos\Database\View\Item\UserRename;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\DTO\UserAdminCommandDoneSignalData;
use Hilos\Users\DTO\UserAdminCommandSignalData;
use Hilos\Users\DTO\UserAdminWriteDoneSignalData;
use Hilos\Users\DTO\UserAdminWriteSignalData;
use Hilos\Users\DTO\UserBlockWriteDoneSignalData;
use Hilos\Users\DTO\UserBlockWriteSignalData;
use Hilos\Users\DTO\UserRenameDoneSignalData;
use Hilos\Users\DTO\UserRenameSignalData;
use Hilos\WiringRefusal;
use Throwable;

/**
 * Agent for one person. Its claims name the person's row and child sets; it keeps no copy of them.
 *
 * It writes the ordinary edits of the person's row - the name, the admin flag, the block (HIL-1404).
 * Each comes as a frame from the coordinator that judged it: the name from
 * {@see AbstractUsersLibraryAgent}, the two flags from {@see AbstractSessionsLibraryAgent}. The
 * agent writes and always answers - a refusal included, because the coordinator continues only on
 * the answer and something is waiting on it - and the coordinator does what follows the write.
 */
abstract class AbstractUserAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_USER;

    /**
     * The edits of one person, each addressed by the person's id; the answers go back to the
     * coordinator under the name each frame carries.
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_USER_RENAME => [
            AgentSignalConfigKey::INDEX_FIELD => UserRenameSignalData::userId,
            AgentSignalConfigKey::DTO => UserRenameSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_ADMIN_WRITE => [
            AgentSignalConfigKey::INDEX_FIELD => UserAdminWriteSignalData::userId,
            AgentSignalConfigKey::DTO => UserAdminWriteSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_ADMIN_COMMAND => [
            AgentSignalConfigKey::INDEX_FIELD => UserAdminCommandSignalData::userId,
            AgentSignalConfigKey::DTO => UserAdminCommandSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_BLOCK_WRITE => [
            AgentSignalConfigKey::INDEX_FIELD => UserBlockWriteSignalData::userId,
            AgentSignalConfigKey::DTO => UserBlockWriteSignalData::class,
        ],
    ];

    /** @var array<string, list<TruthSourceOperation>> The person's row, excluding creation and removal. */
    public const array OWNS_DB_ROWS = [
        HilosDbContext::users => [TruthSourceOperation::Update],
    ];

    /**
     * @var array<string, list<TruthSourceOperation>> The person's borrowed child sets, and the rename
     *     journal of the person, which the agent adds a row to in the transaction that writes the name.
     */
    public const array OWNS_DB_SET = [
        HilosDbContext::identities => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::passkeyCredentials => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactors => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactorBackupCodes => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactorResets => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactorSettings => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactorTrusts => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::stepUps => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::accountDeletions => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::userPhotos => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::notifications => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::notificationPreferences => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::pushSubscriptions => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::userRenames => [TruthSourceOperation::Add],
    ];

    private int $userId;

    /**
     * @param string $agentIndex Person id from the agent address
     * @throws AgentIndexRequiredException When the address omits the id
     * @throws InvalidAgentIndexException When the id is not a positive integer
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('User agent requires a person id');
        }

        $userId = ctype_digit($agentIndex)
            ? filter_var($agentIndex, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        // The master keys the agent by the address as sent. An alternate spelling of one id
        // would split that key from the worker's normalized id and duplicate its set claim.
        if ($userId === false || (string)$userId !== $agentIndex) {
            throw new InvalidAgentIndexException('User agent requires a positive integer person id');
        }

        $this->userId = $userId;
        $this->agentIndex = $agentIndex;
    }

    /**
     * @param string $collection Collection whose row claim is being resolved
     * @return list<string> The person's row key
     */
    public function ownedDbRowKeys(string $collection): array
    {
        return [(string)$this->userId];
    }

    /**
     * @param string $collection Collection whose set claim is being resolved
     * @return string The person's root set key
     */
    public function ownedDbSetKey(string $collection): string
    {
        return (string)$this->userId;
    }

    /**
     * Writes one edit of the person and answers the coordinator that asked for it.
     *
     * A frame naming another person never reached the right agent, and is refused as such rather
     * than written: the claims of this agent cover its own person alone.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one of this agent's frames
     * @throws InvalidAgentSignalPayloadException When the payload is not the one its name promises
     * @throws AgentException When the frame names another person
     * @throws InvalidArgumentException When the answer frame cannot be named or queued
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_USER_RENAME:
                $rename = $data->data;
                if (!$rename instanceof UserRenameSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserRenameSignalData::class, $rename);
                }

                $this->refuseAnotherPerson($rename->userId, $name);
                $this->handleRename($rename);

                return;

            case HilosSignalConstants::HILOS_USER_ADMIN_WRITE:
                $adminWrite = $data->data;
                if (!$adminWrite instanceof UserAdminWriteSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserAdminWriteSignalData::class, $adminWrite);
                }

                $this->refuseAnotherPerson($adminWrite->userId, $name);
                $this->sendToAgent($adminWrite->replySignal, UserAdminWriteDoneSignalData::to(
                    $adminWrite,
                    $this->flagRefusal(fn () => $this->writeAdminFlag($adminWrite->admin), 'Admin rights'),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_ADMIN_COMMAND:
                $adminCommand = $data->data;
                if (!$adminCommand instanceof UserAdminCommandSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserAdminCommandSignalData::class, $adminCommand);
                }

                $this->refuseAnotherPerson($adminCommand->userId, $name);
                $this->sendToAgent($adminCommand->replySignal, UserAdminCommandDoneSignalData::to(
                    $adminCommand,
                    $this->flagRefusal(fn () => $this->writeAdminFlag($adminCommand->admin), "Admin rights by {$adminCommand->command}"),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_BLOCK_WRITE:
                $blockWrite = $data->data;
                if (!$blockWrite instanceof UserBlockWriteSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserBlockWriteSignalData::class, $blockWrite);
                }

                $this->refuseAnotherPerson($blockWrite->userId, $name);
                $this->sendToAgent($blockWrite->replySignal, UserBlockWriteDoneSignalData::to(
                    $blockWrite,
                    $this->flagRefusal(fn () => $this->writeBlockFlag($blockWrite->block), 'Account block'),
                ));

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /** WorkerManager releases the claims after this hook returns; the agent holds no other state. */
    public function onStop(): void
    {
    }

    /**
     * Renames the person and records the rename in the framework's journal (HIL-1195).
     *
     * The name and the journal row are one transaction: a rename the journal does not know of did
     * not happen. A name that is already the person's, once trimmed, writes nothing and answers
     * null. An account folded into another one is refused before anything is read of it
     * (HIL-1292): its name is a tombstone's. What follows the commit - the notice to the person,
     * the project's hook - is the users library's, on the answer.
     *
     * Not final: the framework's test stands replace it to pin the route apart from the table.
     *
     * @param string $name Name to give; trimmed and held to the frame of {@see UserActions::rename()}
     * @param ?int $renamedByUserId Person who did the rename - the renamed person's own id when they renamed
     *     themselves - or null when the author is not a person
     * @return ?UserRename Journal row of this rename, or null when the name was already the person's
     * @throws ItemNotFoundForUpdateException When there is no such person
     * @throws ValidationException When the account was merged into another one, or the name is empty, too short or too long
     * @throws HilosException When the name, the journal row or the transaction cannot be written
     */
    protected function renamePerson(string $name, ?int $renamedByUserId): ?UserRename
    {
        $user = $this->personToWrite();
        $oldName = $user->name;
        if (trim($name) === $oldName) {
            return null;
        }

        Database::transactionStart();
        try {
            $user->actions->rename($name);
            $rename = Hilos::$db->userRenames->actions->add($this->userId, $renamedByUserId, $oldName, $user->name);
            Database::transactionCommit();
        } catch (HilosException $failure) {
            $this->rollBack();

            throw $failure;
        }

        return $rename;
    }

    /**
     * Writes the person's admin flag, and nothing else.
     *
     * Telling the person's browsers is the sessions library's, once this answers. An account
     * folded into another one is refused, both ways, before the write (HIL-1199): its rights belong
     * to the account it became. Not final, for the reason {@see self::renamePerson()} gives.
     *
     * @param bool $admin New admin flag
     * @throws ItemNotFoundForUpdateException When there is no such person
     * @throws ValidationException When the account was merged into another one
     * @throws HilosException On database failure while reading or writing the flag
     */
    protected function writeAdminFlag(bool $admin): void
    {
        $this->personToWrite()->actions->setAdmin($admin);
    }

    /**
     * Writes the person's block flag; the sessions library ends the sessions once this answers.
     *
     * The same shape as {@see self::writeAdminFlag()}: a folded account is refused, both ways,
     * before the write - the merge closed its sign-in with the flag, and the flag is not an
     * administrator's to reopen. Not final, for the reason {@see self::renamePerson()} gives.
     *
     * @param bool $block New block flag
     * @throws ItemNotFoundForUpdateException When there is no such person
     * @throws ValidationException When the account was merged into another one
     * @throws HilosException On database or truth-source failure while reading or writing the flag
     */
    protected function writeBlockFlag(bool $block): void
    {
        $this->personToWrite()->actions->setBlock($block);
    }

    /**
     * Writes a rename and answers the users library with its row or its refusal.
     *
     * The refusals keep the sentences the administrator always read: a missing person and a name
     * the row refuses are said, and a storage or wiring failure travels as the placeholder with
     * its detail beside it, written to this agent's log.
     *
     * @param UserRenameSignalData $ask Whom to rename, to what, and on whose word
     * @throws InvalidArgumentException When the answer frame cannot be named or queued
     */
    private function handleRename(UserRenameSignalData $ask): void
    {
        $rename = null;
        $refusal = null;
        try {
            $rename = $this->renamePerson($ask->name, $ask->renamedByUserId);
        } catch (ItemNotFoundForUpdateException) {
            $refusal = ActionRefusal::said("User #{$this->userId} not found");
        } catch (ValidationException $e) {
            $refusal = ActionRefusal::said('Failed to update user: ' . $e->getMessage());
        } catch (WiringRefusal $wiring) {
            // Answered like the storage failure below rather than raised (HIL-575): the library
            // continues only on the answer, and a modal or a chat window is waiting on it. Its own
            // words - the name of a collection nobody here reads - are not an answer about this
            // rename, so they ride only as the detail an admin may quote.
            $this->logAgentError("Rename refused for userId={$this->userId}: {$wiring->getMessage()}");
            $refusal = ActionRefusal::fromThrowable($wiring);
        } catch (HilosException $e) {
            $this->logAgentError("Rename failed for userId={$this->userId}: {$e->getMessage()}");
            $refusal = ActionRefusal::fromThrowable($e);
        }

        $this->sendToAgent(
            $ask->replySignal,
            UserRenameDoneSignalData::to($ask, $rename === null ? null : (int)$rename->id, $refusal),
        );
    }

    /**
     * Runs one flag write and says why it was refused, if it was.
     *
     * Every failure is an answer, the wiring refusal included: the sessions library continues only
     * on the answer, and a card or a parked command is waiting on it.
     *
     * @param callable(): void $write The flag write
     * @param string $what Which flag, for the log line
     * @return ?ActionRefusal Why the flag was not written, or null when it was
     */
    private function flagRefusal(callable $write, string $what): ?ActionRefusal
    {
        try {
            $write();
        } catch (Throwable $e) {
            $refusal = ActionRefusal::fromThrowable($e);
            if ($refusal->isInternal()) {
                $this->logAgentError("{$what} for #{$this->userId} failed: {$e->getMessage()}");
            }

            return $refusal;
        }

        return null;
    }

    /**
     * @return User The person's row, open to an edit
     * @throws ItemNotFoundForUpdateException When there is no such person
     * @throws ValidationException When the account was merged into another one
     * @throws HilosException When the person or the merges cannot be read
     */
    private function personToWrite(): User
    {
        $user = Hilos::$db->users[$this->userId] ?? null;
        if ($user === null) {
            throw new ItemNotFoundForUpdateException("No such user: {$this->userId}");
        }
        if (Hilos::$db->userMerges[$this->userId] !== null) {
            throw new ValidationException(AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE);
        }

        return $user;
    }

    /**
     * @param int $userId Person the frame names
     * @param string $name Frame name, for the refusal
     * @throws AgentException When the frame names another person than this agent's
     */
    private function refuseAnotherPerson(int $userId, string $name): void
    {
        if ($userId !== $this->userId) {
            throw new AgentException("{$name} for user #{$userId} reached the agent of user #{$this->userId}");
        }
    }

    /**
     * Rolls back a failed rename without letting the cleanup replace the failure.
     *
     * The connection under the transaction belongs to the worker and outlives the frame, so a
     * transaction left open would take in every later write that worker makes.
     */
    private function rollBack(): void
    {
        try {
            Database::transactionRollback();
        } catch (HilosException) {
            // Reporting the cleanup would replace the failure the caller is owed
        }
    }
}
