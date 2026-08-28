<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Agents\Hilos;

use Demo\SimplePoll\Auth\PollAuthMethods;
use Demo\SimplePoll\Auth\PollOAuthConfig;
use Demo\SimplePoll\Constants\PollNotificationType;
use Demo\SimplePoll\Database\PollDbContext;
use Demo\SimplePoll\Hilos;
use Demo\SimplePoll\Pages\Hilos\Users\UserPage;
use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Database\DatabaseException;
use Hilos\HilosException;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Users\DTO\AdminRenameDoneSignalData;
use Hilos\Users\DTO\AdminRenameSignalData;

/**
 * The simple-poll demo's users library - the project half of the framework sign-in feature (HIL-634).
 *
 * Every sign-in command lives in {@see AbstractUsersLibraryAgent}; what is here is the
 * handful of answers only this project can give: which collection its accounts live in,
 * how one is created and named, which methods an identifier may be offered, and the
 * provider wiring a social login runs on. Everything the surface actually does with those
 * answers is the framework's.
 *
 * Registered under {@see HilosAgentType::HILOS_USERS_LIBRARY} by this demo's own topology,
 * and reached because the demo declares {@see HilosFeature::AUTH}: the feature is what
 * turns the library's command names into this project's door.
 *
 * {@see afterUserCreated()} is deliberately not overridden: this demo keeps no event log, so
 * a new account is news to nobody here and the empty framework default is the whole truth.
 */
final class UsersLibraryAgent extends AbstractUsersLibraryAgent
{
    /**
     * @var list<string> The people it answers about, owned by the poll agent that writes them,
     *     on top of everything the framework library reads.
     */
    public const array READS_DB = [...parent::READS_DB, PollDbContext::users, PollDbContext::userRenames];

    /**
     * The write half of the admin rename, addressed here because the account row is this
     * library's (HIL-771).
     *
     * The submit itself stayed on {@see UserPage}: what closes it is that page's admin guard,
     * and an agent action carries no level to inherit. So the page checks who is asking and
     * this frame carries the work.
     */
    public const array AGENT_SIGNALS = [
        ...parent::AGENT_SIGNALS,
        HilosSignalConstants::HILOS_USER_ADMIN_RENAME => AdminRenameSignalData::class,
    ];

    /**
     * Claims the rename journal this library writes from its OWN process.
     *
     * The registry is per process, and the audit row below is written HERE rather than in the
     * agent that catalogs it: a claim registered by the admin index agent covers that agent's
     * worker and nothing else, so without this the rename's log line would be refused as a write
     * with no truth source behind it. The same second claim, for the same reason, that the
     * account set itself carries.
     *
     * @throws HilosException On database or runtime startup failure
     */
    public function onStart(): void
    {
        parent::onStart();

        $this->registerDbTruthSource(PollDbContext::userRenames);
    }

    /**
     * Renames one account for an administrator, logs it, and tells the person it happened.
     *
     * The body of {@see UserPage}'s update handler, whole and in the same order: the account
     * row, the standalone audit row, then the best-effort notice. What changed is only WHERE it
     * runs - here, where the account set is owned, instead of in whichever worker served the
     * admin's socket.
     *
     * Both refusals keep the sentences the page sent, because they are what an admin reads. A
     * missing person and a name the row refuses are answers, not exceptions: the ask arrived as
     * a frame, and a throw here would leave the modal waiting forever.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one this library declares
     * @throws LogicException When the payload is not the one its name promises
     * @throws HilosException Whatever the framework library's own handler raises
     * @throws InvalidArgumentException When the answer cannot be named or queued
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        if ($name !== HilosSignalConstants::HILOS_USER_ADMIN_RENAME) {
            parent::onSignalAgent($data, $source, $name);

            return;
        }

        if (!$data->data instanceof AdminRenameSignalData) {
            throw new LogicException($name . ' payload must be ' . AdminRenameSignalData::class);
        }

        $this->sendToAgent(
            HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE,
            new AdminRenameDoneSignalData(
                $data->data->acceptKey,
                $data->data->requestId,
                $this->renameForAdmin($data->data),
            ),
        );
    }

    /**
     * Writes the rename, its audit row and its notice, or says why none of them happened.
     *
     * @param AdminRenameSignalData $rename Whom to rename, to what, and on whose word
     * @return ?string Why the account was not renamed, or null when it was
     */
    private function renameForAdmin(AdminRenameSignalData $rename): ?string
    {
        try {
            $user = Hilos::$db->users[$rename->userId];
            if ($user === null) {
                return "User #{$rename->userId} not found";
            }

            $oldName = $user->name;
            $user->actions->rename($rename->name);
            Hilos::$db->userRenames->actions->add($rename->userId, $oldName, $user->name);
            $this->notifyRenamedUser($rename->userId, $oldName, $user->name, $rename->adminUserId);
        } catch (ValidationException $e) {
            return 'Failed to update user: ' . $e->getMessage();
        } catch (DatabaseException $e) {
            // The same sentence the dispatcher would have put on the wire had this been thrown
            // on the page: a storage failure is told to nobody but the log.
            $this->logAgentError("Admin rename failed for userId={$rename->userId}: {$e->getMessage()}");

            return SignalConstants::ACTION_FAILED_REASON;
        } catch (HilosException $e) {
            $this->logAgentError("Admin rename failed for userId={$rename->userId}: {$e->getMessage()}");

            return 'Failed to update user: ' . $e->getMessage();
        }

        return null;
    }

    /**
     * Tells the renamed user that an administrator changed their account name.
     *
     * The administrator sees the result in the table, so only the person whose account was
     * touched is notified - and only when the name really changed, or when an administrator
     * renamed somebody other than themselves. The emit is best-effort: the rename and its audit
     * row stand whatever happens to it.
     *
     * @param int $userId Renamed user id
     * @param string $oldName Name the account carried before
     * @param string $newName Name the account carries now
     * @param ?int $actorUserId The administrator behind the rename, as their own worker read them
     */
    private function notifyRenamedUser(int $userId, string $oldName, string $newName, ?int $actorUserId): void
    {
        if ($oldName === $newName || $actorUserId === $userId) {
            return;
        }

        try {
            Hilos::$notify?->emit(new NotificationDraft(
                userId: $userId,
                type: PollNotificationType::USER_RENAMED,
                title: 'An administrator renamed your account',
                severity: NotificationSeverity::INFO,
                body: 'Your name is now ' . $newName,
                data: [
                    'oldName' => $oldName,
                    'newName' => $newName,
                    'actorUserId' => $actorUserId,
                ],
            ));
        } catch (HilosException $e) {
            $this->logAgentError("Rename notification failed for userId={$userId}: {$e->getMessage()}");
        }
    }

    /**
     * Names this demo's own accounts collection.
     *
     * @return string Collection name of the simple-poll users
     */
    protected function usersCollection(): string
    {
        return PollDbContext::users;
    }

    /**
     * Creates one account with the display name the ceremony earned.
     *
     * @param string $displayName Name to show for the new account
     * @return int Durable id of the created user
     * @throws EmptyValueException When the display name is empty
     * @throws HilosException When the insert fails
     */
    public function createUser(string $displayName): int
    {
        return (int)Hilos::$db->users->actions->createWithName($displayName)->id;
    }

    /**
     * Names one account the way this demo shows it.
     *
     * A row that is gone, or one carrying an empty name, answers null - the caller draws
     * its own placeholder rather than offering the person a blank label to recognize
     * themselves by.
     *
     * @param int $userId Account to name
     * @return ?string Name to show, or null when there is none
     * @throws HilosException When the lookup fails
     */
    public function displayNameOf(int $userId): ?string
    {
        $name = Hilos::$db->users[$userId]?->name;

        return $name === null || $name === '' ? null : $name;
    }

    /**
     * Builds the detector over the sign-in methods this demo has actually wired.
     *
     * @return IdentifierDetector Detector answering with this demo's enabled method keys
     */
    protected function buildAuthMethods(): IdentifierDetector
    {
        return PollAuthMethods::detector();
    }

    /**
     * Builds the OAuth service this demo's providers are configured on.
     *
     * @return OAuthService Service over the demo's provider credentials
     */
    protected function buildOAuthService(): ?OAuthService
    {
        return PollOAuthConfig::buildService();
    }
}
