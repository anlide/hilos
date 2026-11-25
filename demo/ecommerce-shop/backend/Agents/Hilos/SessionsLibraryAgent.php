<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents\Hilos;

use Demo\EcommerceShop\Agents\EcommerceShopAgent;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\ShopAccountMergeCartWakeSignalData;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Hilos;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\HilosProfileFlow as StateHilosProfileFlow;
use Hilos\Runtime\State\Item\HilosProfileNameChange as StateHilosProfileNameChange;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;
use Hilos\WiringRefusal;
use Random\RandomException;
use Throwable;

/**
 * The ecommerce-shop demo's sessions library - its own way in and registration holds.
 *
 * Everything a session is lives in {@see AbstractSessionsLibraryAgent} (HIL-710), and so do the
 * operations over the person since HIL-1197: {@see CliCommands::ADMIN_CREATE} and the grant
 * pair write `hilos_user` in the framework, the block and the takeover check alongside them.
 * What this demo has to say for itself is the claims over its own sign-in - the waits and the
 * registration holds. The framework erases the whole person, including the rename journal
 * and person row (HIL-1200); this demo has no additional rows to erase.
 *
 * Registered under {@see HilosAgentType::HILOS_SESSIONS_LIBRARY} by this demo's own topology,
 * which is also what makes the handshake arrive here rather than in {@see EcommerceShopAgent}.
 */
final class SessionsLibraryAgent extends AbstractSessionsLibraryAgent
{
    /**
     * @var array<string, list<TruthSourceOperation>> Framework writes and creation of guest cart transfers
     */
    public const array OWNS_DB = [
        ...parent::OWNS_DB,
        EcommerceShopDbContext::shopGuestCartTransfers => [TruthSourceOperation::Add],
    ];

    /**
     * Registration holds read at handshake and guest cart contents checked when assigning a transfer.
     *
     * Only the users library writes these holds (HIL-1411). The read is declared in the project
     * because only {@see AuthFeature::mount()} mounts the registration reservation table.
     *
     * @var list<string>
     */
    public const array READS_DB = [
        ...parent::READS_DB,
        HilosDbContext::registrationReservations,
        EcommerceShopDbContext::sessionProductCarts,
        EcommerceShopDbContext::sessionVoucherCarts,
    ];

    /**
     * The two waits a sign-in parks a browser on, and the line that says how its code is
     * travelling: a registration in progress, a recovery, and the send behind them (HIL-826).
     *
     * Declared here rather than by {@see AbstractSessionsLibraryAgent} because the collections
     * exist only where a sign-in surface does: {@see AuthFeature::mount()} mounts them and nothing
     * else does, so a library that claimed them in a project without one would read a collection
     * that is not there on every tick. None of them has a second writer: the users library asks
     * for every park by frame (HIL-1044, it was an add/remove co-owner of the waits since HIL-685),
     * and the transports carrying the code report to this library by frame.
     * Neither have the provider sign-ins tabs are waiting on, which stand here for the same
     * reason (HIL-1044): the agents carrying the exchange report every ending by frame.
     * Nor have the profile windows half-way through (HIL-1182): the users library reports every step by frame.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_RT = [
        StateRegistrationWaiter::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        StateRecoveryWaiter::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        StateHilosCodeSendAttempt::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        StateHilosOAuthTrip::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        StateHilosProfileFlow::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        StateHilosProfileNameChange::RT_COLLECTION => TruthSourceOperation::BY_KIND,
    ];

    /** @var array<string, mixed> Framework frames and the shop's merge wake */
    public const array AGENT_SIGNALS = [
        ...parent::AGENT_SIGNALS,
        EcommerceShopSignalConstants::SHOP_ACCOUNT_MERGE_CART_WAKE => ShopAccountMergeCartWakeSignalData::class,
    ];

    /**
     * Handles the shop's merge wake and delegates the framework's frames.
     *
     * @param AgentSignalData $data Wrapped agent payload
     * @param string $sender Full sender identity
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When neither handler owns the frame
     * @throws InvalidAgentSignalPayloadException When the payload differs from its declaration
     * @throws HilosException When storage, runtime, session or merge work fails
     * @throws RandomException When a session token cannot be minted
     * @throws InvalidArgumentException When a frame or reply cannot be named
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case EcommerceShopSignalConstants::SHOP_ACCOUNT_MERGE_CART_WAKE:
                $wake = $data->data;
                if (!$wake instanceof ShopAccountMergeCartWakeSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, ShopAccountMergeCartWakeSignalData::class, $wake);
                }

                $this->resumeMergeAfterGuestReceipt($wake, $sender);

                return;

            default:
                parent::onSignalAgent($data, $sender, $name);
        }
    }

    /**
     * Drives the existing merge only while the canonical Give is still outstanding.
     *
     * @param ShopAccountMergeCartWakeSignalData $wake Merge operation addressed by the wake
     * @param string $sender Full sender identity
     * @throws HilosException When the stored facts cannot be read or the merge drive fails
     */
    private function resumeMergeAfterGuestReceipt(ShopAccountMergeCartWakeSignalData $wake, string $sender): void
    {
        $operation = Hilos::$db->accountOperations->fresh($wake->operationId);
        if ($operation === null || $operation->kind !== AccountOperationKind::Merge
            || $operation->stage !== AccountMergeStage::Go->value || $operation->finishedAt !== null) {
            return;
        }
        $loser = new SignalSource(SignalSource::AGENT, HilosAgentType::HILOS_USER, (string)$operation->loserUserId);
        if ($sender !== SignalSource::describe($loser)) {
            return;
        }

        $give = Hilos::$db->accountOperationAssignments->byIdentity(
            $wake->operationId,
            AccountMergeStage::Go->value,
            AccountMergeDuty::Give->value,
        );
        if ($give !== null && Hilos::$db->accountOperationResults->byAssignment($give->id) !== null) {
            return;
        }

        try {
            $this->driveAccountMerge($operation);
        } catch (WiringRefusal $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new AgentException("Account merge #{$wake->operationId} could not resume after the guest cart receipt.", previous: $failure);
        }
    }
}
