<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Constants\PasswordPolicy;
use Demo\Chat\Core\Router\DTO\PasswordUpdatedSignalData;
use Demo\Chat\Core\Router\DTO\RenameModerationResultSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\Profile\LinkOAuthStartActionDTO;
use Demo\Chat\Pages\DTO\Profile\RenameActionDTO;
use Demo\Chat\Pages\DTO\Profile\SetPasswordActionDTO;
use Demo\Chat\Pages\DTO\Profile\UnlinkIdentityActionDTO;
use Hilos\Auth\OAuth\DTO\OAuthAuthorizeSignalData;
use Hilos\Auth\OAuth\Exception\OAuthUnknownProviderException;
use Hilos\Auth\OAuth\OAuthStateSigner;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\View\Item\Identity;
use Hilos\HilosException;
use Hilos\Pages\AbstractHilosProfilePage;

/**
 * Chat demo implementation of the framework current-user profile page.
 *
 * The framework owns the page identity (key, route, subscription signal); this
 * concrete binds the chat agent, the self-connection browser data, and the
 * user-initiated rename action. The page is served by the chat agent because the
 * rename runs through the connection runtime (moderation phase) the chat agent
 * is the truth source for. A rename failure surfaces through the framework
 * action_error contract (the default onActionException, reached for both the
 * synchronous validation and the async moderation reject that PageSignalRouter
 * routes back); success is state-driven — the renamed user fans out over the
 * self-connection data, so no explicit success ack is sent.
 *
 * @property ChatAgent $agent
 */
final class ProfilePage extends AbstractHilosProfilePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

    public const array ACTIONS = [
        ChatSignalConstants::RENAME => RenameActionDTO::class,
        ChatSignalConstants::UNLINK_IDENTITY => UnlinkIdentityActionDTO::class,
        ChatSignalConstants::SET_PASSWORD => SetPasswordActionDTO::class,
        ChatSignalConstants::LINK_OAUTH_START => LinkOAuthStartActionDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            ChatSignalConstants::RENAME_MODERATION_RESULT => RenameModerationResultSignalData::class,
        ],
    ];

    /**
     * Routes profile actions to typed handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws ValidationException When an unlink is refused, a password change/add is refused (weak, wrong current, or no verified email), or an OAuth link provider is unknown
     * @throws \Random\RandomException When minting an OAuth link state cannot draw from the CSPRNG
     * @throws HilosException When rename moderation setup fails, an identity delete query fails, or a password read/write query fails
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        switch ($action) {
            case ChatSignalConstants::RENAME:
                if (!$dto instanceof RenameActionDTO) {
                    throw new InvalidActionPayloadException($action, RenameActionDTO::class, $dto);
                }
                $this->handleRename($acceptKey, $dto);

                break;

            case ChatSignalConstants::UNLINK_IDENTITY:
                if (!$dto instanceof UnlinkIdentityActionDTO) {
                    throw new InvalidActionPayloadException($action, UnlinkIdentityActionDTO::class, $dto);
                }
                $this->handleUnlinkIdentity($acceptKey, $dto);

                break;

            case ChatSignalConstants::SET_PASSWORD:
                if (!$dto instanceof SetPasswordActionDTO) {
                    throw new InvalidActionPayloadException($action, SetPasswordActionDTO::class, $dto);
                }
                $this->handleSetPassword($acceptKey, $dto);

                break;

            case ChatSignalConstants::LINK_OAUTH_START:
                if (!$dto instanceof LinkOAuthStartActionDTO) {
                    throw new InvalidActionPayloadException($action, LinkOAuthStartActionDTO::class, $dto);
                }
                $this->handleLinkOAuthStart($dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Routes profile-page agent signals to rename moderation handling.
     *
     * @param AgentSignalData $data Wrapped rename moderation result payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Agent signal name
     * @throws AgentUnknownSignalException When signal name is not supported by this page
     * @throws LogicException When the rename moderation payload type does not match the signal contract
     * @throws ValidationException When moderation rejects the requested display name
     * @throws AgentException When moderation result does not match an active rename request
     * @throws HilosException On database, runtime, truth-source, or signal failure
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        switch ($name) {
            case ChatSignalConstants::RENAME_MODERATION_RESULT:
                if (!$data->data instanceof RenameModerationResultSignalData) {
                    throw new LogicException(
                        ChatSignalConstants::RENAME_MODERATION_RESULT
                        . ' payload must be ' . RenameModerationResultSignalData::class,
                    );
                }
                $this->handleRenameModerationResult($data->data);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Starts moderation for a user-initiated rename action.
     *
     * @param string $acceptKey Accept key
     * @param RenameActionDTO $dto Rename DTO
     * @throws EmptyValueException When name is empty
     * @throws ItemNotFoundForUpdateException When user session is missing
     * @throws ValidationException When another rename is already being moderated
     * @throws HilosException On runtime update failure
     */
    private function handleRename(string $acceptKey, RenameActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            $this->logAgentError("Empty new name (acceptKey={$acceptKey})");
            throw new EmptyValueException('User name cannot be empty');
        }

        if (Hilos::$rt->selfConnection === null) {
            $this->logAgentError("User not found for acceptKey={$acceptKey}");
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        if (
            Hilos::$rt->selfConnection->renameModerationPhase
            === ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_CHECKING
        ) {
            throw new ValidationException('Another rename is already being moderated');
        }

        Hilos::$rt->selfConnection->actions->startRenameModeration($dto->newName);
    }

    /**
     * Unlinks one of the signed-in user's login identities (HIL-377).
     *
     * The thin demo half of the unlink: it resolves the session user from the
     * self-connection and delegates to the framework identity primitive, which
     * owns the server-authoritative guards (ownership, last-identity refusal) and
     * the delete. Success is state-driven — the delete broadcasts DB_SYNC_DELETED,
     * re-emitting the owner's identities projection so the row disappears from
     * every connection; a rejected unlink surfaces through the default framework
     * action_error contract.
     *
     * @param string $acceptKey Accept key
     * @param UnlinkIdentityActionDTO $dto Unlink DTO carrying the identity id
     * @throws ValidationException When the id is missing, not owned by the user, or is their last identity
     * @throws ItemNotFoundForUpdateException When the user session is missing
     * @throws DatabaseException When the identity lookup or delete query fails
     */
    private function handleUnlinkIdentity(string $acceptKey, UnlinkIdentityActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            throw new ValidationException('Identity id is required');
        }

        if (Hilos::$rt->selfConnection === null) {
            $this->logAgentError("User not found for acceptKey={$acceptKey}");
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        Hilos::$db->identities->deleteIdentity(Hilos::$rt->selfConnection->userId, $dto->identityId);
    }

    /**
     * Adds or changes the signed-in user's password from the profile (HIL-402).
     *
     * Server-authoritative and self-only: the user id is read from the session, never
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
     * @param string $acceptKey Accept key
     * @param SetPasswordActionDTO $dto Set-password DTO (new password + optional current)
     * @throws ItemNotFoundForUpdateException When the user session is missing
     * @throws ValidationException When the new password is too weak, the current password is wrong, or the user has no verified email
     * @throws DatabaseException When an identity read or secret write query fails
     */
    private function handleSetPassword(string $acceptKey, SetPasswordActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null || Hilos::$rt->selfConnection->userId === null) {
            $this->logAgentError("User not found for acceptKey={$acceptKey}");
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $userId = Hilos::$rt->selfConnection->userId;

        if (strlen($dto->newPassword) < PasswordPolicy::MIN_LENGTH) {
            throw new ValidationException('Password must be at least ' . PasswordPolicy::MIN_LENGTH . ' characters');
        }

        $passwordIdentity = $this->findPasswordIdentity($userId);
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

        foreach (Hilos::$rt->connections->forUser($userId) as $connection) {
            $this->sendToUser(
                ChatSignalConstants::PASSWORD_UPDATED,
                $connection->acceptKey,
                new PasswordUpdatedSignalData($mode),
            );
        }
    }

    /**
     * Finds the signed-in user's `password` identity, if any.
     *
     * @param int $userId Owning user id
     * @return ?Identity The user's password identity, or null when they have none
     * @throws DatabaseException When the identity lookup query fails
     */
    private function findPasswordIdentity(int $userId): ?Identity
    {
        foreach (Hilos::$db->identities->listByUser($userId) as $identity) {
            if ($identity->type === IdentityType::PASSWORD) {
                return $identity;
            }
        }

        return null;
    }

    /**
     * Begins linking an OAuth provider to the signed-in account (HIL-401).
     *
     * The link-mode analog of the login start action: authenticated (the whole
     * profile page is an AUTHENTICATED surface), it mints a link-mode authorize URL
     * whose signed `state` carries mode=link so the callback binds the identity to
     * this session's user instead of resolving an account. The initiator's user id
     * is not carried here — it is read server-side from the session at callback time
     * ({@see \Demo\Chat\Pages\MainPage::handleOauthCallback()}), so a client can
     * never link into another account. The URL rides the OAUTH_AUTHORIZE signal
     * (the framework `action_success` carries no domain payload); the SPA navigates
     * there. An unknown provider is a synchronous rejection.
     *
     * @param LinkOAuthStartActionDTO $dto Parsed link-start payload (provider)
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the provider is not configured
     * @throws \Random\RandomException When the platform CSPRNG cannot produce a state nonce
     */
    private function handleLinkOAuthStart(LinkOAuthStartActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null) {
            $this->logAgentError('User not found for OAuth link start');
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $connection = Hilos::$rt->selfConnection;

        try {
            $authorizeUrl = ChatOAuthConfig::buildService()
                ->beginAuthorization($dto->provider, $connection->sessionToken, OAuthStateSigner::MODE_LINK);
        } catch (OAuthUnknownProviderException) {
            throw new ValidationException('Unknown authentication provider');
        }

        $this->sendToUser(
            HilosSignalConstants::HILOS_OAUTH_AUTHORIZE,
            $connection->acceptKey,
            new OAuthAuthorizeSignalData($connection->acceptKey, $authorizeUrl),
        );
    }

    /**
     * Applies an approved rename moderation result or exposes a retryable failure.
     *
     * Stale connection results fail the agent-signal contract and never rename a user.
     *
     * @param RenameModerationResultSignalData $result Moderation result for a requested display name
     * @throws ValidationException When moderation rejects the display name or is unavailable
     * @throws AgentException When result does not match an active connection rename request
     * @throws HilosException On database, runtime, truth-source, or signal failure
     */
    private function handleRenameModerationResult(RenameModerationResultSignalData $result): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new AgentException('Rename moderation result connection is stale');
        }

        if (
            Hilos::$rt->selfConnection->acceptKey !== $result->acceptKey
            || Hilos::$rt->selfConnection->userId !== $result->userId
            || Hilos::$rt->selfConnection->renameModerationPhase
            !== ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_CHECKING
            || Hilos::$rt->selfConnection->renameModerationName !== $result->newName
        ) {
            throw new AgentException('Rename moderation result does not match active request');
        }

        if (!$result->allow) {
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            $phase = in_array($reason, ['service_unavailable', 'unknown'], true)
                ? ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_UNAVAILABLE
                : ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_REJECTED;
            Hilos::$rt->selfConnection->actions->failRenameModeration(
                $phase,
                $reason,
            );

            throw new ValidationException($reason);
        }

        $user = Hilos::$db->users[$result->userId] ?? null;
        if ($user === null) {
            Hilos::$rt->selfConnection->actions->failRenameModeration(
                ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_UNAVAILABLE,
                'user_not_found',
            );
            throw new ItemNotFoundForUpdateException('User not found for rename');
        }

        $oldName = $user->name;
        Hilos::$rt->selfConnection->actions->clearRenameModeration();
        $user->actions->rename($result->newName);

        Hilos::$db->events->actions->addUserRenamed(
            userId: $result->userId,
            oldName: $oldName,
            newName: $result->newName,
        );
    }
}
