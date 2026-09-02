<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\Agents\ModeratorAgent;
use Demo\Chat\Auth\ChatAuthMethods;
use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Constants\ChatNotificationType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Core\Router\DTO\PasswordUpdatedSignalData;
use Demo\Chat\Core\Router\DTO\RenameModerationResultSignalData;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\Profile\ConfirmAddPasswordActionDTO;
use Demo\Chat\Pages\DTO\Profile\ConfirmSmsAddCodeActionDTO;
use Demo\Chat\Pages\DTO\Profile\RenameActionDTO;
use Demo\Chat\Pages\DTO\Profile\RequestAddPasswordActionDTO;
use Demo\Chat\Pages\DTO\Profile\RequestSmsAddCodeActionDTO;
use Demo\Chat\Pages\DTO\Profile\SetPasswordActionDTO;
use Demo\Chat\Pages\DTO\Profile\UnlinkIdentityActionDTO;
use Demo\Chat\Pages\Hilos\ProfilePage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Runtime\View\Item\Connection;
use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\IdentityCommands;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Auth\PasswordPolicy;
use Hilos\Auth\PhoneNumber;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\DatabaseException;
use Hilos\Database\Verification\VerificationType;
use Hilos\HilosException;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\DTO\AdminRenameDoneSignalData;
use Hilos\Users\DTO\AdminRenameSignalData;
use Random\RandomException;

/**
 * The chat demo's users library - the project half of the framework sign-in feature (HIL-622).
 *
 * Every sign-in command lives in {@see AbstractUsersLibraryAgent}; what stayed behind is the
 * handful of answers only this project can give: which collection its members live in, how one
 * is created and named, what else happens when an account is born, which methods an identifier
 * may be offered, and the provider wiring a social login runs on.
 *
 * Beside them it now holds the profile submits that WRITE a person - the rename and its whole
 * moderation round trip, the unlink, the password, and the two-step adds of a phone and of an
 * email (HIL-771). They were actions of {@see ProfilePage} until a page turned out to carry no
 * claim: a page runs in whatever worker serves the connection, and the account tables are owned
 * here. Their wire names did not change, so the frontend submits exactly what it always did and
 * the router simply hands the name to this agent instead of to that page. What is left on the
 * profile page is what does not write: reading the person, and starting an OAuth link.
 *
 * Registered under {@see HilosAgentType::HILOS_USERS_LIBRARY} by the chat's own topology, and
 * reached because the chat declares {@see HilosFeature::AUTH}: the feature is what turns the
 * library's command names into this project's door.
 */
final class UsersLibraryAgent extends AbstractUsersLibraryAgent
{
    /**
     * @var list<string> The people it answers about and writes, the identities a profile submit
     *     adds or drops, and the room's log of who was renamed, on top of everything the
     *     framework library reads.
     */
    public const array READS_DB = [
        ...parent::READS_DB,
        ChatDbContext::users,
        ChatDbContext::events,
        ChatDbContext::eventUserRenames,
    ];

    /**
     * @var list<string> The sockets it acts for: a profile submit names its person by the
     *     connection that sent it, and the rename parks its moderation phase on that row.
     */
    public const array READS_RT = [...parent::READS_RT, ChatRtContext::connections];

    /**
     * The chat's own profile submits, on top of every sign-in command the framework declares.
     *
     * All seven write a person or their identities, which is what moved them off
     * {@see ProfilePage} (HIL-771). The names are unchanged: an action's name IS its address,
     * so declaring it here is the whole of the move, and `hilos_link_oauth_start` is absent
     * because starting a provider link writes nothing and stayed on the page.
     */
    public const array AGENT_ACTIONS = [
        ...parent::AGENT_ACTIONS,
        ChatSignalConstants::RENAME => RenameActionDTO::class,
        ChatSignalConstants::UNLINK_IDENTITY => UnlinkIdentityActionDTO::class,
        ChatSignalConstants::SET_PASSWORD => SetPasswordActionDTO::class,
        ChatSignalConstants::ADD_SMS_REQUEST => RequestSmsAddCodeActionDTO::class,
        ChatSignalConstants::ADD_SMS_CONFIRM => ConfirmSmsAddCodeActionDTO::class,
        ChatSignalConstants::ADD_PASSWORD_REQUEST => RequestAddPasswordActionDTO::class,
        ChatSignalConstants::ADD_PASSWORD_CONFIRM => ConfirmAddPasswordActionDTO::class,
    ];

    /**
     * All seven, because all seven act on the submitter's own account.
     *
     * The page they came off was closed by {@see PageAccessLevel::AUTHENTICATED}, which gated
     * its actions along with the subscription. An agent action carries no page level to inherit,
     * so without this list a guest could submit them - and each of them reads its person from
     * the acting connection, which an anonymous one has none of.
     */
    public const array AUTH_ACTIONS = [
        ...parent::AUTH_ACTIONS,
        ChatSignalConstants::RENAME,
        ChatSignalConstants::UNLINK_IDENTITY,
        ChatSignalConstants::SET_PASSWORD,
        ChatSignalConstants::ADD_SMS_REQUEST,
        ChatSignalConstants::ADD_SMS_CONFIRM,
        ChatSignalConstants::ADD_PASSWORD_REQUEST,
        ChatSignalConstants::ADD_PASSWORD_CONFIRM,
    ];

    /**
     * The moderator's verdict on a requested display name, and the two admin renames.
     *
     * The verdict is the far end of a person's own rename: this library asks, the moderator
     * answers here, and this library applies the name. The round trip is one agent's business
     * end to end (HIL-771) - splitting it left the ask on an agent and the answer on a page, and
     * the page could not write the row the answer decides.
     *
     * The two renames below arrive from the opposite direction, and they are frames rather than
     * actions for the opposite reason: an administrator renaming somebody else is closed by a
     * page's ADMIN level, which an agent action has no equivalent of, so those submits STAYED on
     * their pages and only the write came here.
     */
    /**
     * Which frame answers which admin rename, by the name that asked (HIL-771).
     *
     * Two entrances, one body: the admin users table and the Hilos user-detail page both rename
     * a person, and each is served by a different agent - so each needs an answer addressed to
     * its own page, and a shared name would send both to one of them.
     */
    private const array ADMIN_RENAME_ANSWERS = [
        ChatSignalConstants::USER_ADMIN_RENAME => ChatSignalConstants::USER_ADMIN_RENAME_DONE,
        HilosSignalConstants::HILOS_USER_ADMIN_RENAME => HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE,
    ];

    public const array AGENT_SIGNALS = [
        ...parent::AGENT_SIGNALS,
        ChatSignalConstants::RENAME_MODERATION_RESULT => RenameModerationResultSignalData::class,
        ChatSignalConstants::USER_ADMIN_RENAME => AdminRenameSignalData::class,
        HilosSignalConstants::HILOS_USER_ADMIN_RENAME => AdminRenameSignalData::class,
    ];

    /**
     * Claims the chat tables and connection rows this library writes from its OWN process.
     *
     * The registry is per process, and the account event below is written HERE rather than
     * in the agent that owns the room: a claim registered by the chat agent covers the chat
     * agent's worker and nothing else, so without this the "registered in chat" line would
     * be refused as a write with no truth source behind it. The same second claim the
     * admin index agent makes on these tables, and for the same reason.
     *
     * The rename's log line lands in the same pair of tables, so `eventUserRenames` joins them
     * (HIL-771). The connection rows are claimed update-only and deliberately so: they belong to
     * the chat agent, which registers, moves and strikes them out; what this library touches on
     * one is the moderation phase of a rename it is running, three fields of a row somebody else
     * brought into being. The same shape of co-ownership the delivery journal has.
     *
     * The two claims never reach the cluster's arbiter as a clash, because both agents are placed
     * the same way - neither names a placement, so both are the leader's, and a second claim from
     * the node that already holds one is not a second node.
     *
     * @throws HilosException On database or runtime startup failure
     */
    public function onStart(): void
    {
        parent::onStart();

        $this->registerDbTruthSource(ChatDbContext::events);
        $this->registerDbTruthSource(ChatDbContext::eventUserRegistrations);
        $this->registerDbTruthSource(ChatDbContext::eventUserRenames);
        RtTruthSourceRegistry::register(
            ChatRtContext::connections,
            true,
            $this->getId(),
            [TruthSourceOperation::Update],
        );
    }

    /**
     * Runs one of the chat's own profile submits, or hands the name back to the framework.
     *
     * None of the seven answers with a reply: each writes, and the browser learns of it from the
     * projection that re-emits or from a signal fanned to the person's own sockets - exactly as
     * it did while these were page actions. The refusals are the same objects thrown in the same
     * order, so a bad payload reads the same on the client too.
     *
     * @param string $acceptKey Accept key of the connection that submitted
     * @param string $action Owned action name from {@see AGENT_ACTIONS}
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Domain reply of a framework command, or null for every chat submit
     * @throws AgentUnknownActionException When the action is not one this library owns
     * @throws InvalidActionPayloadException When the payload does not match the action name
     * @throws ItemNotFoundForUpdateException When the acting connection has no resolvable user
     * @throws ValidationException When a submit is refused
     * @throws RandomException When issuing a code cannot draw from the CSPRNG
     * @throws HilosException When a routed read or write fails
     */
    public function onAgentAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case ChatSignalConstants::RENAME:
                if (!$dto instanceof RenameActionDTO) {
                    throw new InvalidActionPayloadException($action, RenameActionDTO::class, $dto);
                }
                $this->startRename($acceptKey, $dto);

                return null;

            case ChatSignalConstants::UNLINK_IDENTITY:
                if (!$dto instanceof UnlinkIdentityActionDTO) {
                    throw new InvalidActionPayloadException($action, UnlinkIdentityActionDTO::class, $dto);
                }
                $this->unlinkIdentity($acceptKey, $dto);

                return null;

            case ChatSignalConstants::SET_PASSWORD:
                if (!$dto instanceof SetPasswordActionDTO) {
                    throw new InvalidActionPayloadException($action, SetPasswordActionDTO::class, $dto);
                }
                $this->setPassword($acceptKey, $dto);

                return null;

            case ChatSignalConstants::ADD_SMS_REQUEST:
                if (!$dto instanceof RequestSmsAddCodeActionDTO) {
                    throw new InvalidActionPayloadException($action, RequestSmsAddCodeActionDTO::class, $dto);
                }
                $this->requestSmsAddCode($acceptKey, $dto);

                return null;

            case ChatSignalConstants::ADD_SMS_CONFIRM:
                if (!$dto instanceof ConfirmSmsAddCodeActionDTO) {
                    throw new InvalidActionPayloadException($action, ConfirmSmsAddCodeActionDTO::class, $dto);
                }
                $this->confirmSmsAddCode($acceptKey, $dto);

                return null;

            case ChatSignalConstants::ADD_PASSWORD_REQUEST:
                if (!$dto instanceof RequestAddPasswordActionDTO) {
                    throw new InvalidActionPayloadException($action, RequestAddPasswordActionDTO::class, $dto);
                }
                $this->requestAddPassword($acceptKey, $dto);

                return null;

            case ChatSignalConstants::ADD_PASSWORD_CONFIRM:
                if (!$dto instanceof ConfirmAddPasswordActionDTO) {
                    throw new InvalidActionPayloadException($action, ConfirmAddPasswordActionDTO::class, $dto);
                }
                $this->confirmAddPassword($acceptKey, $dto);

                return null;

            default:
                return parent::onAgentAction($acceptKey, $action, $dto);
        }
    }

    /**
     * Takes the moderator's verdict on a rename, or hands the name back to the framework.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $source Signal source
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one this library declared
     * @throws LogicException When the verdict payload is not the one its name promises
     * @throws AgentException When the verdict does not match a rename this connection is running
     * @throws ValidationException When a framework frame this library takes carries the wrong payload
     * @throws InvalidArgumentException When a frame the handler sends cannot be named or queued
     * @throws HilosException When the account read, the rename or the log line fails
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        $doneSignal = self::ADMIN_RENAME_ANSWERS[$name] ?? null;
        if ($doneSignal !== null) {
            if (!$data->data instanceof AdminRenameSignalData) {
                throw new LogicException($name . ' payload must be ' . AdminRenameSignalData::class);
            }
            $this->applyAdminRename($data->data, $doneSignal);

            return;
        }

        if ($name !== ChatSignalConstants::RENAME_MODERATION_RESULT) {
            parent::onSignalAgent($data, $source, $name);

            return;
        }

        if (!$data->data instanceof RenameModerationResultSignalData) {
            throw new LogicException(
                ChatSignalConstants::RENAME_MODERATION_RESULT
                . ' payload must be ' . RenameModerationResultSignalData::class,
            );
        }

        $this->applyRenameModerationResult($data->data);
    }

    /**
     * Renames one account for an administrator and logs it in the room (HIL-771).
     *
     * The body of both admin renames, whole, from the two pages that used to run it: the
     * account row, then the room's log line naming the administrator behind it. What changed is
     * only WHERE it runs - here, where the account set is owned, instead of in whichever worker
     * served the admin's socket. There is no moderation on this path and there never was: an
     * administrator's word about somebody else's name is the decision.
     *
     * Both refusals keep the sentences the pages sent, because they are what an admin reads. A
     * missing person and a name the row refuses are answers, not exceptions: the ask arrived as
     * a frame, and a throw here would leave the modal waiting forever.
     *
     * @param AdminRenameSignalData $rename Whom to rename, to what, and who is waiting
     * @param string $doneSignal Frame name the asking page listens on
     * @throws InvalidArgumentException When the answer cannot be named or queued
     */
    protected function applyAdminRename(AdminRenameSignalData $rename, string $doneSignal): void
    {
        $this->sendToAgent(
            $doneSignal,
            new AdminRenameDoneSignalData($rename->acceptKey, $rename->requestId, $this->renameForAdmin($rename)),
        );
    }

    /**
     * Writes the rename and its log line, or says why neither happened.
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
            Hilos::$db->events->actions->addUserRenamedByAdmin(
                userId: $rename->userId,
                oldName: $oldName,
                newName: $user->name,
                adminUserId: $rename->adminUserId,
            );
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
     * Names the chat's own members collection.
     *
     * @return string Collection name of the chat users
     */
    protected function usersCollection(): string
    {
        return ChatDbContext::users;
    }

    /**
     * Creates one chat user with the display name the ceremony earned.
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
     * Names one chat account the way the room shows it.
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
     * Announces a new member in the room the moment the account exists.
     *
     * @param int $userId User that was just created
     * @param string $identifier Normalized identifier the account was created for (unused)
     * @throws HilosException When the event write fails
     * @throws LogicException If event id is null after sync
     */
    public function afterUserCreated(int $userId, string $identifier): void
    {
        Hilos::$db->events->actions->addUserRegistered($userId);
    }

    /**
     * Builds the detector over the sign-in methods this demo has actually wired.
     *
     * @return IdentifierDetector Detector answering with the chat's enabled method keys
     */
    protected function buildAuthMethods(): IdentifierDetector
    {
        return ChatAuthMethods::detector();
    }

    /**
     * Builds the OAuth service the chat's providers are configured on.
     *
     * @return OAuthService Service over the demo's provider credentials
     */
    protected function buildOAuthService(): ?OAuthService
    {
        return ChatOAuthConfig::buildService();
    }

    /**
     * Starts moderation for a user-initiated rename action.
     *
     * The requested name is parked on the acting connection's own row, which is where the
     * moderator picks it up ({@see ModeratorAgent}); nothing is written to the person until the
     * verdict comes back to {@see applyRenameModerationResult()}.
     *
     * @param string $acceptKey Accept key
     * @param RenameActionDTO $dto Rename DTO
     * @throws EmptyValueException When name is empty
     * @throws ItemNotFoundForUpdateException When user session is missing
     * @throws ValidationException When another rename is already being moderated
     * @throws HilosException On runtime update failure
     */
    private function startRename(string $acceptKey, RenameActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            $this->logAgentError("Empty new name (acceptKey={$acceptKey})");
            throw new EmptyValueException('User name cannot be empty');
        }

        $connection = $this->actingConnection($acceptKey);

        if ($connection->renameModerationPhase === ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_CHECKING) {
            throw new ValidationException('Another rename is already being moderated');
        }

        $connection->actions->startRenameModeration($dto->newName);
    }

    /**
     * Unlinks one of the signed-in user's login identities (HIL-377, HIL-722).
     *
     * The thin demo half of the unlink: it delegates to the framework's unlink command
     * ({@see IdentityCommands::unlink()}), which resolves the acting user, refuses a last
     * sign-in method, and takes a passkey out whole — anchor and stored credential together.
     * It calls the command rather than the identity primitive because the primitive removes
     * the anchor alone, and the credential left behind kept signing its owner in. Success is
     * state-driven — the deletes broadcast DB_SYNC_DELETED, re-emitting the owner's identities
     * projection so the row disappears from every connection; a rejected unlink surfaces
     * through the default framework action_error contract.
     *
     * @param string $acceptKey Accept key
     * @param UnlinkIdentityActionDTO $dto Unlink DTO carrying the identity id
     * @throws ValidationException When the id is missing, not owned by the user, or is their last identity
     * @throws ItemNotFoundForUpdateException When the user session is missing
     * @throws HilosException When an identity or credential lookup or delete fails
     */
    private function unlinkIdentity(string $acceptKey, UnlinkIdentityActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            throw new ValidationException('Identity id is required');
        }

        $this->identityCommands()->unlink($acceptKey, $dto->identityId);
    }

    /**
     * Adds or changes the signed-in user's password from the profile (HIL-402).
     *
     * Server-authoritative and self-only: the user id is read from the acting connection, never
     * the client. Two in-scope flows, chosen from the user's own identities (never from
     * a client flag): a CHANGE (the user already has a `password` identity) re-auths the
     * current password before rewriting the secret; an ADD (no password yet) attaches a
     * `password` identity to the user's already-proven email — no email code, since the
     * email is verified — and marks it verified. A user with no verified email (SMS-only
     * or legacy OAuth) is out of scope here and refused (that path is the email+code
     * branch, HIL-406). On success a {@see ChatSignalConstants::PASSWORD_UPDATED} signal
     * is fanned to all the user's connections, because a change moves nothing in the
     * identity projection that could confirm it; a refusal surfaces through the default
     * framework action_error contract.
     *
     * Which password it changes is the framework's answer and no longer this project's own
     * search (HIL-692): an account holds one, and asking for it by account is what makes
     * "your password is changed" true even for data written before the rule.
     *
     * @param string $acceptKey Accept key
     * @param SetPasswordActionDTO $dto Set-password DTO (new password + optional current)
     * @throws ItemNotFoundForUpdateException When the user session is missing
     * @throws ValidationException When the new password is too weak, the current password is wrong, or the user has no verified email
     * @throws InvalidArgumentException When the password-updated signal cannot be named or queued
     * @throws HilosException When an identity read or secret write query fails
     */
    private function setPassword(string $acceptKey, SetPasswordActionDTO $dto): void
    {
        $userId = $this->requireUserId($acceptKey);

        if (strlen($dto->newPassword) < PasswordPolicy::MIN_LENGTH) {
            throw new ValidationException('Password must be at least ' . PasswordPolicy::MIN_LENGTH . ' characters');
        }

        $passwordIdentity = Hilos::$db->identities->findPasswordByUser($userId);
        if ($passwordIdentity !== null) {
            if ($dto->currentPassword === '' || !$passwordIdentity->verifyPassword($dto->currentPassword)) {
                throw new ValidationException('Current password is incorrect');
            }
            $passwordIdentity->setPassword($dto->newPassword);
            $mode = PasswordUpdatedSignalData::MODE_CHANGED;
        } else {
            $email = Hilos::$db->identities->findVerifiedEmailByUser($userId);
            if ($email === null) {
                throw new ValidationException('Confirm an email address first');
            }
            Hilos::$db->identities->createPasswordIdentity($userId, $email, $dto->newPassword)->markVerified();
            $mode = PasswordUpdatedSignalData::MODE_ADDED;
        }

        $this->fanPasswordUpdated($userId, $mode);
    }

    /**
     * Step 1 of adding a phone identity: issues an OTP to the submitted number (HIL-403).
     *
     * Server-authoritative and self-only: the owning user is read from the acting connection,
     * never the client, and carried on the challenge so step 2 can assert the code
     * was minted for this user. The phone is normalized to E.164 (a malformed number
     * is refused synchronously); the code is issued through the framework
     * VerificationService, whose send gate can drop the request silently — the resend
     * cooldown for a repeat pressed too soon, and the per-window cap once too many
     * codes have gone to that number (HIL-421). Either way the step answers
     * `action_success` and the wizard advances to the code step, so a capped number
     * reaches a code screen for a message that is not coming until the window turns
     * over; there is no resend control here to say so. No duplicate-phone check
     * here: enumeration is avoided by only checking uniqueness on confirm, after the
     * code proves possession.
     *
     * @param string $acceptKey Accept key
     * @param RequestSmsAddCodeActionDTO $dto Add-phone request DTO (phone)
     * @throws ItemNotFoundForUpdateException When the user session is missing
     * @throws ValidationException When the phone is not a valid number
     * @throws EmptyValueException When the normalized identifier is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws HilosException When the verification query fails
     */
    private function requestSmsAddCode(string $acceptKey, RequestSmsAddCodeActionDTO $dto): void
    {
        $userId = $this->requireUserId($acceptKey);

        $phone = PhoneNumber::normalize($dto->phone);
        if ($phone === null) {
            throw new ValidationException('Enter a valid phone number');
        }

        // The send gate's verdict - cooldown hold, cap refusal or a real send - is
        // deliberately dropped: the profile has no resend control to hand a countdown
        // or a refusal to, and a repeat here is a repeated modal submit (HIL-421).
        new VerificationService()->issue(VerificationType::SMS_ADD, $phone, $userId);
    }

    /**
     * Step 2 of adding a phone identity: verifies the OTP and attaches the identity (HIL-403).
     *
     * Server-authoritative and self-only: the owning user is read from the acting connection.
     * The submitted code is verified against the `sms_add` challenge; a
     * missing/expired/wrong code — or a challenge minted for a different user than
     * this session (defence in depth against a swapped phone) — is refused with the
     * same generic message. On success a verified `sms` identity is attached to the
     * session user; the new row reaches every connection through the identities
     * projection re-emit (no bespoke success signal). A phone already used by any
     * identity is refused ('phone already used') and the existing link is never
     * moved.
     *
     * @param string $acceptKey Accept key
     * @param ConfirmSmsAddCodeActionDTO $dto Add-phone confirm DTO (phone, code)
     * @throws ItemNotFoundForUpdateException When the user session is missing
     * @throws ValidationException When the phone/code is invalid or the phone is already in use
     * @throws HilosException When a verification or identity query fails
     */
    private function confirmSmsAddCode(string $acceptKey, ConfirmSmsAddCodeActionDTO $dto): void
    {
        $userId = $this->requireUserId($acceptKey);

        $phone = PhoneNumber::normalize($dto->phone);
        $verifiedUserId = $phone === null
            ? null
            : new VerificationService()->verify(VerificationType::SMS_ADD, $phone, $dto->code);
        if ($phone === null || $verifiedUserId === null || $verifiedUserId !== $userId) {
            throw new ValidationException('Invalid or expired code');
        }

        try {
            Hilos::$db->identities->createSmsIdentity($userId, $phone);
        } catch (DuplicateValueException) {
            throw new ValidationException('That phone number is already in use');
        } catch (EmptyValueException) {
            throw new ValidationException('Enter a valid phone number');
        }
    }

    /**
     * Step 1 of adding a password to a user with no verified email: issues an email code (HIL-406).
     *
     * Server-authoritative and self-only: the owning user is read from the acting connection,
     * never the client, and carried on the challenge so step 2 can assert the code
     * was minted for this user. The email is lowercased and format-checked (a
     * malformed address is refused synchronously). Unlike the SMS-add step, uniqueness
     * IS checked here: because the code is mailed to the entered address, an email
     * already verified by ANOTHER account is refused without sending anything (never
     * mail a stranger's verified address); a free email — or one already the user's
     * own — issues a code through the framework VerificationService, whose send gate
     * can drop the request silently: the resend cooldown for a repeat pressed too
     * soon, and the per-window cap once too many codes have gone to that address
     * (HIL-421). Either way the step answers `action_success` and the wizard advances
     * to the code step, with no resend control here to report the difference.
     *
     * @param string $acceptKey Accept key
     * @param RequestAddPasswordActionDTO $dto Add-password request DTO (email)
     * @throws ItemNotFoundForUpdateException When the user session is missing
     * @throws ValidationException When the email is malformed or already verified by another account
     * @throws EmptyValueException When the normalized identifier is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws HilosException When a verification or identity query fails
     */
    private function requestAddPassword(string $acceptKey, RequestAddPasswordActionDTO $dto): void
    {
        $userId = $this->requireUserId($acceptKey);

        $email = strtolower($dto->email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('Enter a valid email address');
        }

        $ownerId = Hilos::$db->identities->findUserIdByVerifiedEmail($email);
        if ($ownerId !== null && $ownerId !== $userId) {
            throw new ValidationException('That email is already in use');
        }

        // Same as the phone step: no resend control on the profile, so neither the
        // countdown nor the cap refusal has anywhere to go (HIL-421).
        new VerificationService()->issue(VerificationType::EMAIL_ADD, $email, $userId);
    }

    /**
     * Step 2 of adding a password: verifies the email code and writes the identity (HIL-406).
     *
     * Server-authoritative and self-only: the owning user is read from the acting connection.
     * The new password is length-checked FIRST so a weak password never burns the
     * code, and an account that already HAS a password is refused next, for the same
     * reason and in its own words (HIL-692): this flow adds a password, an account holds
     * one, and the answer does not depend on which address was typed - so spending the
     * code to find that out would burn it over a question already settled. The old
     * refusal, "that email is already in use", was about the wrong thing entirely; the
     * address may be perfectly free. The submitted code is then verified against the
     * `email_add` challenge; a missing/expired/wrong code — or a challenge minted for a
     * different user than this session — is refused with the same generic message.
     * Uniqueness is re-checked after verify (a magic_link-verified collision on the same
     * email would slip past createPasswordIdentity's password-scoped duplicate guard)
     * before the write. On success a verified `password`
     * identity is attached to the session user on the now-proven email and a
     * {@see ChatSignalConstants::PASSWORD_UPDATED} signal (MODE_ADDED, reusing the
     * HIL-402 success signal) is fanned to all the user's connections, which clears
     * the form and flips the section to change-mode; the new identity also arrives
     * over the identities projection re-emit.
     *
     * @param string $acceptKey Accept key
     * @param ConfirmAddPasswordActionDTO $dto Add-password confirm DTO (email, code, new password)
     * @throws ItemNotFoundForUpdateException When the user session is missing
     * @throws ValidationException When the password is too weak, the account already has a
     *     password, the code is invalid/expired, or the email is already in use
     * @throws InvalidArgumentException When the password-updated signal cannot be named or queued
     * @throws HilosException When a verification or identity query fails
     */
    private function confirmAddPassword(string $acceptKey, ConfirmAddPasswordActionDTO $dto): void
    {
        $userId = $this->requireUserId($acceptKey);

        if (strlen($dto->newPassword) < PasswordPolicy::MIN_LENGTH) {
            throw new ValidationException('Password must be at least ' . PasswordPolicy::MIN_LENGTH . ' characters');
        }

        if (Hilos::$db->identities->findPasswordByUser($userId) !== null) {
            throw new ValidationException('This account already has a password');
        }

        $email = strtolower($dto->email);
        $verifiedUserId = new VerificationService()->verify(VerificationType::EMAIL_ADD, $email, $dto->code);
        if ($verifiedUserId === null || $verifiedUserId !== $userId) {
            throw new ValidationException('Invalid or expired code');
        }

        $ownerId = Hilos::$db->identities->findUserIdByVerifiedEmail($email);
        if ($ownerId !== null && $ownerId !== $userId) {
            throw new ValidationException('That email is already in use');
        }

        try {
            Hilos::$db->identities->createPasswordIdentity($userId, $email, $dto->newPassword)->markVerified();
        } catch (DuplicateValueException) {
            throw new ValidationException('That email is already in use');
        } catch (EmptyValueException) {
            throw new ValidationException('Enter a valid email address');
        }

        $this->fanPasswordUpdated($userId, PasswordUpdatedSignalData::MODE_ADDED);
    }

    /**
     * Applies an approved rename moderation result or tells the asker it was refused.
     *
     * Stale connection results fail the agent-signal contract and never rename a user.
     *
     * The refusal is addressed to the connection that asked, as the action_error of the
     * `rename` action it submitted - the same frame, for the same action name, that the page
     * used to send from its exception hook. It is sent here rather than thrown because the
     * hook that turned a thrown failure into that frame belongs to a page, and this handler
     * runs on an agent: throwing would leave the person's modal waiting on nothing.
     *
     * @param RenameModerationResultSignalData $result Moderation result for a requested display name
     * @throws AgentException When result does not match an active connection rename request
     * @throws InvalidArgumentException When the refusal frame cannot be named or queued
     * @throws HilosException On database, runtime, truth-source, or signal failure
     */
    private function applyRenameModerationResult(RenameModerationResultSignalData $result): void
    {
        $connection = Hilos::$rt->connections[$result->acceptKey] ?? null;
        if ($connection === null) {
            throw new AgentException('Rename moderation result connection is stale');
        }

        if (
            $connection->userId !== $result->userId
            || $connection->renameModerationPhase !== ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_CHECKING
            || $connection->renameModerationName !== $result->newName
        ) {
            throw new AgentException('Rename moderation result does not match active request');
        }

        if (!$result->allow) {
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            $phase = in_array($reason, ['service_unavailable', 'unknown'], true)
                ? ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_UNAVAILABLE
                : ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_REJECTED;
            $connection->actions->failRenameModeration($phase, $reason);
            if ($phase === ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_REJECTED) {
                $this->notifyRenameRejected($result->userId, $result->newName, $reason);
            }

            $this->refuseRename($result->acceptKey, $reason);

            return;
        }

        $user = Hilos::$db->users[$result->userId] ?? null;
        if ($user === null) {
            $connection->actions->failRenameModeration(
                ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_UNAVAILABLE,
                'user_not_found',
            );
            $this->refuseRename($result->acceptKey, 'User not found for rename');

            return;
        }

        $oldName = $user->name;
        $connection->actions->clearRenameModeration();
        $user->actions->rename($result->newName);

        Hilos::$db->events->actions->addUserRenamed(
            userId: $result->userId,
            oldName: $oldName,
            newName: $result->newName,
        );
    }

    /**
     * Tells one connection its rename was refused, in the frame it is already listening for.
     *
     * @param string $acceptKey Connection that asked for the rename
     * @param string $reason Moderation reason, shown as it is
     * @throws InvalidArgumentException When the action-error signal cannot be named or queued
     */
    private function refuseRename(string $acceptKey, string $reason): void
    {
        $this->sendToUser(
            SignalConstants::ACTION_ERROR,
            $acceptKey,
            new PageActionErrorSignalData(ChatSignalConstants::RENAME, $reason),
        );
    }

    /**
     * Notifies the user that moderation refused the display name they asked for.
     *
     * Only a verdict about the name notifies: an unavailable moderator is an
     * infrastructure failure, and there is nothing to tell the user about it. The
     * emit is best-effort with respect to the rejection - the refusal sent next
     * reaches the user whatever happens to the notification.
     *
     * @param int $userId User who asked to be renamed
     * @param string $newName Rejected display name
     * @param string $reason Moderation reason
     */
    private function notifyRenameRejected(int $userId, string $newName, string $reason): void
    {
        try {
            Hilos::$notify?->emit(new NotificationDraft(
                userId: $userId,
                type: ChatNotificationType::RENAME_REJECTED,
                title: 'Your new name was not accepted',
                severity: NotificationSeverity::WARNING,
                body: 'Moderation rejected it: ' . $reason,
                data: [
                    'reason' => $reason,
                    'newName' => $newName,
                ],
            ));
        } catch (HilosException $e) {
            $this->logAgentError(
                "Rename rejection notification failed for userId={$userId}: {$e->getMessage()}",
            );
        }
    }

    /**
     * Fans the password-updated signal to every socket the person has open.
     *
     * @param int $userId Account whose secret changed
     * @param string $mode Whether the password was added or changed, a {@see PasswordUpdatedSignalData} mode
     * @throws InvalidArgumentException When the signal cannot be named or queued
     */
    private function fanPasswordUpdated(int $userId, string $mode): void
    {
        foreach (Hilos::$rt->connections->forUser($userId) as $connection) {
            $this->sendToUser(
                ChatSignalConstants::PASSWORD_UPDATED,
                $connection->acceptKey,
                new PasswordUpdatedSignalData($mode),
            );
        }
    }

    /**
     * Resolves the connection that submitted, or refuses the action.
     *
     * Read off the chat's own connection rows rather than off `selfConnection`, which is what
     * the page handlers used: an agent is not the connection's page host and has no "self" - it
     * is handed an accept key and looks the row up.
     *
     * @param string $acceptKey Acting connection accept key
     * @return Connection Row of the connection that submitted
     * @throws ItemNotFoundForUpdateException When no live connection carries the key
     */
    private function actingConnection(string $acceptKey): Connection
    {
        $connection = Hilos::$rt->connections[$acceptKey] ?? null;
        if ($connection === null) {
            $this->logAgentError("User not found for acceptKey={$acceptKey}");
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        return $connection;
    }

    /**
     * Resolves the acting connection's user, or refuses the action.
     *
     * The refusal doubles as the guard behind {@see AUTH_ACTIONS}: an anonymous session is
     * turned away by the dispatcher before it gets here, and a connection that went anonymous
     * in between is turned away here.
     *
     * @param string $acceptKey Acting connection accept key
     * @return int Authenticated submitter user id
     * @throws ItemNotFoundForUpdateException When no live connection carries the key, or it is anonymous
     */
    private function requireUserId(string $acceptKey): int
    {
        $userId = $this->actingConnection($acceptKey)->userId;
        if ($userId === null) {
            $this->logAgentError("User not found for acceptKey={$acceptKey}");
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        return $userId;
    }
}
