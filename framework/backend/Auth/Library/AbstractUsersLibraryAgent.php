<?php

declare(strict_types=1);

namespace Hilos\Auth\Library;

use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Library\Command\AbstractLibraryCommands;
use Hilos\Auth\Library\Command\ActingSession;
use Hilos\Auth\Library\Command\DetectionCommands;
use Hilos\Auth\Library\Command\MagicLinkCommands;
use Hilos\Auth\Library\Command\OAuthCommands;
use Hilos\Auth\Library\Command\PasskeyCommands;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Auth\Library\Command\PasswordCommands;
use Hilos\Auth\Library\Command\PhoneCodeCommands;
use Hilos\Auth\Library\Command\RecoveryCommands;
use Hilos\Auth\Library\DTO\AbandonRegistrationActionDTO;
use Hilos\Auth\Library\DTO\AuthPasswordChangedSignalData;
use Hilos\Auth\Library\DTO\AuthRecoveryGrantedSignalData;
use Hilos\Auth\Library\DTO\AuthRecoveryWaitMovedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationAbandonedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationLandedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationWaitMovedSignalData;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Library\DTO\CompletePasswordResetActionDTO;
use Hilos\Auth\Library\DTO\ConfirmMagicLinkActionDTO;
use Hilos\Auth\Library\DTO\ConfirmMagicLinkCodeActionDTO;
use Hilos\Auth\Library\DTO\ConfirmPasswordResetActionDTO;
use Hilos\Auth\Library\DTO\ConfirmPhoneCodeActionDTO;
use Hilos\Auth\Library\DTO\ConfirmRegisterActionDTO;
use Hilos\Auth\Library\DTO\DetectIdentifierActionDTO;
use Hilos\Auth\Library\DTO\LinkOAuthAfterReauthActionDTO;
use Hilos\Auth\Library\DTO\LoginActionDTO;
use Hilos\Auth\Library\DTO\OAuthCallbackActionDTO;
use Hilos\Auth\Library\DTO\OAuthLoginReadySignalData;
use Hilos\Auth\Library\DTO\OAuthStartActionDTO;
use Hilos\Auth\Library\DTO\PasskeyDiscoverableLoginOptionsActionDTO;
use Hilos\Auth\Library\DTO\PasskeyLoginConfirmActionDTO;
use Hilos\Auth\Library\DTO\PasskeyRegisterConfirmActionDTO;
use Hilos\Auth\Library\DTO\PasskeyRegisterOptionsActionDTO;
use Hilos\Auth\Library\DTO\RegisterActionDTO;
use Hilos\Auth\Library\DTO\RequestMagicLinkActionDTO;
use Hilos\Auth\Library\DTO\RequestPasswordResetActionDTO;
use Hilos\Auth\Library\DTO\RequestPhoneCodeActionDTO;
use Hilos\Auth\Library\DTO\RequestRegisterConfirmActionDTO;
use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Auth\Session\SessionAck;
use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Runtime\State\Item\RecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter;
use Hilos\Hilos;
use Hilos\HilosException;
use Random\RandomException;

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
 * It is ABSTRACT for one reason, the same one that makes the OAuth agent abstract: creating
 * a user touches the project's own users table, which the framework does not know the shape
 * of. The project supplies that step through {@see createUser()} and whatever it does
 * besides through {@see afterUserCreated()}, and names the methods it offers for an
 * identifier through {@see buildAuthMethods()}. Everything else - the commands, their
 * guards, their answers - stays here and is the same for every project that declares
 * {@see HilosFeature::AUTH}.
 */
abstract class AbstractUsersLibraryAgent extends AbstractAgent
{
    /**
     * @var list<string> The credentials its passkey commands enrol and check against
     *     ({@see PasskeyCommands}), which no page topology names and which nothing else declares.
     */
    public const array READS_DB = [HilosDbContext::passkeyCredentials];

    public const string AGENT_TYPE = HilosAgentType::HILOS_USERS_LIBRARY;

    /**
     * The throttle verdict is declared HERE and must not be declared by a second agent of
     * the project: routing picks the verdict's destination from whoever declared it, and
     * this is the agent whose dispatcher parks the throttled sign-in commands and waits for
     * it. Declaring it elsewhere takes the answer away from the pool that is waiting (HIL-420).
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT => ThrottleVerdictSignalData::class,
        HilosSignalConstants::HILOS_OAUTH_LOGIN_READY => OAuthLoginReadySignalData::class,
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
        HilosSignalConstants::HILOS_LOGIN => LoginActionDTO::class,
        HilosSignalConstants::HILOS_REGISTER => RegisterActionDTO::class,
        HilosSignalConstants::HILOS_REQUEST_PASSWORD_RESET => RequestPasswordResetActionDTO::class,
        HilosSignalConstants::HILOS_CONFIRM_PASSWORD_RESET => ConfirmPasswordResetActionDTO::class,
        HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET => CompletePasswordResetActionDTO::class,
        HilosSignalConstants::HILOS_REQUEST_REGISTER_CONFIRM => RequestRegisterConfirmActionDTO::class,
        HilosSignalConstants::HILOS_CONFIRM_REGISTER => ConfirmRegisterActionDTO::class,
        HilosSignalConstants::HILOS_ABANDON_REGISTRATION => AbandonRegistrationActionDTO::class,
        HilosSignalConstants::HILOS_REQUEST_PHONE_CODE => RequestPhoneCodeActionDTO::class,
        HilosSignalConstants::HILOS_CONFIRM_PHONE_CODE => ConfirmPhoneCodeActionDTO::class,
        HilosSignalConstants::HILOS_REQUEST_MAGIC_LINK => RequestMagicLinkActionDTO::class,
        HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK => ConfirmMagicLinkActionDTO::class,
        HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK_CODE => ConfirmMagicLinkCodeActionDTO::class,
        HilosSignalConstants::HILOS_OAUTH_START => OAuthStartActionDTO::class,
        HilosSignalConstants::HILOS_OAUTH_CALLBACK => OAuthCallbackActionDTO::class,
        HilosSignalConstants::HILOS_LINK_OAUTH_AFTER_REAUTH => LinkOAuthAfterReauthActionDTO::class,
        HilosSignalConstants::HILOS_PASSKEY_REGISTER_OPTIONS => PasskeyRegisterOptionsActionDTO::class,
        HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM => PasskeyRegisterConfirmActionDTO::class,
        HilosSignalConstants::HILOS_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS =>
            PasskeyDiscoverableLoginOptionsActionDTO::class,
        HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM => PasskeyLoginConfirmActionDTO::class,
    ];

    /**
     * Every anonymous-reachable door into an account is throttled (HIL-420), and the list
     * is exactly that: the ones that guess a secret and the ones that make the server spend
     * something on a stranger's say-so - an email, an SMS, a password hash, a registration
     * reservation. Reads are absent, with the one exception that proves the rule:
     * DETECT_IDENTIFIER answers whether an account exists, which is precisely what an
     * enumerator wants, and this list is the whole of what keeps that answer expensive
     * (HIL-414). Abandoning a registration is absent because it spends nothing and can only
     * ever undo the caller's own wait.
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
        HilosSignalConstants::HILOS_REQUEST_PHONE_CODE,
        HilosSignalConstants::HILOS_CONFIRM_PHONE_CODE,
        HilosSignalConstants::HILOS_REQUEST_MAGIC_LINK,
        HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK,
        HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK_CODE,
        HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM,
    ];

    /**
     * The commands that add to an account rather than open one, and so need a signed-in
     * session. Everything else here is a guest's way in and must stay open to one.
     */
    public const array AUTH_ACTIONS = [
        HilosSignalConstants::HILOS_LINK_OAUTH_AFTER_REAUTH,
        HilosSignalConstants::HILOS_PASSKEY_REGISTER_OPTIONS,
        HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM,
    ];

    /** Methods this project offers for an identifier, resolved once on start. */
    private IdentifierDetector $authMethods;

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

    /**
     * Claims the right to mint a user and resolves the project's auth seams.
     *
     * A creating truth source rather than a reading one: the library is the process that
     * brings an account into being, and that is the claim which has to be unique. The
     * identity, verification, reservation and credential tables need no claim of their own -
     * they are read by key and written by whoever holds the command, which is this agent.
     *
     * The two parked-surface collections are claimed because a command parks a browser on the
     * code step it just opened, and a runtime write with no claim behind it is refused. The
     * session holder claims them too, and for its own half - it parks a reconnecting socket
     * and releases every wait a converge or a dead connection ends.
     *
     * Which of the two OWNS the wait was open when this was written (HIL-622, P-125) and is
     * answered now: the holder does, wholly, and this library is a declared add/remove
     * co-owner beside it (HIL-685). So the pair is not two writers of one row - what the
     * library brings into being it may also take away, and everything else about a row that
     * already exists it says in a frame.
     *
     * @throws HilosException On database or runtime startup failure
     */
    public function onStart(): void
    {
        $this->authMethods = $this->buildAuthMethods();
        TruthSourceRegistry::registerCreate($this->usersCollection(), $this->getId());
        $this->registerRtTruthSource(RegistrationWaiter::RT_COLLECTION);
        $this->registerRtTruthSource(RecoveryWaiter::RT_COLLECTION);
    }

    /**
     * A library brings a row into being and takes it away, and never edits one.
     *
     * The standard behaviour of a library rather than a switch each project throws for
     * itself: what a library writes is a fact about an account being made or unmade, and a
     * fact already written is not its to reword. The whole of the rule is this one line, so
     * revisiting it - the owner is explicitly unsure about the removal half - costs one edit
     * and not a walk of every claim.
     *
     * @return list<TruthSourceOperation> Adding and removing, never updating
     */
    protected function defaultTruthSourceOperations(): array
    {
        return [TruthSourceOperation::Add, TruthSourceOperation::Remove];
    }

    /**
     * The library holds nothing across a stop: its state is the database.
     */
    public function onStop(): void
    {
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
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $source Signal source
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one this library declared
     * @throws ValidationException When the payload is not the one its name promises, or OAuth is unwired
     * @throws InvalidArgumentException When a frame the completion sends cannot be named or queued
     * @throws HilosException When the identity lookup, the account, or the project's bookkeeping fails
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        if ($name === HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT) {
            return;
        }
        if ($name !== HilosSignalConstants::HILOS_OAUTH_LOGIN_READY) {
            throw new AgentUnknownSignalException($name);
        }
        if (!$data->data instanceof OAuthLoginReadySignalData) {
            throw new ValidationException(
                HilosSignalConstants::HILOS_OAUTH_LOGIN_READY . ' payload must be ' . OAuthLoginReadySignalData::class,
            );
        }

        $this->oauthCommands()->completeLogin($data->data);
    }

    /**
     * Returns the methods this project is willing to offer for an identifier.
     *
     * Public because the command groups are separate classes and PHP has no visibility
     * between a class and its own collaborators; the same note covers everything below
     * that a group calls. Nothing outside {@see AbstractLibraryCommands} is meant to.
     *
     * @return IdentifierDetector Detector over the project's enabled method keys
     */
    public function authMethods(): IdentifierDetector
    {
        return $this->authMethods;
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
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    public function grantSession(
        ActingSession $acting,
        int $userId,
        ?string $ack = null,
        ?AuthFlowOutcome $outcome = null,
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
     * Tells the session holder one browser walked away from the registration it was on.
     *
     * The holder forgets the wait - the parked sockets and the durable memory alike - so
     * this session's tabs go back to the identifier field together. The hold on the
     * address is deliberately NOT dropped, and that is the library's decision rather than
     * the holder's: it is what puts a returning person back on their own code screen
     * without spending a second letter.
     *
     * @param ActingSession $acting Browser abandoning its registration
     * @param ?AuthFlowOutcome $outcome Where the surface goes next, answered by the holder
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    public function announceRegistrationAbandoned(
        ActingSession $acting,
        ?AuthFlowOutcome $outcome = null,
    ): void {
        $this->handOff(
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_ABANDONED,
            new AuthRegistrationAbandonedSignalData(
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
     * Names the project collection the user rows live in.
     *
     * The framework knows a user by id and nothing else; which collection holds the row is
     * the project's, which is why the truth-source claim asks rather than assumes.
     *
     * @return string Collection name of the project's users
     */
    abstract protected function usersCollection(): string;

    /**
     * Creates one user row in the project's own users table.
     *
     * The seam the whole class is abstract for: the framework has an identity, an address
     * and a display name, and no idea what a user of THIS project is made of.
     *
     * Called inside the landing transaction, and {@see afterUserCreated()} after it commits:
     * the row and the identity that makes it reachable stand or fall together, while what a
     * project writes ABOUT a new member is news and must not be rolled back into existence.
     *
     * @param string $displayName Name to show for the new account
     * @return int Durable id of the created user
     * @throws HilosException When the project's create fails
     */
    abstract public function createUser(string $displayName): int;

    /**
     * Names one account the way the project would show it.
     *
     * The framework knows a user by id; what to call them is a column of the project's own
     * table. Asked for by the passkey enrollment, whose options carry a name the OS picker
     * draws beside the key - a person with two accounts sees only this to tell them apart.
     *
     * Null when the project has nothing to show - a deleted row, or an account it never
     * named - and the caller then draws its own placeholder rather than an empty label.
     *
     * @param int $userId Account to name
     * @return ?string Name to show, or null when the project has none for this account
     * @throws HilosException When the project's lookup fails
     */
    abstract public function displayNameOf(int $userId): ?string;

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
     * Builds the detector over the method keys this project has actually wired.
     *
     * @return IdentifierDetector Detector answering with keys the project can serve
     */
    abstract protected function buildAuthMethods(): IdentifierDetector;

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
     * @throws ValidationException When the command refuses what was submitted
     * @throws RandomException When issuing a verification code cannot draw from the CSPRNG
     * @throws HilosException When a command exposes database, runtime, or settings failure
     */
    private function runOwnedAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_DETECT_IDENTIFIER:
                if (!$dto instanceof DetectIdentifierActionDTO) {
                    throw new InvalidActionPayloadException($action, DetectIdentifierActionDTO::class, $dto);
                }

                return $this->detectionCommands()->detectIdentifier($acceptKey, $dto);

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

            case HilosSignalConstants::HILOS_ABANDON_REGISTRATION:
                if (!$dto instanceof AbandonRegistrationActionDTO) {
                    throw new InvalidActionPayloadException($action, AbandonRegistrationActionDTO::class, $dto);
                }
                $this->passwordCommands()->abandonRegistration($acceptKey, $dto);

                return null;

            case HilosSignalConstants::HILOS_REQUEST_PHONE_CODE:
                if (!$dto instanceof RequestPhoneCodeActionDTO) {
                    throw new InvalidActionPayloadException($action, RequestPhoneCodeActionDTO::class, $dto);
                }
                $this->phoneCodeCommands()->requestPhoneCode($acceptKey, $dto);

                return null;

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
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
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
}
