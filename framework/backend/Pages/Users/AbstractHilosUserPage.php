<?php

declare(strict_types=1);

namespace Hilos\Pages\Users;

use Hilos\AdminViewMode\WireField;
use Hilos\Auth\AccountDeletion\AccountDeletionSettings;
use Hilos\Auth\Impersonation\DTO\ImpersonationCardSettings;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\ImpersonateRequestSignalData;
use Hilos\Auth\Session\DTO\ImpersonateStartActionDTO;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\HandoverGatekeeperTrait;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Users\DTO\HilosUserPageSubscribeParams;
use Hilos\Users\AccountStandingResolver;
use Hilos\Users\DTO\AccountAdminSetSignalData;
use Hilos\Users\DTO\AccountBlockSetSignalData;
use Hilos\Users\DTO\AccountDeletionSetSignalData;
use Hilos\Users\DTO\AccountMergeActionDTO;
use Hilos\Users\DTO\AccountMergeSignalData;
use Hilos\Users\DTO\AccountStandingStateSignalData;
use Hilos\Users\DTO\AdminRenameSignalData;
use Hilos\Users\DTO\HilosUserAdminSetActionDTO;
use Hilos\Users\DTO\HilosUserBlockSetActionDTO;
use Hilos\Users\DTO\HilosUserDeletionSetActionDTO;
use Hilos\Users\DTO\HilosUserUpdateActionDTO;
use Hilos\Users\DTO\HilosUserUpdateFailSignalData;
use Hilos\Users\DTO\HilosUserUpdateSuccessSignalData;
use Throwable;

/**
 * Base class for the framework Hilos single-user page.
 *
 * The default subscription path answers the client, parses the `userId` route param, and then
 * calls {@see self::onHilosUserSubscribe()}.
 *
 * Its ADMIN gate closes renaming, account merging, rights, blocking, scheduled deletion and taking
 * the person over. The sessions or users library judges and writes each change, then returns its
 * outcome here to complete the tracked submit on the surface that accepted it. A rename is
 * forwarded here since HIL-1195, when the users library took it over from the projects' own copies
 * of this page; the takeover since HIL-1170, when its button moved from the people list onto the
 * card - the name is declared on this base class, so every project mounting the card gets it.
 */
abstract class AbstractHilosUserPage extends AbstractHilosPage
{
    // The rename answers an untracked submit on the card's own acks, every other action as the trait does.
    use HandoverGatekeeperTrait {
        answerUntracked as private answerUntrackedByDefault;
    }

    public const string PAGE = HilosPageConstants::HILOS_USER;

    public const string ACCOUNT_DELETION_GRACE_DAYS = 'accountDeletionGraceDays';

    /** Page data key of the standing of the person the card shows (HIL-945): the one verdict the card reads. */
    public const string ACCOUNT_STANDING = 'accountStanding';

    /** Page data key of the impersonation settings the card's takeover section is drawn from (HIL-1170). */
    public const string IMPERSONATION = 'impersonation';

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_USER_UPDATE => HilosUserUpdateActionDTO::class,
        HilosSignalConstants::HILOS_USER_MERGE => AccountMergeActionDTO::class,
        HilosSignalConstants::HILOS_USER_ADMIN_SET => HilosUserAdminSetActionDTO::class,
        HilosSignalConstants::HILOS_USER_BLOCK_SET => HilosUserBlockSetActionDTO::class,
        HilosSignalConstants::HILOS_USER_DELETION_SET => HilosUserDeletionSetActionDTO::class,
        HilosSignalConstants::HILOS_IMPERSONATE_START => ImpersonateStartActionDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE => HandoverAnswerSignalData::class,
            HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE => HandoverAnswerSignalData::class,
            HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE => HandoverAnswerSignalData::class,
            HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE => HandoverAnswerSignalData::class,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET_DONE => HandoverAnswerSignalData::class,
            HilosSignalConstants::HILOS_IMPERSONATE_DONE => HandoverAnswerSignalData::class,
        ],
    ];

    /**
     * The standing frame one subscriber of the card is sent, past the view mode's bridge, or null when it is sent nothing.
     *
     * The card's own road for what {@see AccountStandingAudience} sends between page answers: the
     * gate is asked for this connection now, and a viewer gets the frame hidden
     * ({@see AbstractPage::frameForViewer()}).
     *
     * @param string $acceptKey Connection the frame goes to
     * @param AccountStandingStateSignalData $data Frame as an admin receives it
     * @return ?SignalDataInterface The frame, its hidden copy for a viewer, or null when the gate refuses the connection now
     */
    public static function standingFrame(string $acceptKey, AccountStandingStateSignalData $data): ?SignalDataInterface
    {
        return static::frameForViewer($acceptKey, $data, AccountStandingStateSignalData::wireFields());
    }

    /**
     * Hands account lifecycle actions to their owning libraries.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Domain reply, or null while the library owes the answer
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the payload does not match the action name
     * @throws InvalidArgumentException When the request cannot be handed to the library
     * @throws InvalidFormatException When the lifecycle target id is not positive
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_USER_UPDATE:
                if (!$dto instanceof HilosUserUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosUserUpdateActionDTO::class, $dto);
                }
                $this->handleRename($acceptKey, $dto);

                break;

            case HilosSignalConstants::HILOS_USER_MERGE:
                if (!$dto instanceof AccountMergeActionDTO) {
                    throw new InvalidActionPayloadException($action, AccountMergeActionDTO::class, $dto);
                }
                $this->handleAccountMerge($acceptKey, $dto);

                break;

            case HilosSignalConstants::HILOS_USER_ADMIN_SET:
                if (!$dto instanceof HilosUserAdminSetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosUserAdminSetActionDTO::class, $dto);
                }
                $this->handleAdminSet($acceptKey, $dto);

                break;

            case HilosSignalConstants::HILOS_USER_BLOCK_SET:
                if (!$dto instanceof HilosUserBlockSetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosUserBlockSetActionDTO::class, $dto);
                }
                $this->handleBlockSet($acceptKey, $dto);

                break;

            case HilosSignalConstants::HILOS_USER_DELETION_SET:
                if (!$dto instanceof HilosUserDeletionSetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosUserDeletionSetActionDTO::class, $dto);
                }
                $this->handleDeletionSet($acceptKey, $dto);

                break;

            case HilosSignalConstants::HILOS_IMPERSONATE_START:
                if (!$dto instanceof ImpersonateStartActionDTO) {
                    throw new InvalidActionPayloadException($action, ImpersonateStartActionDTO::class, $dto);
                }
                $this->handleImpersonateStart($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Answers the admin whose request the owning library has finished.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender in full, as {@see SignalSource::describe()} spells it (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one this page declares
     * @throws LogicException When the payload is not the one its name promises
     * @throws InvalidArgumentException When the browser ack cannot be named
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE:
            case HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE:
            case HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE:
            case HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE:
            case HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET_DONE:
            case HilosSignalConstants::HILOS_IMPERSONATE_DONE:
                if (!$data->data instanceof HandoverAnswerSignalData) {
                    throw new LogicException($name . ' payload must be ' . HandoverAnswerSignalData::class);
                }

                $this->answerHandover($data->data);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Sends a failed rename through the card's modal ack contract.
     *
     * A rename that failed on this page - its payload did not parse, or a guard refused it - is
     * answered with the fail ack the card listens for; every other action with the default. The
     * ack carries the failure's own text only for a connection that proves an admin now.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name that failed
     * @param ActionPayloadDTO $dto Action payload
     * @param Throwable $e Action failure
     * @throws InvalidArgumentException When the ack or the fallback action-error frame cannot be named
     */
    public function onActionException(string $acceptKey, string $action, ActionPayloadDTO $dto, Throwable $e): void
    {
        if ($action === HilosSignalConstants::HILOS_USER_UPDATE) {
            $this->sendToUser(
                HilosSignalConstants::HILOS_USER_UPDATE_FAIL,
                $acceptKey,
                new HilosUserUpdateFailSignalData($this->failureText($acceptKey, $e)),
            );

            return;
        }

        parent::onActionException($acceptKey, $action, $dto, $e);
    }

    /**
     * Lets go of the connection's place among the cards kept in step with their person's standing.
     *
     * @param string $acceptKey WebSocket accept key
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        AccountStandingAudience::removeSubscriber($acceptKey);
    }

    /**
     * Sends an untracked rename's outcome as the two named acks the card has always listened
     * for, the refusal and the success both - a tracked one is answered on its request id instead.
     *
     * @param string $acceptKey Accept key of the admin who asked
     * @param string $action Browser action name the outcome belongs to
     * @param ?string $error Why the write was refused, or null when it went through
     * @throws InvalidArgumentException When the ack cannot be named
     */
    protected function answerUntracked(string $acceptKey, string $action, ?string $error): void
    {
        if ($action !== HilosSignalConstants::HILOS_USER_UPDATE) {
            $this->answerUntrackedByDefault($acceptKey, $action, $error);

            return;
        }

        if ($error !== null) {
            $this->sendToUser(HilosSignalConstants::HILOS_USER_UPDATE_FAIL, $acceptKey, new HilosUserUpdateFailSignalData($error));

            return;
        }

        $this->sendToUser(HilosSignalConstants::HILOS_USER_UPDATE_SUCCESS, $acceptKey, new HilosUserUpdateSuccessSignalData());
    }

    /**
     * Carries the deletion confirmation's grace period, the person's standing and the impersonation settings in the subscription's first answer.
     *
     * The standing is the one verdict the card reads its block, freeze and scheduled deletion from
     * (HIL-945); later changes arrive as {@see HilosSignalConstants::HILOS_ACCOUNT_STANDING_STATE}.
     * The impersonation settings decide whether the card has its takeover section and for whom its
     * button is switched off (HIL-1170); no frame follows them - a setting changed while the card is
     * open is caught by the server's refusal in the same words. A viewer of the admin view mode is
     * shown the impersonation settings ({@see self::dataFields()}) and the rest hidden: the people's
     * fields are opened by HIL-1254.
     *
     * @param string $acceptKey Subscribing connection (unused)
     * @param PageRouteParams $params Route params naming the person
     * @return ?PagePayload Grace period and standing snapshot, supplemented by the page's identity
     * @throws MissingPageRouteParamException When `userId` is absent
     * @throws InvalidPageRouteParamException When `userId` is non-numeric or `<= 0`
     * @throws DatabaseException When the stored setting or the person's standing cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     * @throws HilosException When the person's standing cannot be read
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        $userId = HilosUserPageSubscribeParams::fromPageRouteParams($params)->userId;

        return new PagePayload(data: [
            self::ACCOUNT_DELETION_GRACE_DAYS => AccountDeletionSettings::graceDays(),
            self::ACCOUNT_STANDING => AccountStandingResolver::of($userId)->toArray(),
            self::IMPERSONATION => ImpersonationCardSettings::current()->toArray(),
        ]);
    }

    /**
     * Declares the impersonation settings open to a viewer of the admin view mode (HIL-1170).
     *
     * They are settings of the installation, not facts about the person on the card; the card's
     * other keys stay hidden until the people's fields are opened (HIL-1254).
     *
     * @return array<string, WireField> Data key to where it comes from
     */
    protected function dataFields(): array
    {
        return [self::IMPERSONATION => WireField::notPersonal()];
    }

    /**
     * Parses route params, keeps the card in step with its person's standing, and runs the typed
     * hook, once the client has been answered.
     *
     * Final: subclasses customize subscribe behavior through
     * {@see self::onHilosUserSubscribe()}, not this method.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params for the page subscription
     * @throws MissingPageRouteParamException When `userId` is absent
     * @throws InvalidPageRouteParamException When `userId` is non-numeric or `<= 0`
     * @throws HilosException Whatever else the typed hook raises
     */
    final protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        $parsed = HilosUserPageSubscribeParams::fromPageRouteParams($params);
        AccountStandingAudience::addSubscriber($acceptKey, $parsed->userId);
        $this->onHilosUserSubscribe($acceptKey, $parsed);
    }

    /**
     * Runs optional project-specific subscribe behavior after the page has been answered.
     *
     * Default intentionally does nothing.
     *
     * @param string $acceptKey WebSocket accept key
     * @param HilosUserPageSubscribeParams $params Parsed subscribe params (always has `userId > 0`)
     */
    protected function onHilosUserSubscribe(string $acceptKey, HilosUserPageSubscribeParams $params): void
    {
    }

    /**
     * Hands one rename to the users library, which owns the person's row and its journal.
     *
     * Nothing is judged on the way out, not even that the person exists: the answer would be
     * read in this worker and acted on in another. Who is asking IS resolved here, because this
     * worker is the one holding the admin's socket.
     *
     * @param string $acceptKey WebSocket accept key of the requesting admin
     * @param HilosUserUpdateActionDTO $dto Person and the name typed for them
     * @throws InvalidArgumentException When the rename frame cannot be named or queued
     */
    private function handleRename(string $acceptKey, HilosUserUpdateActionDTO $dto): void
    {
        $this->forward(
            HilosSignalConstants::HILOS_USER_ADMIN_RENAME,
            new AdminRenameSignalData(
                userId: $dto->id,
                name: $dto->name,
                replySignal: HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::HILOS_USER_UPDATE,
                successMessage: null,
                adminUserId: Hilos::$browser?->resolveActionUserId($acceptKey),
            ),
        );
    }

    /**
     * Forwards one merge without judging it in the page worker.
     *
     * @param string $acceptKey WebSocket accept key of the requesting admin
     * @param AccountMergeActionDTO $dto Account pair and optional password choice
     * @throws InvalidArgumentException When the request frame cannot be named or queued
     */
    private function handleAccountMerge(string $acceptKey, AccountMergeActionDTO $dto): void
    {
        $this->forward(
            HilosSignalConstants::HILOS_ACCOUNT_MERGE,
            new AccountMergeSignalData(
                survivorUserId: $dto->survivorUserId,
                loserUserId: $dto->loserUserId,
                passwordFate: $dto->passwordFate?->value,
                replySignal: HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::HILOS_USER_MERGE,
                successMessage: null,
            ),
        );
    }

    /**
     * @param string $acceptKey Initiating administrator connection
     * @param HilosUserAdminSetActionDTO $dto Target account and requested state
     * @throws InvalidArgumentException When the request frame cannot be named or queued
     * @throws InvalidFormatException When the target id is not positive
     */
    private function handleAdminSet(string $acceptKey, HilosUserAdminSetActionDTO $dto): void
    {
        $this->forward(
            HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET,
            new AccountAdminSetSignalData(
                userId: $dto->userId,
                admin: $dto->admin,
                replySignal: HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::HILOS_USER_ADMIN_SET,
                successMessage: null,
            ),
        );
    }

    /**
     * @param string $acceptKey Initiating administrator connection
     * @param HilosUserBlockSetActionDTO $dto Target account and requested state
     * @throws InvalidArgumentException When the request frame cannot be named or queued
     * @throws InvalidFormatException When the target id is not positive
     */
    private function handleBlockSet(string $acceptKey, HilosUserBlockSetActionDTO $dto): void
    {
        $this->forward(
            HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET,
            new AccountBlockSetSignalData(
                userId: $dto->userId,
                block: $dto->block,
                replySignal: HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::HILOS_USER_BLOCK_SET,
                successMessage: null,
            ),
        );
    }

    /**
     * @param string $acceptKey Initiating administrator connection
     * @param HilosUserDeletionSetActionDTO $dto Target account and requested state
     * @throws InvalidArgumentException When the request frame cannot be named or queued
     * @throws InvalidFormatException When the target id is not positive
     */
    private function handleDeletionSet(string $acceptKey, HilosUserDeletionSetActionDTO $dto): void
    {
        $this->forward(
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET,
            new AccountDeletionSetSignalData(
                userId: $dto->userId,
                scheduled: $dto->scheduled,
                replySignal: HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::HILOS_USER_DELETION_SET,
                successMessage: null,
            ),
        );
    }

    /**
     * Hands one takeover of the person on the card to the owner of the session and stops owing the caller an answer.
     *
     * The admin half of a two-step action: what this page is, is the door - it carries the ADMIN
     * level that decides who may ask at all, and an agent action carries no such level, which is
     * why the name is here. What it is not, is the writer: the session belongs to
     * {@see AbstractSessionsLibraryAgent}, and a page runs in whichever worker serves the
     * connection, so a page rebinding it would be a write with no claim behind it.
     *
     * Nothing is judged here on the way out, the admin's own session and the settings included:
     * they would be read in this worker and acted on in another, and they are free to change in
     * between. The library re-checks the administrator, asks the confirmation of the operation when
     * it is switched on, and runs the whole guard order where it writes.
     *
     * No sentence is spoken on success: the takeover arrives as the rebound session on the
     * handshake the library publishes, which is what the person sees change.
     *
     * @param string $acceptKey WebSocket accept key of the requesting admin
     * @param ImpersonateStartActionDTO $dto Impersonation-start action payload
     * @throws InvalidArgumentException When the request frame cannot be named or queued
     */
    private function handleImpersonateStart(string $acceptKey, ImpersonateStartActionDTO $dto): void
    {
        $this->forward(
            HilosSignalConstants::HILOS_IMPERSONATE_REQUEST,
            new ImpersonateRequestSignalData(
                targetUserId: $dto->targetUserId,
                replySignal: HilosSignalConstants::HILOS_IMPERSONATE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::HILOS_IMPERSONATE_START,
                successMessage: null,
            ),
        );
    }
}
