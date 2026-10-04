<?php

declare(strict_types=1);

namespace Hilos\Auth\Library;

use Hilos\Auth\AccountDeletion\DTO\AccountDeletionCancelActionDTO;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionCodeActionDTO;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionOpenActionDTO;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionStartActionDTO;
use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Library\Command\AbstractLibraryCommands;
use Hilos\Auth\Library\Command\AccountDeletionCommands;
use Hilos\Auth\Library\Command\LegalAcceptanceCommands;
use Hilos\Auth\Library\Command\ActingSession;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\Library\Command\DetectionCommands;
use Hilos\Auth\Library\Command\EmailChangeCommands;
use Hilos\Auth\Library\Command\IdentityCommands;
use Hilos\Auth\Library\Command\MagicLinkCommands;
use Hilos\Auth\Library\Command\OAuthCommands;
use Hilos\Auth\Library\Command\PasskeyCommands;
use Hilos\Auth\Library\Command\PasswordChangeCommands;
use Hilos\Auth\Library\Command\PasswordCommands;
use Hilos\Auth\Library\Command\PhoneCodeCommands;
use Hilos\Auth\Library\Command\ProfilePhotoCommands;
use Hilos\Auth\Library\Command\RecoveryCommands;
use Hilos\Auth\Library\Command\SecondFactorCommands;
use Hilos\Auth\Library\Command\StepUpCommands;
use Hilos\Auth\Library\Command\UserRenameCommands;
use Hilos\Auth\Library\DTO\AuthOtherSessionsEndSignalData;
use Hilos\Auth\Library\DTO\AuthPasswordChangedSignalData;
use Hilos\Auth\Library\DTO\AuthRecoveryGrantedSignalData;
use Hilos\Auth\Library\DTO\AuthRecoveryWaitMovedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationCanceledSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationLandedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationProvenSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationWaitMovedSignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorCancelSignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorMissedSignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorOffSignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorSetupProvenSignalData;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Library\DTO\CancelRegistrationActionDTO;
use Hilos\Auth\Library\DTO\CancelSecondFactorActionDTO;
use Hilos\Auth\Library\DTO\CompletePasswordResetActionDTO;
use Hilos\Auth\Library\DTO\CompleteRegistrationActionDTO;
use Hilos\Auth\Library\DTO\CompleteRegistrationPasskeyActionDTO;
use Hilos\Auth\Library\DTO\CompleteRegistrationPasswordlessActionDTO;
use Hilos\Auth\Library\DTO\ConfirmMagicLinkActionDTO;
use Hilos\Auth\Library\DTO\ConfirmMagicLinkCodeActionDTO;
use Hilos\Auth\Library\DTO\ConfirmPasswordResetActionDTO;
use Hilos\Auth\Library\DTO\ConfirmPhoneCodeActionDTO;
use Hilos\Auth\Library\DTO\ConfirmRegisterActionDTO;
use Hilos\Auth\Library\DTO\ConfirmSecondFactorActionDTO;
use Hilos\Auth\Library\DTO\DetectIdentifierActionDTO;
use Hilos\Auth\Library\DTO\LegalAcceptActionDTO;
use Hilos\Auth\Library\DTO\LegalConsentActionDTO;
use Hilos\Auth\Library\DTO\LegalConsentReplyDTO;
use Hilos\Auth\Library\DTO\LegalReconsentActionDTO;
use Hilos\Auth\Library\DTO\LegalReconsentPreviewActionDTO;
use Hilos\Auth\Library\DTO\LegalReconsentPreviewReplyDTO;
use Hilos\Legal\LegalConsentProjector;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalHoldCommandConstants;
use Hilos\Legal\LegalReconsentProjector;
use Hilos\Legal\LegalSettings;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Auth\Library\DTO\LinkOAuthAfterReauthActionDTO;
use Hilos\Auth\Library\DTO\LoginActionDTO;
use Hilos\Auth\Library\DTO\OAuthCallbackActionDTO;
use Hilos\Auth\Library\DTO\OAuthCreateAccountActionDTO;
use Hilos\Auth\Library\DTO\OAuthLoginReadySignalData;
use Hilos\Auth\Library\DTO\OAuthStartActionDTO;
use Hilos\Auth\Library\DTO\PasskeyDiscoverableLoginOptionsActionDTO;
use Hilos\Auth\Library\DTO\PasskeyLoginConfirmActionDTO;
use Hilos\Auth\Library\DTO\PasskeyRegisterConfirmActionDTO;
use Hilos\Auth\Library\DTO\PasskeyRegisterOptionsActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddPasswordConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddPasswordRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddSmsConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddSmsRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordCodeConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordCodeRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordOpenActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeCurrentConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeCurrentRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeNewConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeNewRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileFlowStepSignalData;
use Hilos\Auth\Library\DTO\ProfilePhotoRemoveActionDTO;
use Hilos\Auth\Library\DTO\ProfilePhotoSetActionDTO;
use Hilos\Auth\Library\DTO\ProfilePasswordUpdatedSignalData;
use Hilos\Auth\Library\DTO\ProfileSetPasswordActionDTO;
use Hilos\Auth\Library\DTO\ProfileUnlinkIdentityActionDTO;
use Hilos\Auth\Library\DTO\RegisterActionDTO;
use Hilos\Auth\Library\DTO\RegistrationPasskeyOptionsActionDTO;
use Hilos\Auth\Library\DTO\RequestMagicLinkActionDTO;
use Hilos\Auth\Library\DTO\RequestPasswordResetActionDTO;
use Hilos\Auth\Library\DTO\RequestPhoneCodeActionDTO;
use Hilos\Auth\Library\DTO\RequestRegisterConfirmActionDTO;
use Hilos\Auth\Library\DTO\SecondFactorResetCancelLinkActionDTO;
use Hilos\Auth\Library\DTO\SecondFactorResetRequestActionDTO;
use Hilos\Auth\Library\DTO\SecondFactorSetupConfirmActionDTO;
use Hilos\Auth\Library\DTO\SecondFactorSetupFinishActionDTO;
use Hilos\Auth\Library\DTO\SecondFactorSetupStartActionDTO;
use Hilos\Auth\Method\AuthMethodGate;
use Hilos\Auth\Method\EnabledAuthMethods;
use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Auth\OAuth\DTO\OAuthTripEndedSignalData;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorCodesRenewActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorCodesShowActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorEnrollConfirmActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorEnrollStartActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorRemoveActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorResetCancelActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorResetRequestActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorResetWaitSetActionDTO;
use Hilos\Auth\SecondFactor\SecondFactorResetSweeper;
use Hilos\Auth\Session\SessionAck;
use Hilos\Auth\StepUp\DTO\StepUpConfirmActionDTO;
use Hilos\Auth\StepUp\DTO\StepUpStartActionDTO;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Auth\Verification\VerificationSweepCommandConstants;
use Hilos\Auth\Verification\VerificationSweepSettings;
use Hilos\Auth\Verification\VerificationSweeper;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Item\UserRename;
use Hilos\Database\Schema\EntitySchemaAxis;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Runtime\State\Item\HilosProfileFlow;
use Hilos\Runtime\State\Item\HilosProfilePhotoCheck;
use Hilos\Runtime\State\Item\HilosUpload;
use Hilos\Auth\AccountDeletion\AccountDeletionSettings;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Users\AccountStandingResolver;
use Hilos\Users\AskingAdministrator;
use Hilos\Users\DTO\AccountDeletionSetSignalData;
use Hilos\Users\DTO\AdminRenameSignalData;
use Hilos\Users\DTO\ProfilePhotoVerdictSignalData;
use Hilos\WiringRefusal;
use Random\RandomException;
use Throwable;

/**
 * The users library: the one owner of the user set and of every command that writes it.
 *
 * An entity library in the sense of docs/agents/architecture/entity-libraries.md, and the
 * first one in code (HIL-622). What it owns is a SET - users, identities, registration
 * reservations, verifications, passkey credentials - and every sign-in command over that
 * set: detecting an identifier, passwords, codes, magic links, passkeys, OAuth account
 * resolution. Before this agent existed those commands sat on one project's page, which
 * made the door into a framework only that project had.
 *
 * WHAT IT DOES NOT OWN, and cannot: sessions and the parked sign-in surfaces. They belong
 * to a library of their own ({@see AbstractSessionsLibraryAgent}), because one entity is one
 * library and the two are placed differently - sessions are touched by every handshake,
 * users only by a sign-in. A truth source is not handed to a second process either, so a
 * command that ends in a signed-in person ends in a frame to that library, never in a
 * session write of its own.
 *
 * It is ABSTRACT by convention alone, like {@see AbstractNotificationsLibraryAgent}: every
 * Hilos agent is mounted through a concrete class in the project's registry. The person is
 * the framework's (HIL-1194) - created in `hilos_user` ({@see createUser()}), named from it
 * ({@see displayNameOf()}), renamed with a row of its rename journal ({@see renameUser()},
 * HIL-1195), and refused deletion while an administrator
 * ({@see assertAdministratorMayDelete()}). What a project adds is its providers
 * ({@see buildOAuthService()}), whatever it does when an account is born
 * ({@see afterUserCreated()}) or renamed ({@see afterUserRenamed()}) and the claims over its
 * own tables; the methods it offers for an
 * identifier are its method directory narrowed by the admin ({@see EnabledAuthMethods}).
 * Everything else - the commands, their guards, their answers - stays here and is the same for
 * every project that declares {@see HilosFeature::AUTH}.
 */
abstract class AbstractUsersLibraryAgent extends AbstractAgent
{
    /**
     * The account set, and the proofs an account is reached by: its ways in, the codes that check
     * them, the holds a registration takes, the credentials a passkey enrols.
     *
     * The four proofs are claimed OUTRIGHT and with every operation. They used to be described as
     * needing no claim of their own, and that sentence held on nothing but the guard's silence:
     * the right was asked only of the four eagerly loaded collections, and these four are lazy
     * (HIL-716). Every write to them goes through a command of this library - a code is issued and
     * spent, a password secret is rewritten, a hold is taken and released - so the operation set
     * is spelled out per entry rather than left to {@see self::defaultTruthSourceOperations()}:
     * that default is what a library does to a row it SHARES, and the rows carrying an account's
     * proofs are edited in place.
     *
     * The account set is claimed whole and with every operation too (HIL-1194). The table and its
     * key are the framework's (`hilos_user`, HIL-1192), so the claim no longer waits for a
     * project to name the collection. Every operation, and not the create-only right it began as
     * (HIL-771): a page carries no claim, so the writers that used to rename somebody from a
     * profile submit come here instead, and renaming is editing the row.
     *
     * The rename journal is written by this library alone (HIL-1195), in one transaction with
     * the name it records ({@see renameUser()}): a rename and its row stand or fall together, so
     * they have one writer. Every operation, because an erased person's rows leave with them.
     *
     * The second factor is a proof of the account too (HIL-494), so four of its tables are here
     * the same way: the authenticators, the backup codes, the delayed removals and each person's
     * own removal wait are written by this library's commands and by its reset sweep, and by
     * nothing else. The fifth, the browsers trusted to skip the step, is keyed by a session row
     * and belongs to the session holder.
     *
     * A step-up confirmation is another proof owned here (HIL-495): this library derives,
     * checks and records it while executing the protected account command. Its browser key is
     * a token hash, but its set is the person whose operation it opens.
     *
     * A person's own request to delete their account is here too (HIL-302): this library's
     * commands start it and call it off. The session holder carries it out, and marks it done
     * under a borrowed right of its own - it is the one that erases the account.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [
        HilosDbContext::users => TruthSourceOperation::ALL,
        HilosDbContext::identities => TruthSourceOperation::ALL,
        HilosDbContext::verifications => TruthSourceOperation::ALL,
        HilosDbContext::registrationReservations => TruthSourceOperation::ALL,
        HilosDbContext::passkeyCredentials => TruthSourceOperation::ALL,
        HilosDbContext::secondFactors => TruthSourceOperation::ALL,
        HilosDbContext::secondFactorBackupCodes => TruthSourceOperation::ALL,
        HilosDbContext::secondFactorResets => TruthSourceOperation::ALL,
        HilosDbContext::secondFactorSettings => TruthSourceOperation::ALL,
        HilosDbContext::stepUps => TruthSourceOperation::ALL,
        HilosDbContext::accountDeletions => TruthSourceOperation::ALL,
        HilosDbContext::legalAcceptances => TruthSourceOperation::ALL,
        HilosDbContext::userRenames => TruthSourceOperation::ALL,
        HilosDbContext::userPhotos => TruthSourceOperation::ALL,
    ];

    /** @var array<string, list<TruthSourceOperation>> */
    public const array OWNS_RT = [
        HilosProfilePhotoCheck::RT_COLLECTION => TruthSourceOperation::BY_KIND,
    ];

    /** @var list<string> */
    public const array READS_RT = [HilosUpload::RT_COLLECTION];

    /** The files library owns the registry row whose owner decides a published photo's person. */
    public const array READS_DB = [HilosDbContext::files];

    public const string AGENT_TYPE = HilosAgentType::HILOS_USERS_LIBRARY;

    /**
     * The throttle verdict is declared HERE and must not be declared by a second agent of
     * the project: routing picks the verdict's destination from whoever declared it, and
     * this is the agent whose dispatcher parks the throttled sign-in commands and waits for
     * it. Declaring it elsewhere takes the answer away from the pool that is waiting (HIL-420).
     * The admin card also hands deletion requests here, because this library owns their rows
     * and publishes the account's state after scheduling or canceling them (HIL-304). It hands
     * a rename here for the same reason (HIL-771): the name and its journal row are this
     * library's to write (HIL-1195).
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT => ThrottleVerdictSignalData::class,
        HilosSignalConstants::HILOS_OAUTH_LOGIN_READY => OAuthLoginReadySignalData::class,
        HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET => AccountDeletionSetSignalData::class,
        HilosSignalConstants::HILOS_USER_ADMIN_RENAME => AdminRenameSignalData::class,
        HilosSignalConstants::HILOS_PROFILE_PHOTO_VERDICT => ProfilePhotoVerdictSignalData::class,
        HilosSignalConstants::HILOS_PROFILE_PHOTO_PUBLISHED => FilesPublishedSignalData::class,
    ];

    /**
     * The sign-in commands this library owns, by wire name.
     *
     * The list is the door: an action named here is routed to this agent by
     * {@see Hilos::getAgentActionRoutes()} and parsed into the DTO beside it. It grows one
     * command group at a time as the groups land, and every name in it has a branch in
     * {@see onAgentAction()} - a name without one is an action the router accepts and the
     * handler then refuses, which is worse than an action nobody declared.
     */
    public const array AGENT_ACTIONS = [
        HilosSignalConstants::HILOS_DETECT_IDENTIFIER => DetectIdentifierActionDTO::class,
        HilosSignalConstants::HILOS_LEGAL_CONSENT => LegalConsentActionDTO::class,
        HilosSignalConstants::HILOS_LEGAL_RECONSENT => LegalReconsentActionDTO::class,
        HilosSignalConstants::HILOS_LEGAL_ACCEPT => LegalAcceptActionDTO::class,
        HilosSignalConstants::HILOS_LEGAL_RECONSENT_PREVIEW => LegalReconsentPreviewActionDTO::class,
        HilosSignalConstants::HILOS_LOGIN => LoginActionDTO::class,
        HilosSignalConstants::HILOS_REGISTER => RegisterActionDTO::class,
        HilosSignalConstants::HILOS_REQUEST_PASSWORD_RESET => RequestPasswordResetActionDTO::class,
        HilosSignalConstants::HILOS_CONFIRM_PASSWORD_RESET => ConfirmPasswordResetActionDTO::class,
        HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET => CompletePasswordResetActionDTO::class,
        HilosSignalConstants::HILOS_REQUEST_REGISTER_CONFIRM => RequestRegisterConfirmActionDTO::class,
        HilosSignalConstants::HILOS_CONFIRM_REGISTER => ConfirmRegisterActionDTO::class,
        HilosSignalConstants::HILOS_COMPLETE_REGISTRATION => CompleteRegistrationActionDTO::class,
        HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSWORDLESS =>
            CompleteRegistrationPasswordlessActionDTO::class,
        HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSKEY => CompleteRegistrationPasskeyActionDTO::class,
        HilosSignalConstants::HILOS_CANCEL_REGISTRATION => CancelRegistrationActionDTO::class,
        HilosSignalConstants::HILOS_REQUEST_PHONE_CODE => RequestPhoneCodeActionDTO::class,
        HilosSignalConstants::HILOS_CONFIRM_PHONE_CODE => ConfirmPhoneCodeActionDTO::class,
        HilosSignalConstants::HILOS_REQUEST_MAGIC_LINK => RequestMagicLinkActionDTO::class,
        HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK => ConfirmMagicLinkActionDTO::class,
        HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK_CODE => ConfirmMagicLinkCodeActionDTO::class,
        HilosSignalConstants::HILOS_OAUTH_START => OAuthStartActionDTO::class,
        HilosSignalConstants::HILOS_OAUTH_CALLBACK => OAuthCallbackActionDTO::class,
        HilosSignalConstants::HILOS_LINK_OAUTH_AFTER_REAUTH => LinkOAuthAfterReauthActionDTO::class,
        HilosSignalConstants::HILOS_OAUTH_CREATE_ACCOUNT => OAuthCreateAccountActionDTO::class,
        HilosSignalConstants::HILOS_PASSKEY_REGISTER_OPTIONS => PasskeyRegisterOptionsActionDTO::class,
        HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM => PasskeyRegisterConfirmActionDTO::class,
        HilosSignalConstants::HILOS_REGISTRATION_PASSKEY_OPTIONS => RegistrationPasskeyOptionsActionDTO::class,
        HilosSignalConstants::HILOS_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS =>
            PasskeyDiscoverableLoginOptionsActionDTO::class,
        HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM => PasskeyLoginConfirmActionDTO::class,
        HilosSignalConstants::HILOS_CONFIRM_SECOND_FACTOR => ConfirmSecondFactorActionDTO::class,
        HilosSignalConstants::HILOS_CANCEL_SECOND_FACTOR => CancelSecondFactorActionDTO::class,
        HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_START => SecondFactorSetupStartActionDTO::class,
        HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_CONFIRM => SecondFactorSetupConfirmActionDTO::class,
        HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_FINISH => SecondFactorSetupFinishActionDTO::class,
        HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_REQUEST => SecondFactorResetRequestActionDTO::class,
        HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_CANCEL_LINK => SecondFactorResetCancelLinkActionDTO::class,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START => ProfileSecondFactorEnrollStartActionDTO::class,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_CONFIRM => ProfileSecondFactorEnrollConfirmActionDTO::class,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_REMOVE => ProfileSecondFactorRemoveActionDTO::class,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_SHOW => ProfileSecondFactorCodesShowActionDTO::class,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW => ProfileSecondFactorCodesRenewActionDTO::class,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_WAIT_SET => ProfileSecondFactorResetWaitSetActionDTO::class,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST => ProfileSecondFactorResetRequestActionDTO::class,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_CANCEL => ProfileSecondFactorResetCancelActionDTO::class,
        HilosSignalConstants::HILOS_STEP_UP_START => StepUpStartActionDTO::class,
        HilosSignalConstants::HILOS_STEP_UP_CONFIRM => StepUpConfirmActionDTO::class,
        HilosSignalConstants::PROFILE_SET_PASSWORD => ProfileSetPasswordActionDTO::class,
        HilosSignalConstants::PROFILE_UNLINK_IDENTITY => ProfileUnlinkIdentityActionDTO::class,
        HilosSignalConstants::PROFILE_ADD_SMS_REQUEST => ProfileAddSmsRequestActionDTO::class,
        HilosSignalConstants::PROFILE_ADD_SMS_CONFIRM => ProfileAddSmsConfirmActionDTO::class,
        HilosSignalConstants::PROFILE_ADD_PASSWORD_REQUEST => ProfileAddPasswordRequestActionDTO::class,
        HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM => ProfileAddPasswordConfirmActionDTO::class,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_REQUEST => ProfileEmailChangeCurrentRequestActionDTO::class,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_CONFIRM => ProfileEmailChangeCurrentConfirmActionDTO::class,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_REQUEST => ProfileEmailChangeNewRequestActionDTO::class,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM => ProfileEmailChangeNewConfirmActionDTO::class,
        HilosSignalConstants::PROFILE_PHOTO_SET => ProfilePhotoSetActionDTO::class,
        HilosSignalConstants::PROFILE_PHOTO_REMOVE => ProfilePhotoRemoveActionDTO::class,
        HilosSignalConstants::PROFILE_CHANGE_PASSWORD_OPEN => ProfileChangePasswordOpenActionDTO::class,
        HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_REQUEST => ProfileChangePasswordCodeRequestActionDTO::class,
        HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_CONFIRM => ProfileChangePasswordCodeConfirmActionDTO::class,
        HilosSignalConstants::PROFILE_CHANGE_PASSWORD => ProfileChangePasswordActionDTO::class,
        HilosSignalConstants::HILOS_ACCOUNT_DELETION_OPEN => AccountDeletionOpenActionDTO::class,
        HilosSignalConstants::HILOS_ACCOUNT_DELETION_CODE => AccountDeletionCodeActionDTO::class,
        HilosSignalConstants::HILOS_ACCOUNT_DELETION_START => AccountDeletionStartActionDTO::class,
        HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL => AccountDeletionCancelActionDTO::class,
    ];

    /**
     * Every anonymous-reachable door into an account is throttled (HIL-420), and the list
     * is exactly that: the ones that guess a secret and the ones that make the server spend
     * something on a stranger's say-so - an email, an SMS, a password hash, a registration
     * reservation, an account. Reads are absent, with the one exception that proves the rule:
     * DETECT_IDENTIFIER answers whether an account exists, which is precisely what an
     * enumerator wants, and this list is the whole of what keeps that answer expensive
     * (HIL-414). Canceling a registration is absent because it spends nothing and can only
     * ever undo the caller's own registration - its wait and the hold it took (HIL-829).
     *
     * The second factor adds the doors that guess a secret (HIL-494): a code on the way in, the
     * first code of an enrolment, the token of a cancel link, and the five profile submits that
     * start with a code. Its other submits spend nothing and only move the caller's own wait or
     * the caller's own choice.
     *
     * Operation step-up adds its confirm submit: it guesses a password, authenticator,
     * delivered code, or device-key assertion, so it passes the same throttle before work.
     *
     * The profile's own ways in and the email change add theirs by the same rule (HIL-1137):
     * the password submit, which guesses the current password on a change; the second step of
     * a phone and of a password by mail, which guess a code; and the three email-change steps
     * that carry the current address's code. The submits that only send a code are absent -
     * the code carries its own send cap - and so are the unlink and the provider link start.
     *
     * Password-change confirmation and save are throttled too (HIL-300): they guess a code
     * and can reach the password policy's unchanged-password answer.
     *
     * Account deletion adds its start by the same rule (HIL-302): it guesses the code the
     * address received. Opening the window, sending the code and calling it off guess nothing.
     *
     * The passkey door of a new account adds both its submits (HIL-1104): the first answers
     * whether an identifier is somebody's, which is what the lookup is throttled for, and the
     * second creates an account.
     */
    public const array THROTTLED_ACTIONS = [
        HilosSignalConstants::HILOS_DETECT_IDENTIFIER,
        HilosSignalConstants::HILOS_LOGIN,
        HilosSignalConstants::HILOS_REGISTER,
        HilosSignalConstants::HILOS_REQUEST_PASSWORD_RESET,
        HilosSignalConstants::HILOS_CONFIRM_PASSWORD_RESET,
        HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET,
        HilosSignalConstants::HILOS_REQUEST_REGISTER_CONFIRM,
        HilosSignalConstants::HILOS_CONFIRM_REGISTER,
        HilosSignalConstants::HILOS_COMPLETE_REGISTRATION,
        HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSWORDLESS,
        HilosSignalConstants::HILOS_REGISTRATION_PASSKEY_OPTIONS,
        HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSKEY,
        HilosSignalConstants::HILOS_OAUTH_CREATE_ACCOUNT,
        HilosSignalConstants::HILOS_REQUEST_PHONE_CODE,
        HilosSignalConstants::HILOS_CONFIRM_PHONE_CODE,
        HilosSignalConstants::HILOS_REQUEST_MAGIC_LINK,
        HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK,
        HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK_CODE,
        HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM,
        HilosSignalConstants::HILOS_CONFIRM_SECOND_FACTOR,
        HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_CONFIRM,
        HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_CANCEL_LINK,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_CONFIRM,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_REMOVE,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_SHOW,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW,
        HilosSignalConstants::HILOS_STEP_UP_CONFIRM,
        HilosSignalConstants::PROFILE_SET_PASSWORD,
        HilosSignalConstants::PROFILE_ADD_SMS_CONFIRM,
        HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_CONFIRM,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_REQUEST,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM,
        HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_CONFIRM,
        HilosSignalConstants::PROFILE_CHANGE_PASSWORD,
        HilosSignalConstants::HILOS_ACCOUNT_DELETION_START,
    ];

    /**
     * The commands that add to an account rather than open one, and so need a signed-in
     * session. Everything else here is a guest's way in and must stay open to one. The
     * profile's second-factor commands are here whole (HIL-494): they act on the person the
     * session belongs to and on nobody else. Step-up start and confirm stay outside this list:
     * actingPerson also admits a blocked card for an operation that declares that entrance (HIL-303).
     * The profile's own ways in and the email change are here whole (HIL-1137): each reads its person
     * from the acting session, which an anonymous one has none of. So are the four submits of
     * account deletion (HIL-302) and password change (HIL-300), for the same reason, and the
     * re-consent read and the acceptance (HIL-500): both are about the documents the person holds.
     */
    public const array AUTH_ACTIONS = [
        HilosSignalConstants::HILOS_LINK_OAUTH_AFTER_REAUTH,
        HilosSignalConstants::HILOS_PASSKEY_REGISTER_OPTIONS,
        HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_CONFIRM,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_REMOVE,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_SHOW,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_WAIT_SET,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_CANCEL,
        HilosSignalConstants::PROFILE_SET_PASSWORD,
        HilosSignalConstants::PROFILE_UNLINK_IDENTITY,
        HilosSignalConstants::PROFILE_ADD_SMS_REQUEST,
        HilosSignalConstants::PROFILE_ADD_SMS_CONFIRM,
        HilosSignalConstants::PROFILE_ADD_PASSWORD_REQUEST,
        HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_REQUEST,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_CONFIRM,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_REQUEST,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM,
        HilosSignalConstants::PROFILE_PHOTO_SET,
        HilosSignalConstants::PROFILE_PHOTO_REMOVE,
        HilosSignalConstants::PROFILE_CHANGE_PASSWORD_OPEN,
        HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_REQUEST,
        HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_CONFIRM,
        HilosSignalConstants::PROFILE_CHANGE_PASSWORD,
        HilosSignalConstants::HILOS_ACCOUNT_DELETION_OPEN,
        HilosSignalConstants::HILOS_ACCOUNT_DELETION_CODE,
        HilosSignalConstants::HILOS_ACCOUNT_DELETION_START,
        HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL,
        HilosSignalConstants::HILOS_LEGAL_RECONSENT,
        HilosSignalConstants::HILOS_LEGAL_ACCEPT,
    ];

    /**
     * The commands of this library a frozen person still runs (HIL-945). Calling off their own
     * deletion: closing it would build a trap - to keep the account one would first have to accept
     * the terms of an account one is leaving. And reading the new terms and accepting them - the
     * exit that lifts the freeze (HIL-500).
     */
    public const array FROZEN_EXIT_ACTIONS = [
        HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL,
        HilosSignalConstants::HILOS_LEGAL_RECONSENT,
        HilosSignalConstants::HILOS_LEGAL_ACCEPT,
    ];

    /**
     * The two commands answered by the users library (both test-only).
     *
     * The test: prefix enforces the production ban via NonProductionGate. Routed here because
     * this library owns both acceptance records and verification rows ({@see self::OWNS_DB}).
     */
    public const array AGENT_COMMANDS = [
        CliCommands::LEGAL_TEST_HOLD,
        CliCommands::VERIFICATION_TEST_SWEEP,
    ];

    /** Name of the cron rule of the second-factor removal sweep (HIL-494). */
    private const string SECOND_FACTOR_RESET_SWEEP_RULE = 'hilos_second_factor_reset_sweep';

    /** Name of the live verification sweep rule (HIL-1163). */
    private const string VERIFICATION_SWEEP_RULE = 'hilos_verification_sweep';

    /** Once a minute: a removal is carried out within a minute of its moment. */
    private const string SECOND_FACTOR_RESET_SWEEP_CRON = '* * * * *';

    /**
     * Action name of the dispatch running right now, or null outside one.
     *
     * Kept because a frame that hands an answer to the session holder has to name the
     * action being answered, and the command building that frame is three calls deep from
     * where the name was passed in. Threading it through every one of them would put a
     * parameter nothing but the last line reads on a dozen signatures.
     */
    private ?string $currentAction = null;

    /** The identifier lookup, built on first use. */
    private ?DetectionCommands $detectionCommands = null;

    /** The password door and the registration it mints, built on first use. */
    private ?PasswordCommands $passwordCommands = null;

    /** Both halves of the mailed letter, built on first use. */
    private ?MagicLinkCommands $magicLinkCommands = null;

    /** The two submits of a code sent to a phone, built on first use. */
    private ?PhoneCodeCommands $phoneCodeCommands = null;

    /** Both ends of a provider login, built on first use. */
    private ?OAuthCommands $oauthCommands = null;

    /** Enrolling a passkey and signing in with one, built on first use. */
    private ?PasskeyCommands $passkeyCommands = null;

    /** The three submits of a password recovery, built on first use. */
    private ?RecoveryCommands $recoveryCommands = null;

    /** The person's own ways in, added and taken off, built on first use. */
    private ?IdentityCommands $identityCommands = null;

    /** The four steps of changing the account's email, built on first use. */
    private ?EmailChangeCommands $emailChangeCommands = null;

    /** The existing-password change, built on first use. */
    private ?PasswordChangeCommands $passwordChangeCommands = null;

    /** The second factor's commands, built on first use. */
    private ?SecondFactorCommands $secondFactorCommands = null;

    /** Operation-level confirmation commands, built on first use. */
    private ?StepUpCommands $stepUpCommands = null;

    /** A person's own account deletion, built on first use. */
    private ?AccountDeletionCommands $accountDeletionCommands = null;

    /** Renaming a person with its journal row, built on first use. */
    private ?UserRenameCommands $userRenameCommands = null;

    /** Profile photo commands, built on first use. */
    private ?ProfilePhotoCommands $profilePhotoCommands = null;

    /** Schedule of the second-factor removal sweep, armed on start (HIL-494). */
    private ?CronRule $secondFactorResetSweepRule = null;

    /** Schedule rebuilt when its setting changes. */
    private ?CronRule $verificationSweepRule = null;

    /** A full batch means another pass is due on the next tick. */
    private bool $verificationSweepBacklog = false;

    /** Minute in which the schedule setting was last checked. */
    private int $verificationSweepCheckedMinute = -1;

    /**
     * What a library does to a row it shares with another owner: bring it into being, take it away.
     *
     * The default covers the claims made through the seam, and those are the co-owned ones -
     * whatever a project adds beside the framework's own. The two parked-surface collections
     * used to be the framework's example; since HIL-1044 the session holder writes them alone and
     * this library asks for every change by frame. A fact another holder is keeping is not this
     * library's to reword, which is why updating is absent and why a pair is not two writers of
     * one row.
     *
     * The account set is deliberately not among them: its claim is declared in this class's own
     * `OWNS_DB` with every operation, because a library that renames somebody edits the row it
     * owns, and there it owns rather than shares.
     *
     * @return TruthSourceOperations Adding and removing, never updating
     */
    public static function defaultTruthSourceOperations(): TruthSourceOperations
    {
        return TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Remove);
    }

    /**
     * The library holds nothing across a stop: its state is the database.
     */
    public function onStop(): void
    {
    }

    /**
     * Arms the once-a-minute sweep of second-factor removals (HIL-494).
     *
     * Never run rather than just run: removals whose time came while no library held the table
     * are the ones waiting longest, and the first tick carries them out.
     */
    public function onStart(): void
    {
        $rule = new CronRule(self::SECOND_FACTOR_RESET_SWEEP_RULE, self::SECOND_FACTOR_RESET_SWEEP_CRON);
        $rule->lastRun = 0.0;
        $this->secondFactorResetSweepRule = $rule;
    }

    /**
     * Carries out due second-factor removals and a bounded verification-code sweep.
     *
     * @throws HilosException When a lookup, a write, a frame or an announcement fails
     */
    public function onTick(): void
    {
        if ($this->secondFactorResetSweepRule?->shouldRun() === true) {
            new SecondFactorResetSweeper($this->secondFactorCommands())->sweep();
        }

        $this->sweepVerifications();
        if (Hilos::hasFeature(HilosFeature::PROFILE_PHOTO)) {
            $this->profilePhotoCommands()->sweepClosedConnections();
        }
    }

    /**
     * Sweeps one batch on schedule, then drains a full batch on subsequent ticks.
     * A changed schedule takes effect at the next minute without restarting the library.
     *
     * @throws HilosException When settings or verification persistence fails
     */
    private function sweepVerifications(): void
    {
        $minute = intdiv(time(), 60);
        if ($minute !== $this->verificationSweepCheckedMinute) {
            $this->verificationSweepCheckedMinute = $minute;
            $expression = VerificationSweepSettings::sweepCron();
            if ($this->verificationSweepRule === null || $this->verificationSweepRule->expression !== $expression) {
                $this->verificationSweepRule = new CronRule(self::VERIFICATION_SWEEP_RULE, $expression);
            }
        }

        if (!$this->verificationSweepBacklog && $this->verificationSweepRule?->shouldRun() !== true) {
            return;
        }

        $removed = new VerificationSweeper()->sweep();
        $this->verificationSweepBacklog = $removed === VerificationSweeper::BATCH;
        if ($removed > 0) {
            $retention = VerificationSweepSettings::retentionSeconds();
            $this->logAgentInfo("Verification sweep: removed {$removed} spent or expired codes older than {$retention} s");
        }
    }

    /**
     * Runs one sign-in command and answers the surface that submitted it.
     *
     * The whole of the routing this agent does: a name, the group that owns it, the reply
     * that group produced. A command whose ending is a session does not answer here at all -
     * it hands the answer to the session holder ({@see grantSession()} and its siblings) and
     * returns null, and the dispatcher then sends nothing.
     *
     * @param string $acceptKey Accept key of the connection that submitted
     * @param string $action Owned action name from {@see AGENT_ACTIONS}
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO What the surface is told, or null when the holder answers instead
     * @throws AgentUnknownActionException When the action is not one this library owns
     * @throws InvalidActionPayloadException When the payload does not match the action name
     * @throws ValidationException When the command refuses what was submitted
     * @throws RandomException When issuing a verification code cannot draw from the CSPRNG
     * @throws HilosException When a command exposes database, runtime, or settings failure
     */
    public function onAgentAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        $this->currentAction = $action;
        try {
            return $this->runOwnedAction($acceptKey, $action, $dto);
        } finally {
            $this->currentAction = null;
        }
    }

    /**
     * Handles one frame the OAuth agent addressed to this library.
     *
     * The only frame it takes: the provider answered, and turning that answer into an
     * account is this library's half of the login ({@see OAuthCommands::completeLogin()}).
     * The throttle verdict is addressed here only to reach the router underneath, which is
     * what holds the parked action waiting on it; the library itself has nothing to do
     * with it.
     *
     * A completion that breaks is answered, not only logged (HIL-1044): a tab is waiting on
     * this login, and an exception that went to the log alone left it waiting for good. It
     * learns the login failed through the session holder, like every other ending of a trip.
     *
     * The person's card hands an administrator's rename here (HIL-1195), and it is answered on
     * the frame it names, a refusal included: a modal is waiting on it.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one this library declared
     * @throws ValidationException When the payload is not the one its name promises
     * @throws InvalidArgumentException When the failure frame cannot be named or queued
     * @throws HilosException Whatever a project library that takes frames of its own raises on them
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT:
                return;

            case HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET:
                $this->handleDeletionSetRequest($data->data);

                return;

            case HilosSignalConstants::HILOS_USER_ADMIN_RENAME:
                if (!$data->data instanceof AdminRenameSignalData) {
                    throw new ValidationException(
                        HilosSignalConstants::HILOS_USER_ADMIN_RENAME . ' payload must be ' . AdminRenameSignalData::class,
                    );
                }
                $this->sendToAgent(
                    $data->data->replySignal,
                    HandoverAnswerSignalData::to($data->data, $this->handleAdminRename($data->data)),
                );

                return;

            case HilosSignalConstants::HILOS_PROFILE_PHOTO_VERDICT:
                if (!$data->data instanceof ProfilePhotoVerdictSignalData) {
                    throw new ValidationException(
                        HilosSignalConstants::HILOS_PROFILE_PHOTO_VERDICT . ' payload must be ' . ProfilePhotoVerdictSignalData::class,
                    );
                }
                $this->profilePhotoCommands()->verdict($data->data);

                return;

            case HilosSignalConstants::HILOS_PROFILE_PHOTO_PUBLISHED:
                if (!$data->data instanceof FilesPublishedSignalData) {
                    throw new ValidationException(
                        HilosSignalConstants::HILOS_PROFILE_PHOTO_PUBLISHED . ' payload must be ' . FilesPublishedSignalData::class,
                    );
                }
                $this->profilePhotoCommands()->published($data->data);

                return;

            case HilosSignalConstants::HILOS_OAUTH_LOGIN_READY:
                if (!$data->data instanceof OAuthLoginReadySignalData) {
                    throw new ValidationException(
                        HilosSignalConstants::HILOS_OAUTH_LOGIN_READY . ' payload must be ' . OAuthLoginReadySignalData::class,
                    );
                }
                $this->handleOAuthLoginReady($data->data);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Routes a CLI command sent to this library.
     *
     * The names of {@see self::AGENT_COMMANDS}; anything else gets an error reply
     * rather than silence, because the socket parks the caller until it is answered.
     *
     * @param CommandRequestDTO $data Command request payload
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused)
     * @throws InvalidArgumentException When the reply carries an empty correlation id
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        if ($data->command === CliCommands::LEGAL_TEST_HOLD) {
            $this->handleLegalHoldCommand($data);

            return;
        }

        if ($data->command === CliCommands::VERIFICATION_TEST_SWEEP) {
            $this->handleVerificationSweepCommand($data);

            return;
        }

        $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));
    }

    /**
     * Runs {@see CliCommands::LEGAL_TEST_HOLD} and answers the parked socket exactly once.
     *
     * @param CommandRequestDTO $data Command request carrying user id, document and revision id
     * @throws InvalidArgumentException When the reply carries an empty correlation id
     */
    private function handleLegalHoldCommand(CommandRequestDTO $data): void
    {
        $rawUserId = $data->payload[LegalHoldCommandConstants::FIELD_USER_ID] ?? null;
        $userId = is_int($rawUserId) ? $rawUserId : (is_numeric($rawUserId) ? (int)$rawUserId : 0);
        $documentKey = $data->payload[LegalHoldCommandConstants::FIELD_DOCUMENT] ?? null;
        $revisionId = $data->payload[LegalHoldCommandConstants::FIELD_REVISION_ID] ?? null;

        if (!is_string($documentKey) || !is_string($revisionId)) {
            $this->replyToCommand(CommandReplyDTO::error(
                $data->correlationId,
                'Legal hold requires document and revision id as strings',
            ));

            return;
        }

        try {
            $standing = $this->legalAcceptanceCommands()->hold($userId, $documentKey, $revisionId);
            $frozen = AccountStandingResolver::isFrozen($userId);
        } catch (Throwable $e) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $e->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            LegalHoldCommandConstants::FIELD_USER_ID => $userId,
            LegalHoldCommandConstants::FIELD_DOCUMENT => $standing->document->value,
            LegalHoldCommandConstants::FIELD_REVISION_ID => $revisionId,
            LegalHoldCommandConstants::FIELD_STANDING => $standing->standing->value,
            LegalHoldCommandConstants::FIELD_DEADLINE => $standing->deadline,
            LegalHoldCommandConstants::FIELD_FROZEN => $frozen,
        ]));
    }

    /**
     * Ages one address and sweeps once for the test-only command.
     *
     * @param CommandRequestDTO $data Command request carrying a non-empty identifier
     * @throws InvalidArgumentException When the reply carries an empty correlation id
     */
    private function handleVerificationSweepCommand(CommandRequestDTO $data): void
    {
        $identifier = $data->payload[VerificationSweepCommandConstants::FIELD_IDENTIFIER] ?? null;
        if (!is_string($identifier) || trim($identifier) === '') {
            $this->replyToCommand(CommandReplyDTO::error(
                $data->correlationId,
                'Verification sweep requires a non-empty identifier',
            ));

            return;
        }

        try {
            $sweeper = new VerificationSweeper();
            $sweeper->age($identifier);
            $removed = $sweeper->sweep();
            $kept = $sweeper->countFor($identifier);
        } catch (Throwable $e) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $e->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            VerificationSweepCommandConstants::FIELD_IDENTIFIER => $identifier,
            VerificationSweepCommandConstants::FIELD_REMOVED => $removed,
            VerificationSweepCommandConstants::FIELD_KEPT => $kept,
        ]));
    }

    /**
     * @param OAuthLoginReadySignalData $ready Provider login to complete or report as failed
     * @throws InvalidArgumentException When the failure frame cannot be named or queued
     */
    private function handleOAuthLoginReady(OAuthLoginReadySignalData $ready): void
    {
        try {
            $this->oauthCommands()->completeLogin($ready);
        } catch (WiringRefusal $refusal) {
            // Told apart from an ordinary failure: nothing about this login is wrong, the process
            // is not wired to what it reads, and every login will fail the same way until it is.
            // The tab is still answered - it cannot be raised to.
            $this->logAgentError("OAuth login completion is not wired in this process: {$refusal->getMessage()}");
            $this->reportOAuthLoginFailed($ready);
        } catch (Throwable $e) {
            $this->logAgentError("OAuth login completion failed for {$ready->acceptKey}: " . $e->getMessage());
            $this->reportOAuthLoginFailed($ready);
        }
    }

    /**
     * Tells the session holder a provider sign-in ended in a failure here (HIL-1044).
     *
     * @param OAuthLoginReadySignalData $ready The answer whose completion failed
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    private function reportOAuthLoginFailed(OAuthLoginReadySignalData $ready): void
    {
        $this->sendToAgent(
            HilosSignalConstants::HILOS_OAUTH_TRIP_ENDED,
            new OAuthTripEndedSignalData(
                $ready->tripKeyHash,
                $ready->acceptKey,
                $ready->provider,
                OAuthResultSignalData::REASON_LOGIN_FAILED,
            ),
        );
    }

    /**
     * Returns the methods this project is willing to offer for an identifier.
     *
     * Public because the command groups are separate classes and PHP has no visibility
     * between a class and its own collaborators; the same note covers everything below
     * that a group calls. Nothing outside {@see AbstractLibraryCommands} is meant to.
     *
     * Built for every lookup and not once on start: the set is what an administrator switched
     * on (HIL-427), read from settings every process holds locally, so a detector held from
     * the start would go on naming the methods this process started with until the daemon
     * restarted.
     *
     * @return IdentifierDetector Detector over the installation's enabled method keys, as set now
     * @throws DatabaseException When the stored method setting cannot be read
     * @throws SettingException When the method setting's catalog entry or stored value is invalid
     */
    public function authMethods(): IdentifierDetector
    {
        return new IdentifierDetector(EnabledAuthMethods::keys());
    }

    /**
     * Returns the project's OAuth wiring, or null when it signs nobody in through a provider.
     *
     * @return ?OAuthService Service the project's providers are configured on, or null when it has none
     * @throws HilosException When the provider registry cannot be built
     */
    public function oauthService(): ?OAuthService
    {
        return $this->buildOAuthService();
    }

    /** @return LegalAcceptanceCommands Shared write entry for registration and re-consent */
    public function legalAcceptanceCommands(): LegalAcceptanceCommands
    {
        return new LegalAcceptanceCommands($this);
    }

    /**
     * Refuses an administrator's deletion of an account that is missing, is an administrator, or
     * was merged into another one.
     *
     * What the framework checks on its own tables, in that order: the `hilos_user` row, then the
     * merge table (HIL-1199) - a folded account's deletion is the account it became's to ask for.
     * A project with a refusal of its own overrides this and calls the parent first.
     *
     * @param int $userId Account an administrator wants to schedule for deletion
     * @throws ItemNotFoundForUpdateException When the account does not exist
     * @throws ValidationException When the account is an administrator, or was merged into another one
     * @throws HilosException When the account cannot be read
     */
    protected function assertAdministratorMayDelete(int $userId): void
    {
        $user = Hilos::$db->users[$userId] ?? null;
        if ($user === null) {
            throw new ItemNotFoundForUpdateException("No such user: {$userId}");
        }
        if ($user->admin === true) {
            throw new ValidationException('Remove the admin rights first');
        }
        if (Hilos::$db->userMerges[$userId] !== null) {
            throw new ValidationException(AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE);
        }
    }

    /**
     * Requires a live confirmation before a project-owned protected action runs.
     *
     * @param string $acceptKey Accept key of the connection that submitted
     * @param string $operation Protected operation key
     * @throws ValidationException When impersonation is active or confirmation is absent or expired
     * @throws HilosException When the acting session, settings, proofs, or confirmation cannot be read
     */
    protected function requireStepUp(string $acceptKey, string $operation): void
    {
        $this->stepUpCommands()->require($acceptKey, $operation);
    }

    /**
     * Asks the session holder to sign one session in, and hands it the answer to give.
     *
     * The ending of every ceremony that proves who somebody is against an account that
     * already exists: a password, a phone code on a known number, a clicked link on a
     * taken address, a passkey. The library got as far as "this is user N" and stops
     * there - the session, its rotated token and its live sockets belong to the holder.
     *
     * The answer goes WITH the request rather than out from here (HIL-622). Both halves
     * arrive at the browser as frames, and the surface closes on the identity coming up;
     * an "you are in" sent from this side would race the currentUser it announces, and on
     * a loaded box would win about as often as not.
     *
     * @param ActingSession $acting Browser that proved the credential
     * @param int $userId Account it proved itself to be
     * @param ?string $ack Mark to show on the session's sockets (a {@see SessionAck} value), or null for none
     * @param ?AuthFlowOutcome $outcome Where the surface goes next, answered by the holder
     * @param ?string $tripKeyHash Hash of the key of the provider sign-in this grant ends, or null for every other
     *     ceremony (HIL-1044)
     * @param bool $secondFactorProven Whether the second factor was just shown, so the holder does not ask for it
     *     again (HIL-494); every first proof leaves it false and meets the holder's second-factor gate
     * @param bool $trustDevice Whether the person asked not to be asked again on this browser (HIL-494)
     * @param ?string $provenBy Step-up method just proved, or null when it cannot credit an operation
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    public function grantSession(
        ActingSession $acting,
        int $userId,
        ?string $ack = null,
        ?AuthFlowOutcome $outcome = null,
        ?string $tripKeyHash = null,
        bool $secondFactorProven = false,
        bool $trustDevice = false,
        ?string $provenBy = null,
    ): void {
        $this->handOff(
            HilosSignalConstants::HILOS_AUTH_SESSION_GRANT,
            new AuthSessionGrantSignalData(
                $acting->sessionToken,
                $userId,
                $acting->acceptKey,
                $this->currentActionRequestId(),
                $this->currentAction,
                $outcome?->toArray(),
                $ack,
                $tripKeyHash,
                $secondFactorProven,
                $trustDevice,
                $provenBy,
            ),
        );
    }

    /**
     * Tells the session holder a registration has landed, naming the winner and the losers.
     *
     * One frame for the whole ending because the holder does it in one order and the
     * order is the mechanism: mark the sockets, raise the winner's session, drop its wait,
     * then tell the browsers that were racing the same identifier that it is taken.
     *
     * @param ActingSession $acting Browser whose proof created the account
     * @param string $identifier Normalized identifier that was confirmed
     * @param int $userId Account the confirmation created
     * @param list<string> $losingSessionTokens Sessions whose hold on the identifier is dropped
     * @param ?AuthFlowOutcome $outcome Where the winner's surface goes next, answered by the holder
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    public function announceRegistrationLanded(
        ActingSession $acting,
        string $identifier,
        int $userId,
        array $losingSessionTokens,
        ?AuthFlowOutcome $outcome = null,
    ): void {
        $this->handOff(
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_LANDED,
            new AuthRegistrationLandedSignalData(
                $identifier,
                $userId,
                $acting->sessionToken,
                $acting->acceptKey,
                $losingSessionTokens,
                $this->currentActionRequestId(),
                $this->currentAction,
                $outcome?->toArray(),
            ),
        );
    }

    /**
     * Tells the session holder that one session may now set a password for an address.
     *
     * The grant is a fact about the BROWSER, not about the socket that proved the code,
     * so the holder is the one that can act on it: it moves the session's other tabs onto
     * the password step, and nobody in them submitted anything to be answered.
     *
     * @param ActingSession $acting Browser that proved the recovery code
     * @param string $identifier Normalized address being recovered
     * @param ?AuthFlowOutcome $outcome Where the submitting surface goes next, answered by the holder
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    public function announceRecoveryGranted(
        ActingSession $acting,
        string $identifier,
        ?AuthFlowOutcome $outcome = null,
    ): void {
        $this->handOff(
            HilosSignalConstants::HILOS_AUTH_RECOVERY_GRANTED,
            new AuthRecoveryGrantedSignalData(
                $identifier,
                $acting->sessionToken,
                $acting->acceptKey,
                [],
                $this->currentActionRequestId(),
                $this->currentAction,
                $outcome?->toArray(),
            ),
        );
    }

    /**
     * Tells the session holder that one browser proved the address it is registering.
     *
     * The registration counterpart of {@see announceRecoveryGranted()} (HIL-825), and it
     * carries no account because there is none yet: the proof is a fact about the BROWSER,
     * so the holder is the one that can act on it. It moves the session's other tabs onto
     * the password step and answers the submitting one last.
     *
     * @param ActingSession $acting Browser that proved the registration code
     * @param string $identifier Normalized address that was proved
     * @param ?AuthFlowOutcome $outcome Where the submitting surface goes next, answered by the holder
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    public function announceRegistrationProven(
        ActingSession $acting,
        string $identifier,
        ?AuthFlowOutcome $outcome = null,
    ): void {
        $this->handOff(
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_PROVEN,
            new AuthRegistrationProvenSignalData(
                $identifier,
                $acting->sessionToken,
                $acting->acceptKey,
                [],
                $this->currentActionRequestId(),
                $this->currentAction,
                $outcome?->toArray(),
            ),
        );
    }

    /**
     * Tells the session holder a recovery finished: the secret is written, take the account back.
     *
     * What the holder then does is the point of resetting a password at all - it signs
     * this browser in, settles the tabs that were waiting on the address, and logs every
     * OTHER session of the account out. A reset happens when access has leaked, so it
     * ends with one live session and not with one more.
     *
     * @param ActingSession $acting Browser that saved the new password
     * @param int $userId Account whose password was saved
     * @param string $identifier Normalized address whose recovery this was
     * @param ?AuthFlowOutcome $outcome Where the surface goes next, answered by the holder
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    public function announcePasswordChanged(
        ActingSession $acting,
        int $userId,
        string $identifier,
        ?AuthFlowOutcome $outcome = null,
    ): void {
        $this->handOff(
            HilosSignalConstants::HILOS_AUTH_PASSWORD_CHANGED,
            new AuthPasswordChangedSignalData(
                $userId,
                $acting->sessionToken,
                $acting->acceptKey,
                $identifier,
                $this->currentActionRequestId(),
                $this->currentAction,
                $outcome?->toArray(),
            ),
        );
    }

    /**
     * Tells the session holder one browser canceled the registration it was on.
     *
     * The holder forgets the wait - the parked sockets and the durable memory alike - so
     * this session's tabs go back to the identifier field together. The hold on the address
     * is already gone by then, and dropping it is this library's own move rather than the
     * holder's: the reservations are declared here and written nowhere else (HIL-829).
     * What a person who leaves in SILENCE keeps is that same hold, which is why walking
     * away sends no frame at all and this one means "it was pressed".
     *
     * @param ActingSession $acting Browser canceling its registration
     * @param ?AuthFlowOutcome $outcome Where the surface goes next, answered by the holder
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    public function announceRegistrationCanceled(
        ActingSession $acting,
        ?AuthFlowOutcome $outcome = null,
    ): void {
        $this->handOff(
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_CANCELED,
            new AuthRegistrationCanceledSignalData(
                $acting->sessionToken,
                $acting->acceptKey,
                $this->currentActionRequestId(),
                $this->currentAction,
                $outcome?->toArray(),
            ),
        );
    }

    /**
     * Tells the session holder which address one browser is now waiting to confirm.
     *
     * Sent beside every park, and it is not a hand-off: the browser that submitted is
     * answered by the library itself, here and now, because the answer never stood on the
     * parked row. What the row is for is the OTHER tabs of that session, which a converge
     * reaches through it, and those are the holder's.
     *
     * It exists for the one case parking cannot serve alone (HIL-685): the row is already
     * there and points at another address. Editing this collection belongs to its one full
     * truth source, so the library adds what is missing and says the rest in this frame.
     *
     * @param ActingSession $acting Browser being parked
     * @param string $identifier Normalized identifier it waits on now
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    public function announceRegistrationWaitMoved(ActingSession $acting, string $identifier): void
    {
        $this->sendToAgent(
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_MOVED,
            new AuthRegistrationWaitMovedSignalData($acting->acceptKey, $identifier, $acting->sessionToken),
        );
    }

    /**
     * Tells the session holder which address one browser is now recovering.
     *
     * The recovery twin of {@see announceRegistrationWaitMoved()}, and it carries one
     * consequence more: re-pointing a recovery waiter drops the grant on it, so a second
     * code asked for from the same tab cannot open the password step of the address the
     * person just left. Not a hand-off either - the library answers the send itself.
     *
     * @param ActingSession $acting Browser being parked
     * @param string $identifier Normalized address it recovers now
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    public function announceRecoveryWaitMoved(ActingSession $acting, string $identifier): void
    {
        $this->sendToAgent(
            HilosSignalConstants::HILOS_AUTH_RECOVERY_WAIT_MOVED,
            new AuthRecoveryWaitMovedSignalData($acting->acceptKey, $identifier, $acting->sessionToken),
        );
    }

    /**
     * Asks the session holder to count a wrong second-factor code against this browser's wait (HIL-494).
     *
     * Not a hand-off: the submit is answered here, with the refusal the command throws after
     * this frame is queued.
     *
     * @param ActingSession $acting Browser that sent the code
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    public function announceSecondFactorMissed(ActingSession $acting): void
    {
        $this->sendToAgent(
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_MISSED,
            new AuthSecondFactorMissedSignalData($acting->sessionToken),
        );
    }

    /**
     * Tells the session holder the enrolment on the way in is confirmed (HIL-494).
     *
     * @param ActingSession $acting Browser that enrolled
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    public function announceSecondFactorSetupProven(ActingSession $acting): void
    {
        $this->sendToAgent(
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_SETUP_PROVEN,
            new AuthSecondFactorSetupProvenSignalData($acting->sessionToken),
        );
    }

    /**
     * Tells the session holder a person's second factor is gone (HIL-494).
     *
     * @param int $userId Person whose factor is gone
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    public function announceSecondFactorOff(int $userId): void
    {
        $this->sendToAgent(HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_OFF, new AuthSecondFactorOffSignalData($userId));
    }

    /**
     * Fans the password-updated signal to every socket the person has open.
     *
     * Every tab, not only the one that saved: a change moves nothing the other tabs can see,
     * and any of them may show the confirmation.
     *
     * @param int $userId Account whose secret changed
     * @param string $mode Whether the password was added or changed, a {@see ProfilePasswordUpdatedSignalData} mode
     * @throws InvalidArgumentException When the signal cannot be named or queued
     */
    public function announcePasswordUpdated(int $userId, string $mode): void
    {
        foreach (Hilos::$rt?->sessionConnectionsSource()?->findByUser($userId) ?? [] as $connection) {
            $this->sendToUser(
                HilosSignalConstants::PROFILE_PASSWORD_UPDATED,
                $connection->acceptKey,
                new ProfilePasswordUpdatedSignalData($mode),
            );
        }
    }

    /**
     * Asks the session holder to end the person's other ordinary sessions (HIL-300).
     * The holder processes this frame independently of the answer to the password action: that
     * answer is the dispatcher's, or - when a code proved the change - the holder's own, handed
     * over by the frame that ends the flow and so sent after this one (HIL-1182).
     *
     * @param ActingSession $acting Person and acting session to preserve
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    public function announceOtherSessionsEnd(ActingSession $acting): void
    {
        $this->sendToAgent(
            HilosSignalConstants::HILOS_AUTH_OTHER_SESSIONS_END,
            new AuthOtherSessionsEndSignalData($acting->userId, $acting->sessionToken),
        );
    }

    /**
     * Tells the session holder how far a profile window of this browser has got, and hands it the
     * answer (HIL-1182).
     *
     * The step is a fact about the SESSION, not about the tab that submitted it: every tab of the
     * browser opens the window on it, and a reloaded one does too. So the holder writes it, tells
     * those tabs, and answers this one last - the counterpart of {@see announceRecoveryGranted()}
     * for a person who is already signed in. A null step ends the flow and takes the record away.
     *
     * @param ActingSession $acting Browser and person the step was run for
     * @param string $operation Operation key of the window
     * @param ?string $step Step reached ({@see HilosProfileFlow}'s STEP_* constants), or null when the flow is over
     * @param ?string $address The account's address the proof stands on, or null when the flow is over
     * @param ?string $target New address of an email change, on its last step alone
     * @param ?int $expiresAt Epoch milliseconds the code of the proof dies at, or null when the flow is over
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    public function announceProfileFlowStep(
        ActingSession $acting,
        string $operation,
        ?string $step,
        ?string $address = null,
        ?string $target = null,
        ?int $expiresAt = null,
    ): void {
        $this->handOff(
            HilosSignalConstants::HILOS_PROFILE_FLOW_STEP,
            new ProfileFlowStepSignalData(
                $acting->sessionToken,
                $acting->userId,
                $operation,
                $step,
                $address,
                $target,
                $expiresAt,
                $acting->acceptKey,
                $this->currentActionRequestId(),
                $this->currentAction,
            ),
        );
    }

    /**
     * Asks the session holder to let this browser's second-factor wait go, and hands it the answer (HIL-494).
     *
     * @param ActingSession $acting Browser whose wait ends
     * @param ?AuthFlowOutcome $outcome Answer to the submit, or null for the address field
     * @param ?string $code Why the other tabs go back (an AuthFlowOutcome::CODE_* value), or null
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    public function announceSecondFactorCanceled(
        ActingSession $acting,
        ?AuthFlowOutcome $outcome = null,
        ?string $code = null,
    ): void {
        $this->handOff(
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_CANCEL,
            new AuthSecondFactorCancelSignalData(
                $acting->sessionToken,
                $acting->acceptKey,
                $this->currentActionRequestId(),
                $this->currentAction,
                $outcome?->toArray(),
                $code,
            ),
        );
    }

    /**
     * Creates one person row in the framework's `hilos_user` table.
     *
     * Called inside the landing transaction, and {@see afterUserCreated()} after it commits:
     * the row and the identity that makes it reachable stand or fall together, while what a
     * project writes ABOUT a new member is news and must not be rolled back into existence.
     *
     * Not final: the framework's test stands replace it with a table of their own to look inside
     * the landing transaction. A project has nothing to override here - the columns a subclass of
     * the person adds must be insertable ({@see EntitySchemaAxis::COLUMN_NOT_INSERTABLE}).
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
     * Names one account by the `hilos_user.name` it carries.
     *
     * Asked for by the passkey enrollment, whose options carry a name the OS picker draws
     * beside the key - a person with two accounts sees only this to tell them apart.
     *
     * Null when there is nothing to show - a deleted row, or an account never named - and the
     * caller then draws its own placeholder rather than an empty label. Not final, for the
     * reason {@see createUser()} gives.
     *
     * @param int $userId Account to name
     * @return ?string Name to show, or null when there is none for this account
     * @throws HilosException When the lookup fails
     */
    public function displayNameOf(int $userId): ?string
    {
        $name = Hilos::$db->users[$userId]?->name;

        return $name === null || $name === '' ? null : $name;
    }

    /**
     * Renames one person and records the rename in the framework's journal (HIL-1195).
     *
     * The name and the journal row are one transaction; after it commits the person is told
     * when somebody else renamed them, and {@see afterUserRenamed()} runs. A name that is
     * already the person's writes nothing and answers null. Called for an administrator's
     * rename from the person's card, and by a project that renames a person on its own terms -
     * the chat demo's moderated rename of oneself (HIL-1196).
     *
     * Not final, for the reason {@see createUser()} gives.
     *
     * @param int $userId Person to rename
     * @param string $newName Name to give; trimmed and held to the frame of the person's name
     * @param ?int $renamedByUserId Person who did the rename - the renamed person's own id when they renamed
     *     themselves - or null when the author is not a person
     * @return ?UserRename Journal row of this rename, or null when the name was already the person's
     * @throws ItemNotFoundForUpdateException When there is no such person
     * @throws ValidationException When the name is empty, too short or too long
     * @throws HilosException When the name, the journal row or the transaction cannot be written
     */
    public function renameUser(int $userId, string $newName, ?int $renamedByUserId): ?UserRename
    {
        return $this->userRenameCommands()->rename($userId, $newName, $renamedByUserId);
    }

    /**
     * Runs whatever else the project does when a person is renamed.
     *
     * Called after the rename committed, with its journal row. Default does nothing: the chat
     * demo writes the rename into its feed here (HIL-1196), and a project without a feed has
     * nothing to write. What a project writes ABOUT a rename is news - its failure is logged and
     * does not undo the rename, which the administrator is then told succeeded.
     *
     * @param UserRename $rename Journal row of the rename just committed
     * @throws HilosException When the project's own bookkeeping fails
     */
    public function afterUserRenamed(UserRename $rename): void
    {
    }

    /**
     * Runs whatever else the project does when an account is born.
     *
     * Default does nothing, because most projects do nothing: the chat demo writes its
     * registration event here, and a project without an event log has nothing to write.
     *
     * @param int $userId User that was just created
     * @param string $identifier Normalized identifier the account was created for
     * @throws HilosException When the project's own bookkeeping fails
     */
    public function afterUserCreated(int $userId, string $identifier): void
    {
    }

    /**
     * Builds the OAuth service the provider commands run on.
     *
     * Default is null: signing in through a provider is optional, and a project that
     * offers none has no client pair to configure a service with. The provider commands
     * then refuse rather than half-work.
     *
     * The same service the project hands its {@see AbstractOAuthAgent} - the state signer
     * and the link signer both live on it, and a callback verified against one signer and
     * exchanged under another would fail every login.
     *
     * @return ?OAuthService Configured service, or null when the project enables no providers
     * @throws HilosException When the provider registry cannot be built
     */
    protected function buildOAuthService(): ?OAuthService
    {
        return null;
    }


    /**
     * Runs the group that owns one action name and returns what it answered.
     *
     * Split from {@see onAgentAction()} so the name of the running dispatch is set and
     * cleared in one place: a frame built by a command reads that name to say which action
     * it is handing an answer for, and a name left standing after the dispatch would be
     * read by the next frame this library sends outside one - an OAuth login finishing
     * minutes later, on no action at all.
     *
     * @param string $acceptKey Accept key of the connection that submitted
     * @param string $action Owned action name from {@see AGENT_ACTIONS}
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO What the surface is told, or null when the holder answers instead
     * @throws AgentUnknownActionException When the action is not one this library owns
     * @throws InvalidActionPayloadException When the payload does not match the action name
     * @throws ValidationException When the action's sign-in method is switched off, or the command refuses what was submitted
     * @throws RandomException When issuing a verification code cannot draw from the CSPRNG
     * @throws HilosException When a command exposes database, runtime, or settings failure
     */
    private function runOwnedAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        // Before anything else, a flow begun before the switch included: a method switched
        // off is closed on the server, not only hidden on the surface (HIL-427).
        AuthMethodGate::assertActionOpen($action);

        switch ($action) {
            case HilosSignalConstants::HILOS_DETECT_IDENTIFIER:
                if (!$dto instanceof DetectIdentifierActionDTO) {
                    throw new InvalidActionPayloadException($action, DetectIdentifierActionDTO::class, $dto);
                }

                return $this->detectionCommands()->detectIdentifier($acceptKey, $dto);

            case HilosSignalConstants::HILOS_LEGAL_CONSENT:
                return new LegalConsentReplyDTO(LegalSettings::consentForm(), LegalConsentProjector::documents());

            case HilosSignalConstants::HILOS_LEGAL_RECONSENT:
                return $this->legalAcceptanceCommands()->reconsent($acceptKey);

            case HilosSignalConstants::HILOS_LEGAL_ACCEPT:
                if (!$dto instanceof LegalAcceptActionDTO) {
                    throw new InvalidActionPayloadException($action, LegalAcceptActionDTO::class, $dto);
                }
                $this->legalAcceptanceCommands()->acceptCurrent($acceptKey, $dto->acceptedRevisions);

                return null;

            case HilosSignalConstants::HILOS_LEGAL_RECONSENT_PREVIEW:
                if (!$dto instanceof LegalReconsentPreviewActionDTO) {
                    throw new InvalidActionPayloadException($action, LegalReconsentPreviewActionDTO::class, $dto);
                }
                $document = LegalDocument::tryFrom($dto->document);
                if ($document === null) {
                    throw new ValidationException('No such legal document');
                }

                return new LegalReconsentPreviewReplyDTO(...LegalReconsentProjector::preview($document, LegalStandingResolver::today()));

            case HilosSignalConstants::HILOS_LOGIN:
                if (!$dto instanceof LoginActionDTO) {
                    throw new InvalidActionPayloadException($action, LoginActionDTO::class, $dto);
                }
                $this->passwordCommands()->login($acceptKey, $dto);

                return null;

            case HilosSignalConstants::HILOS_REGISTER:
                if (!$dto instanceof RegisterActionDTO) {
                    throw new InvalidActionPayloadException($action, RegisterActionDTO::class, $dto);
                }

                return $this->passwordCommands()->register($acceptKey, $dto);

            case HilosSignalConstants::HILOS_REQUEST_PASSWORD_RESET:
                if (!$dto instanceof RequestPasswordResetActionDTO) {
                    throw new InvalidActionPayloadException($action, RequestPasswordResetActionDTO::class, $dto);
                }

                return $this->recoveryCommands()->requestPasswordReset($acceptKey, $dto);

            case HilosSignalConstants::HILOS_CONFIRM_PASSWORD_RESET:
                if (!$dto instanceof ConfirmPasswordResetActionDTO) {
                    throw new InvalidActionPayloadException($action, ConfirmPasswordResetActionDTO::class, $dto);
                }

                return $this->recoveryCommands()->confirmPasswordReset($acceptKey, $dto);

            case HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET:
                if (!$dto instanceof CompletePasswordResetActionDTO) {
                    throw new InvalidActionPayloadException($action, CompletePasswordResetActionDTO::class, $dto);
                }

                return $this->recoveryCommands()->completePasswordReset($acceptKey, $dto);

            case HilosSignalConstants::HILOS_REQUEST_REGISTER_CONFIRM:
                if (!$dto instanceof RequestRegisterConfirmActionDTO) {
                    throw new InvalidActionPayloadException($action, RequestRegisterConfirmActionDTO::class, $dto);
                }

                return $this->passwordCommands()->requestRegisterConfirm($acceptKey, $dto);

            case HilosSignalConstants::HILOS_CONFIRM_REGISTER:
                if (!$dto instanceof ConfirmRegisterActionDTO) {
                    throw new InvalidActionPayloadException($action, ConfirmRegisterActionDTO::class, $dto);
                }

                return $this->passwordCommands()->confirmRegister($acceptKey, $dto);

            case HilosSignalConstants::HILOS_COMPLETE_REGISTRATION:
                if (!$dto instanceof CompleteRegistrationActionDTO) {
                    throw new InvalidActionPayloadException($action, CompleteRegistrationActionDTO::class, $dto);
                }

                return $this->passwordCommands()->completeRegistration($acceptKey, $dto);

            case HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSWORDLESS:
                if (!$dto instanceof CompleteRegistrationPasswordlessActionDTO) {
                    throw new InvalidActionPayloadException(
                        $action,
                        CompleteRegistrationPasswordlessActionDTO::class,
                        $dto,
                    );
                }

                return $this->passwordCommands()->completeRegistrationPasswordless($acceptKey, $dto);

            case HilosSignalConstants::HILOS_REGISTRATION_PASSKEY_OPTIONS:
                if (!$dto instanceof RegistrationPasskeyOptionsActionDTO) {
                    throw new InvalidActionPayloadException($action, RegistrationPasskeyOptionsActionDTO::class, $dto);
                }

                return $this->passkeyCommands()->registrationOptions($acceptKey, $dto);

            case HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSKEY:
                if (!$dto instanceof CompleteRegistrationPasskeyActionDTO) {
                    throw new InvalidActionPayloadException($action, CompleteRegistrationPasskeyActionDTO::class, $dto);
                }

                return $this->passkeyCommands()->completeRegistration($acceptKey, $dto);

            case HilosSignalConstants::HILOS_CANCEL_REGISTRATION:
                if (!$dto instanceof CancelRegistrationActionDTO) {
                    throw new InvalidActionPayloadException($action, CancelRegistrationActionDTO::class, $dto);
                }
                $this->passwordCommands()->cancelRegistration($acceptKey, $dto);

                return null;

            case HilosSignalConstants::HILOS_REQUEST_PHONE_CODE:
                if (!$dto instanceof RequestPhoneCodeActionDTO) {
                    throw new InvalidActionPayloadException($action, RequestPhoneCodeActionDTO::class, $dto);
                }
                return $this->phoneCodeCommands()->requestPhoneCode($acceptKey, $dto);

            case HilosSignalConstants::HILOS_CONFIRM_PHONE_CODE:
                if (!$dto instanceof ConfirmPhoneCodeActionDTO) {
                    throw new InvalidActionPayloadException($action, ConfirmPhoneCodeActionDTO::class, $dto);
                }

                return $this->phoneCodeCommands()->confirmPhoneCode($acceptKey, $dto);

            case HilosSignalConstants::HILOS_REQUEST_MAGIC_LINK:
                if (!$dto instanceof RequestMagicLinkActionDTO) {
                    throw new InvalidActionPayloadException($action, RequestMagicLinkActionDTO::class, $dto);
                }

                return $this->magicLinkCommands()->requestMagicLink($acceptKey, $dto);

            case HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK:
                if (!$dto instanceof ConfirmMagicLinkActionDTO) {
                    throw new InvalidActionPayloadException($action, ConfirmMagicLinkActionDTO::class, $dto);
                }

                return $this->magicLinkCommands()->confirmMagicLink($acceptKey, $dto);

            case HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK_CODE:
                if (!$dto instanceof ConfirmMagicLinkCodeActionDTO) {
                    throw new InvalidActionPayloadException($action, ConfirmMagicLinkCodeActionDTO::class, $dto);
                }

                return $this->magicLinkCommands()->confirmMagicLinkCode($acceptKey, $dto);

            case HilosSignalConstants::HILOS_OAUTH_START:
                if (!$dto instanceof OAuthStartActionDTO) {
                    throw new InvalidActionPayloadException($action, OAuthStartActionDTO::class, $dto);
                }
                $this->oauthCommands()->startOAuth($acceptKey, $dto);

                return null;

            case HilosSignalConstants::HILOS_OAUTH_CALLBACK:
                if (!$dto instanceof OAuthCallbackActionDTO) {
                    throw new InvalidActionPayloadException($action, OAuthCallbackActionDTO::class, $dto);
                }
                $this->oauthCommands()->callbackOAuth($acceptKey, $dto);

                return null;

            case HilosSignalConstants::HILOS_LINK_OAUTH_AFTER_REAUTH:
                if (!$dto instanceof LinkOAuthAfterReauthActionDTO) {
                    throw new InvalidActionPayloadException($action, LinkOAuthAfterReauthActionDTO::class, $dto);
                }
                $this->oauthCommands()->linkAfterReauth($acceptKey, $dto);

                return null;

            case HilosSignalConstants::HILOS_OAUTH_CREATE_ACCOUNT:
                if (!$dto instanceof OAuthCreateAccountActionDTO) {
                    throw new InvalidActionPayloadException($action, OAuthCreateAccountActionDTO::class, $dto);
                }

                return $this->oauthCommands()->createAccount($acceptKey, $dto);

            case HilosSignalConstants::HILOS_PASSKEY_REGISTER_OPTIONS:
                if (!$dto instanceof PasskeyRegisterOptionsActionDTO) {
                    throw new InvalidActionPayloadException($action, PasskeyRegisterOptionsActionDTO::class, $dto);
                }
                $this->passkeyCommands()->registerOptions($acceptKey, $dto);

                return null;

            case HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM:
                if (!$dto instanceof PasskeyRegisterConfirmActionDTO) {
                    throw new InvalidActionPayloadException($action, PasskeyRegisterConfirmActionDTO::class, $dto);
                }
                $this->passkeyCommands()->registerConfirm($acceptKey, $dto);

                return null;

            case HilosSignalConstants::HILOS_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS:
                if (!$dto instanceof PasskeyDiscoverableLoginOptionsActionDTO) {
                    throw new InvalidActionPayloadException(
                        $action,
                        PasskeyDiscoverableLoginOptionsActionDTO::class,
                        $dto,
                    );
                }
                $this->passkeyCommands()->discoverableLoginOptions($acceptKey, $dto);

                return null;

            case HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM:
                if (!$dto instanceof PasskeyLoginConfirmActionDTO) {
                    throw new InvalidActionPayloadException($action, PasskeyLoginConfirmActionDTO::class, $dto);
                }
                $this->passkeyCommands()->loginConfirm($acceptKey, $dto);

                return null;

            default:
                return $this->runStepUpAction($acceptKey, $action, $dto);
        }
    }

    /**
     * Runs operation-level confirmation actions, then delegates the other names to the profile's.
     *
     * @param string $acceptKey Accept key of the connection that submitted
     * @param string $action Owned action name from {@see AGENT_ACTIONS}
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Opening reply, or null after confirmation
     * @throws AgentUnknownActionException When the action is not one this library owns
     * @throws InvalidActionPayloadException When the payload does not match the action name
     * @throws ValidationException When the command refuses what was submitted
     * @throws RandomException When a code, challenge, secret, backup code, or token cannot be drawn
     * @throws HilosException When a command exposes database, runtime, env, or settings failure
     */
    private function runStepUpAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_STEP_UP_START:
                if (!$dto instanceof StepUpStartActionDTO) {
                    throw new InvalidActionPayloadException($action, StepUpStartActionDTO::class, $dto);
                }

                return $this->stepUpCommands()->start($acceptKey, $dto);

            case HilosSignalConstants::HILOS_STEP_UP_CONFIRM:
                if (!$dto instanceof StepUpConfirmActionDTO) {
                    throw new InvalidActionPayloadException($action, StepUpConfirmActionDTO::class, $dto);
                }
                $this->stepUpCommands()->confirm($acceptKey, $dto);

                return null;

            default:
                return $this->runProfileAction($acceptKey, $action, $dto);
        }
    }

    /**
     * Runs one of the profile's sign-in-method or email-change submits, or hands the name on (HIL-1137).
     *
     * Opening a password change answers with its code destination. Writes announce identity
     * changes through the projection and password changes through the person's own sockets.
     *
     * @param string $acceptKey Accept key of the connection that submitted
     * @param string $action Owned action name from {@see AGENT_ACTIONS}
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Opening answer for password change, null for writes, or a delegated command reply
     * @throws AgentUnknownActionException When the action is not one this library owns
     * @throws InvalidActionPayloadException When the payload does not match the action name
     * @throws ValidationException When the command refuses what was submitted
     * @throws RandomException When a code, a secret, a backup code or a token cannot be drawn
     * @throws HilosException When a command exposes database, runtime, env, or settings failure
     */
    private function runProfileAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::PROFILE_PHOTO_SET:
                if (!$dto instanceof ProfilePhotoSetActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfilePhotoSetActionDTO::class, $dto);
                }
                $this->profilePhotoCommands()->set($acceptKey, $dto->clientUploadId);

                return null;

            case HilosSignalConstants::PROFILE_PHOTO_REMOVE:
                if (!$dto instanceof ProfilePhotoRemoveActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfilePhotoRemoveActionDTO::class, $dto);
                }
                $this->profilePhotoCommands()->remove($acceptKey);

                return null;

            case HilosSignalConstants::PROFILE_SET_PASSWORD:
                if (!$dto instanceof ProfileSetPasswordActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileSetPasswordActionDTO::class, $dto);
                }
                $this->identityCommands()->setPassword($acceptKey, $dto);

                return null;

            case HilosSignalConstants::PROFILE_UNLINK_IDENTITY:
                if (!$dto instanceof ProfileUnlinkIdentityActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileUnlinkIdentityActionDTO::class, $dto);
                }
                if (!$dto->isValid()) {
                    throw new ValidationException(AuthMessages::IDENTITY_ID_REQUIRED);
                }
                $this->identityCommands()->unlink($acceptKey, $dto->identityId);

                return null;

            case HilosSignalConstants::PROFILE_ADD_SMS_REQUEST:
                if (!$dto instanceof ProfileAddSmsRequestActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileAddSmsRequestActionDTO::class, $dto);
                }
                $this->identityCommands()->requestSmsAdd($acceptKey, $dto);

                return null;

            case HilosSignalConstants::PROFILE_ADD_SMS_CONFIRM:
                if (!$dto instanceof ProfileAddSmsConfirmActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileAddSmsConfirmActionDTO::class, $dto);
                }
                $this->identityCommands()->confirmSmsAdd($acceptKey, $dto);

                return null;

            case HilosSignalConstants::PROFILE_ADD_PASSWORD_REQUEST:
                if (!$dto instanceof ProfileAddPasswordRequestActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileAddPasswordRequestActionDTO::class, $dto);
                }
                $this->identityCommands()->requestPasswordAdd($acceptKey, $dto);

                return null;

            case HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM:
                if (!$dto instanceof ProfileAddPasswordConfirmActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileAddPasswordConfirmActionDTO::class, $dto);
                }
                $this->identityCommands()->confirmPasswordAdd($acceptKey, $dto);

                return null;

            case HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_REQUEST:
                if (!$dto instanceof ProfileEmailChangeCurrentRequestActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileEmailChangeCurrentRequestActionDTO::class, $dto);
                }
                $this->emailChangeCommands()->requestCurrentCode($acceptKey);

                return null;

            case HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_CONFIRM:
                if (!$dto instanceof ProfileEmailChangeCurrentConfirmActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileEmailChangeCurrentConfirmActionDTO::class, $dto);
                }
                $this->emailChangeCommands()->confirmCurrentCode($acceptKey, $dto);

                return null;

            case HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_REQUEST:
                if (!$dto instanceof ProfileEmailChangeNewRequestActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileEmailChangeNewRequestActionDTO::class, $dto);
                }
                $this->emailChangeCommands()->requestNewCode($acceptKey, $dto);

                return null;

            case HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM:
                if (!$dto instanceof ProfileEmailChangeNewConfirmActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileEmailChangeNewConfirmActionDTO::class, $dto);
                }
                $this->emailChangeCommands()->confirmNewCode($acceptKey, $dto);

                return null;

            case HilosSignalConstants::PROFILE_CHANGE_PASSWORD_OPEN:
                if (!$dto instanceof ProfileChangePasswordOpenActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileChangePasswordOpenActionDTO::class, $dto);
                }
                return $this->passwordChangeCommands()->open($acceptKey);

            case HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_REQUEST:
                if (!$dto instanceof ProfileChangePasswordCodeRequestActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileChangePasswordCodeRequestActionDTO::class, $dto);
                }
                $this->passwordChangeCommands()->requestCode($acceptKey);

                return null;

            case HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_CONFIRM:
                if (!$dto instanceof ProfileChangePasswordCodeConfirmActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileChangePasswordCodeConfirmActionDTO::class, $dto);
                }
                $this->passwordChangeCommands()->confirmCode($acceptKey, $dto);

                return null;

            case HilosSignalConstants::PROFILE_CHANGE_PASSWORD:
                if (!$dto instanceof ProfileChangePasswordActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileChangePasswordActionDTO::class, $dto);
                }
                $this->passwordChangeCommands()->change($acceptKey, $dto);

                return null;

            default:
                return $this->runAccountDeletionAction($acceptKey, $action, $dto);
        }
    }

    /**
     * Runs one of the four submits of a person's own account deletion, or hands the name on (HIL-302).
     *
     * Opening the window answers with a reply; the other three write, and every tab learns of
     * a start or a cancel from the state fanned to the person's group.
     *
     * @param string $acceptKey Accept key of the connection that submitted
     * @param string $action Owned action name from {@see AGENT_ACTIONS}
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO The opening reply, null for the other three, or what the second factor's command answered
     * @throws AgentUnknownActionException When the action is not one this library owns
     * @throws InvalidActionPayloadException When the payload does not match the action name
     * @throws ValidationException When the command refuses what was submitted
     * @throws RandomException When a code, a secret, a backup code or a token cannot be drawn
     * @throws HilosException When a command exposes database, runtime, env, or settings failure
     */
    private function runAccountDeletionAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_ACCOUNT_DELETION_OPEN:
                if (!$dto instanceof AccountDeletionOpenActionDTO) {
                    throw new InvalidActionPayloadException($action, AccountDeletionOpenActionDTO::class, $dto);
                }

                return $this->accountDeletionCommands()->open($acceptKey);

            case HilosSignalConstants::HILOS_ACCOUNT_DELETION_CODE:
                if (!$dto instanceof AccountDeletionCodeActionDTO) {
                    throw new InvalidActionPayloadException($action, AccountDeletionCodeActionDTO::class, $dto);
                }
                $this->accountDeletionCommands()->sendCode($acceptKey);

                return null;

            case HilosSignalConstants::HILOS_ACCOUNT_DELETION_START:
                if (!$dto instanceof AccountDeletionStartActionDTO) {
                    throw new InvalidActionPayloadException($action, AccountDeletionStartActionDTO::class, $dto);
                }
                $this->accountDeletionCommands()->start($acceptKey, $dto);

                return null;

            case HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL:
                if (!$dto instanceof AccountDeletionCancelActionDTO) {
                    throw new InvalidActionPayloadException($action, AccountDeletionCancelActionDTO::class, $dto);
                }
                $this->accountDeletionCommands()->cancel($acceptKey);

                return null;

            default:
                return $this->runSecondFactorAction($acceptKey, $action, $dto);
        }
    }

    /**
     * Runs one of the second factor's sign-in commands (HIL-494).
     *
     * Split off {@see runOwnedAction()} only for its length: the routing is the same - a name,
     * the group that owns it, the reply that group produced, or null when the holder answers.
     *
     * @param string $acceptKey Accept key of the connection that submitted
     * @param string $action Owned action name from {@see AGENT_ACTIONS}
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO What the surface is told, or null when the holder answers instead
     * @throws AgentUnknownActionException When the action is not one this library owns
     * @throws InvalidActionPayloadException When the payload does not match the action name
     * @throws ValidationException When the command refuses what was submitted
     * @throws RandomException When a secret, a backup code or a token cannot be drawn
     * @throws HilosException When a command exposes database, runtime, or settings failure
     */
    private function runSecondFactorAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_CONFIRM_SECOND_FACTOR:
                if (!$dto instanceof ConfirmSecondFactorActionDTO) {
                    throw new InvalidActionPayloadException($action, ConfirmSecondFactorActionDTO::class, $dto);
                }
                $this->secondFactorCommands()->confirm($acceptKey, $dto);

                return null;

            case HilosSignalConstants::HILOS_CANCEL_SECOND_FACTOR:
                if (!$dto instanceof CancelSecondFactorActionDTO) {
                    throw new InvalidActionPayloadException($action, CancelSecondFactorActionDTO::class, $dto);
                }
                $this->secondFactorCommands()->cancel($acceptKey);

                return null;

            case HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_START:
                if (!$dto instanceof SecondFactorSetupStartActionDTO) {
                    throw new InvalidActionPayloadException($action, SecondFactorSetupStartActionDTO::class, $dto);
                }

                return $this->secondFactorCommands()->setupStart($acceptKey);

            case HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_CONFIRM:
                if (!$dto instanceof SecondFactorSetupConfirmActionDTO) {
                    throw new InvalidActionPayloadException($action, SecondFactorSetupConfirmActionDTO::class, $dto);
                }

                return $this->secondFactorCommands()->setupConfirm($acceptKey, $dto);

            case HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_FINISH:
                if (!$dto instanceof SecondFactorSetupFinishActionDTO) {
                    throw new InvalidActionPayloadException($action, SecondFactorSetupFinishActionDTO::class, $dto);
                }
                $this->secondFactorCommands()->setupFinish($acceptKey);

                return null;

            case HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_REQUEST:
                if (!$dto instanceof SecondFactorResetRequestActionDTO) {
                    throw new InvalidActionPayloadException($action, SecondFactorResetRequestActionDTO::class, $dto);
                }
                $this->secondFactorCommands()->resetRequest($acceptKey);

                return null;

            case HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_CANCEL_LINK:
                if (!$dto instanceof SecondFactorResetCancelLinkActionDTO) {
                    throw new InvalidActionPayloadException($action, SecondFactorResetCancelLinkActionDTO::class, $dto);
                }

                return $this->secondFactorCommands()->resetCancelLink($dto);

            case HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START:
                if (!$dto instanceof ProfileSecondFactorEnrollStartActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileSecondFactorEnrollStartActionDTO::class, $dto);
                }

                return $this->secondFactorCommands()->profileEnrollStart($acceptKey, $dto);

            case HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_CONFIRM:
                if (!$dto instanceof ProfileSecondFactorEnrollConfirmActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileSecondFactorEnrollConfirmActionDTO::class, $dto);
                }

                return $this->secondFactorCommands()->profileEnrollConfirm($acceptKey, $dto);

            case HilosSignalConstants::PROFILE_SECOND_FACTOR_REMOVE:
                if (!$dto instanceof ProfileSecondFactorRemoveActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileSecondFactorRemoveActionDTO::class, $dto);
                }
                $this->secondFactorCommands()->profileRemove($acceptKey, $dto);

                return null;

            case HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_SHOW:
                if (!$dto instanceof ProfileSecondFactorCodesShowActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileSecondFactorCodesShowActionDTO::class, $dto);
                }

                return $this->secondFactorCommands()->profileCodesShow($acceptKey, $dto);

            case HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW:
                if (!$dto instanceof ProfileSecondFactorCodesRenewActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileSecondFactorCodesRenewActionDTO::class, $dto);
                }

                return $this->secondFactorCommands()->profileCodesRenew($acceptKey, $dto);

            case HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_WAIT_SET:
                if (!$dto instanceof ProfileSecondFactorResetWaitSetActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileSecondFactorResetWaitSetActionDTO::class, $dto);
                }
                $this->secondFactorCommands()->profileResetWaitSet($acceptKey, $dto);

                return null;

            case HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST:
                if (!$dto instanceof ProfileSecondFactorResetRequestActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileSecondFactorResetRequestActionDTO::class, $dto);
                }
                $this->secondFactorCommands()->profileResetRequest($acceptKey);

                return null;

            case HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_CANCEL:
                if (!$dto instanceof ProfileSecondFactorResetCancelActionDTO) {
                    throw new InvalidActionPayloadException($action, ProfileSecondFactorResetCancelActionDTO::class, $dto);
                }
                $this->secondFactorCommands()->profileResetCancel($acceptKey);

                return null;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * @return SecondFactorCommands The second factor's commands, built once per process
     */
    protected function secondFactorCommands(): SecondFactorCommands
    {
        return $this->secondFactorCommands ??= new SecondFactorCommands($this);
    }

    /**
     * @return StepUpCommands Operation-level confirmation commands, built once per process
     */
    protected function stepUpCommands(): StepUpCommands
    {
        return $this->stepUpCommands ??= new StepUpCommands(
            $this,
            $this->secondFactorCommands(),
            $this->passkeyCommands(),
        );
    }

    /**
     * @return IdentityCommands The person's own ways in, added and taken off, built once per process
     */
    private function identityCommands(): IdentityCommands
    {
        return $this->identityCommands ??= new IdentityCommands($this);
    }

    /**
     * @return EmailChangeCommands The four steps of changing the account's email, built once per process
     */
    private function emailChangeCommands(): EmailChangeCommands
    {
        return $this->emailChangeCommands ??= new EmailChangeCommands($this, $this->stepUpCommands());
    }

    /**
     * @return PasswordChangeCommands Existing-password change, built once per process
     */
    private function passwordChangeCommands(): PasswordChangeCommands
    {
        return $this->passwordChangeCommands ??= new PasswordChangeCommands($this, $this->stepUpCommands());
    }

    /**
     * @return AccountDeletionCommands A person's own account deletion, built once per process
     */
    private function accountDeletionCommands(): AccountDeletionCommands
    {
        return $this->accountDeletionCommands ??= new AccountDeletionCommands($this, $this->stepUpCommands());
    }

    /**
     * @return UserRenameCommands Renaming a person with its journal row, built once per process
     */
    private function userRenameCommands(): UserRenameCommands
    {
        return $this->userRenameCommands ??= new UserRenameCommands($this);
    }

    /** @return ProfilePhotoCommands Profile photo workflow, built once per process */
    private function profilePhotoCommands(): ProfilePhotoCommands
    {
        return $this->profilePhotoCommands ??= new ProfilePhotoCommands($this);
    }

    /**
     * @return DetectionCommands The identifier lookup, built once per process
     */
    private function detectionCommands(): DetectionCommands
    {
        return $this->detectionCommands ??= new DetectionCommands($this);
    }

    /**
     * @return PasswordCommands The password door and the registration it mints, built once per process
     */
    private function passwordCommands(): PasswordCommands
    {
        return $this->passwordCommands ??= new PasswordCommands($this);
    }

    /**
     * @return PhoneCodeCommands The two submits of a code sent to a phone, built once per process
     */
    private function phoneCodeCommands(): PhoneCodeCommands
    {
        return $this->phoneCodeCommands ??= new PhoneCodeCommands($this);
    }

    /**
     * @return MagicLinkCommands Both halves of the mailed letter, built once per process
     */
    private function magicLinkCommands(): MagicLinkCommands
    {
        return $this->magicLinkCommands ??= new MagicLinkCommands($this);
    }

    /**
     * @return OAuthCommands Both ends of a provider login, built once per process
     */
    private function oauthCommands(): OAuthCommands
    {
        return $this->oauthCommands ??= new OAuthCommands($this);
    }

    /**
     * @return PasskeyCommands Enrolling a passkey and signing in with one, built once per process
     */
    private function passkeyCommands(): PasskeyCommands
    {
        return $this->passkeyCommands ??= new PasskeyCommands($this);
    }

    /**
     * @return RecoveryCommands The three submits of a password recovery, built once per process
     */
    private function recoveryCommands(): RecoveryCommands
    {
        return $this->recoveryCommands ??= new RecoveryCommands($this);
    }

    /**
     * Sends one hand-off frame and stops owing the caller an answer.
     *
     * The pair is what a hand-off IS, which is why it is one call: the frame carries the
     * request id away, and the dispatcher must not ack behind it.
     *
     * @param string $signalName Frame name the holder declared
     * @param SignalDataInterface $data Frame payload, carrying the request id and the answer
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    private function handOff(string $signalName, SignalDataInterface $data): void
    {
        $this->sendToAgent($signalName, $data);
        $this->deferActionReply();
    }

    /**
     * @param AccountDeletionSetSignalData $request Target deletion and waiting administrator
     * @throws WiringRefusal When this worker cannot access a required source
     * @throws InvalidArgumentException When the answer frame cannot be named or queued
     */
    private function handleDeletionSetRequest(AccountDeletionSetSignalData $request): void
    {
        try {
            // Calling a deletion off gives back rather than takes away, so it is no operation to confirm (HIL-1275).
            $by = $request->scheduled
                ? AskingAdministrator::confirmed($request->acceptKey, StepUpOperationKey::DELETE_OTHER_ACCOUNT)
                : AskingAdministrator::of($request->acceptKey);
            if ($request->scheduled) {
                if ($request->userId === $by) {
                    throw new ValidationException('Delete your own account from your profile');
                }
                $this->assertAdministratorMayDelete($request->userId);
                $this->accountDeletionCommands()->scheduleFor($request->userId);
                $this->logAgentInfo('account_deletion_scheduled ' . json_encode([
                    'event' => 'account_deletion_scheduled', 'user' => $request->userId, 'by' => $by,
                ]));
                $days = AccountDeletionSettings::graceDays();
                $message = $days === 1
                    ? 'Deletion scheduled: the account is erased in 1 day'
                    : "Deletion scheduled: the account is erased in {$days} days";
            } else {
                $this->accountDeletionCommands()->cancelFor($request->userId);
                $this->logAgentInfo('account_deletion_canceled ' . json_encode([
                    'event' => 'account_deletion_canceled', 'user' => $request->userId, 'by' => $by,
                ]));
                $message = 'Deletion canceled';
            }
        } catch (WiringRefusal $refusal) {
            throw $refusal;
        } catch (Throwable $e) {
            $refusal = ActionRefusal::fromThrowable($e);
            if ($refusal->isInternal()) {
                $this->logAgentError("Account deletion for #{$request->userId} failed: {$e->getMessage()}");
            }
            $this->sendToAgent($request->replySignal, HandoverAnswerSignalData::to($request, $refusal));

            return;
        }

        $this->sendToAgent($request->replySignal, new HandoverAnswerSignalData(
            acceptKey: $request->acceptKey,
            requestId: $request->requestId,
            action: $request->action,
            successMessage: $message,
            error: null,
            errorType: null,
            errorDetail: null,
        ));
    }

    /**
     * Renames a person for an administrator, or says why the person was not renamed.
     *
     * The refusals keep the sentences the demos' own handler sent, because they are what an
     * administrator reads. A missing person and a name the row refuses are answers, not
     * exceptions: the ask arrived as a frame, and a throw here would leave the modal waiting
     * forever.
     *
     * @param AdminRenameSignalData $rename Whom to rename, to what, and on whose word
     * @return ?ActionRefusal Why the person was not renamed, or null when they were or already carried the name
     */
    private function handleAdminRename(AdminRenameSignalData $rename): ?ActionRefusal
    {
        try {
            $this->renameUser($rename->userId, $rename->name, $rename->adminUserId);
        } catch (ItemNotFoundForUpdateException) {
            return ActionRefusal::said("User #{$rename->userId} not found");
        } catch (ValidationException $e) {
            return ActionRefusal::said('Failed to update user: ' . $e->getMessage());
        } catch (DatabaseException $e) {
            // The same refusal the dispatcher would have put on the wire had this been thrown on
            // the page: the placeholder for the person, the failure beside it for an admin.
            $this->logAgentError("Admin rename failed for userId={$rename->userId}: {$e->getMessage()}");

            return ActionRefusal::fromThrowable($e);
        } catch (WiringRefusal $refusal) {
            // Answered like the storage failure above rather than raised (HIL-575): the ask
            // arrived as a frame with a modal waiting on it, so a throw would hang the admin.
            // Its own words - the name of a collection nobody here reads - are not an answer
            // about this rename, so they ride only as the detail an admin may quote.
            $this->logAgentError("Admin rename refused for userId={$rename->userId}: {$refusal->getMessage()}");

            return ActionRefusal::fromThrowable($refusal);
        } catch (HilosException $e) {
            $this->logAgentError("Admin rename failed for userId={$rename->userId}: {$e->getMessage()}");

            return ActionRefusal::fromThrowable($e);
        }

        return null;
    }
}
