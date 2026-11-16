<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountMergeAccess;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationParticipantStep;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Library\DTO\ConfirmMagicLinkCodeActionDTO;
use Hilos\Auth\Library\DTO\LoginActionDTO;
use Hilos\Auth\Library\DTO\ProfileSetPasswordActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddSmsRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddSmsConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddPasswordRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddPasswordConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeCurrentConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeCurrentRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeNewRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeNewConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordCodeConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordCodeRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordOpenActionDTO;
use Hilos\Auth\Library\DTO\RequestPasswordResetActionDTO;
use Hilos\Auth\Library\DTO\ConfirmPasswordResetActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorCodesShowActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorCodesRenewActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorEnrollStartActionDTO;
use Hilos\Auth\SecondFactor\DTO\SecondFactorProfileReplyDTO;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\Recovery\PasswordRecoveryService;
use Hilos\Core\Exception\ValidationException;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Database\Database;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Verification\VerificationType;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Hilos;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\UserSecondFactorProveDoneSignalData;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;

/** Opportunistic protection writes never turn an otherwise permitted pre-fact login into a refusal. */
final class AccountMergeAncillaryIntegrationTest extends ProfileIntegrationTestCase
{
    private const string EMAIL = 'merge-ancillary@example.test';
    private const string PASSWORD = 'merge-ancillary-secret';
    private const string CODE = '123456';
    private const string HOLDER_ID = 'merge-ancillary-holder';
    private const array TABLES = ['hilos_account_operation_assignment', 'hilos_account_operation_result'];

    /** Adds the carriers and the holder's ordinary declarations to the profile fixture. */
    protected function setUp(): void
    {
        parent::setUp();
        self::stubs(true);
        self::stubs(false);
        OwnershipDeclaration::claimDb(ProfileIntegrationHolder::class, self::HOLDER_ID);
    }

    /** Drops the carriers before their parent table and releases the extra holder. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            self::stubs(true);
        } finally {
            TruthSourceRegistry::unregisterAgent(self::HOLDER_ID);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::HOLDER_ID));
            parent::tearDown();
        }
    }

    /**
     * @param bool $magic Whether a letter's code proved the address
     * @param bool $queued Whether reservation overtakes a queued owner write
     * @param bool $fact Whether give commits before the answer returns to the library
     */
    #[DataProvider('continuations')]
    public function testAncillaryWriteIsSkippedOrRefusedAndOnlyFreshSignInMayContinue(bool $magic, bool $queued, bool $fact): void
    {
        $now = TimeHelper::nowMs();
        $hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        Database::sql('INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`, `secret`, `verified`, `created_at`, `updated_at`)'
            . ' VALUES (?, ?, ?, ?, 0, ?, ?)', [self::USER_ID, IdentityType::PASSWORD, self::EMAIL, $hash, $now, $now]);
        if ($magic) {
            $this->seedCode(VerificationType::MAGIC_LINK_CODE, self::EMAIL, self::USER_ID, self::CODE);
        }
        $before = Database::sql('SELECT * FROM `hilos_identity`')->rows();
        $ownerName = $magic ? HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY : HilosSignalConstants::HILOS_USER_PASSWORD_REHASH;
        $doneName = $magic ? HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY_DONE : HilosSignalConstants::HILOS_USER_PASSWORD_REHASH_DONE;
        if (!$queued) {
            $this->reserve();
        }
        $action = $magic ? new ConfirmMagicLinkCodeActionDTO(self::EMAIL, self::CODE) : new LoginActionDTO(self::EMAIL, self::PASSWORD);
        $this->library->onAgentAction(self::ANONYMOUS_ACCEPT_KEY, $action->getAction(), $action);
        $signals = self::takeSignals();
        if ($queued) {
            $asks = self::named($signals, $ownerName);
            self::assertCount(1, $asks, 'The original proof must queue its opportunistic write before the reservation');
            $operation = $this->reserve();
            if ($fact) {
                $this->give($operation);
            }
            $this->deliverToPerson($asks[0]);
            $answers = self::named(self::takeSignals(), $doneName);
            self::assertCount(1, $answers);
            self::assertInstanceOf(AgentSignalData::class, $answers[0]->data);
            self::assertSame(AccountMergeAccess::REFUSED_MESSAGE, $answers[0]->data->data->error);
            $this->library->onSignalAgent($answers[0]->data, 'test', $doneName);
            $signals = self::takeSignals();
        } else {
            self::assertSame([], self::named($signals, $ownerName), 'Reserved protection is never rewritten as an opportunistic login side effect');
        }
        $grants = self::named($signals, HilosSignalConstants::HILOS_AUTH_SESSION_GRANT);
        self::assertCount($fact ? 0 : 1, $grants);
        if (!$fact) {
            self::assertInstanceOf(AgentSignalData::class, $grants[0]->data);
            self::assertInstanceOf(AuthSessionGrantSignalData::class, $grants[0]->data->data);
            self::assertSame(self::USER_ID, $grants[0]->data->data->userId);
        }
        self::assertSame($before, Database::sql('SELECT * FROM `hilos_identity`')->rows());
    }

    /** @return iterable<string, array{bool, bool, bool}> Both ancillary writes and both sides of their queued continuation */
    public static function continuations(): iterable
    {
        yield 'reserved password skips rehash' => [false, false, false];
        yield 'reserved letter skips verification mark' => [true, false, false];
        yield 'queued rehash refused before fact' => [false, true, false];
        yield 'queued mark refused before fact' => [true, true, false];
        yield 'queued rehash refused after fact' => [false, true, true];
        yield 'queued mark refused after fact' => [true, true, true];
    }

    /** Early library doors refuse before live recovery proof or any new code-send line is touched. */
    public function testProfileAndRecoveryDoorsRefuseBeforeProofOrCodeSideEffects(): void
    {
        $now = TimeHelper::nowMs();
        Database::sql('INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`, `secret`, `verified`, `created_at`, `updated_at`)'
            . ' VALUES (?, ?, ?, ?, 1, ?, ?)',
            [self::USER_ID, IdentityType::PASSWORD, self::EMAIL, password_hash(self::PASSWORD, PASSWORD_BCRYPT), $now, $now]);
        foreach ([StepUpOperationKey::ADD_SIGN_IN_METHOD, StepUpOperationKey::CHANGE_EMAIL, StepUpOperationKey::CHANGE_PASSWORD] as $operation) {
            $this->confirmStepUp($operation);
        }
        $this->seedCode(VerificationType::PASSWORD_RESET, self::EMAIL, self::USER_ID, self::CODE);
        $this->reserve();
        $before = Database::sql('SELECT * FROM `hilos_user_verification`')->rows();
        $actions = [
            new ProfileSetPasswordActionDTO(self::PASSWORD),
            new ProfileAddSmsRequestActionDTO('+15551234567'),
            new ProfileAddSmsConfirmActionDTO(self::CODE),
            new ProfileAddPasswordRequestActionDTO(self::EMAIL),
            new ProfileAddPasswordConfirmActionDTO(self::CODE, self::PASSWORD),
            new ProfileEmailChangeCurrentRequestActionDTO(),
            new ProfileEmailChangeCurrentConfirmActionDTO(self::CODE),
            new ProfileEmailChangeNewRequestActionDTO('merge-next@example.test'),
            new ProfileEmailChangeNewConfirmActionDTO(self::CODE),
            new ProfileChangePasswordOpenActionDTO(),
            new ProfileChangePasswordCodeRequestActionDTO(),
            new ProfileChangePasswordCodeConfirmActionDTO(self::CODE),
            new ProfileChangePasswordActionDTO(self::PASSWORD, false),
            new RequestPasswordResetActionDTO(self::EMAIL),
            new ConfirmPasswordResetActionDTO(self::EMAIL, self::CODE),
        ];
        foreach ($actions as $action) {
            $this->assertRefused(AccountMergeAccess::REFUSED_MESSAGE, $action->getAction(), $action);
            self::assertSame($before, Database::sql('SELECT * FROM `hilos_user_verification`')->rows(), $action->getAction());
            self::assertNull($this->codeSendLine(), 'A refused mutation must not open a progress line');
        }
        $recovery = new PasswordRecoveryService();
        foreach ([
            static fn () => $recovery->requestCode(self::EMAIL),
            static fn () => $recovery->acceptCode(self::EMAIL, self::CODE),
            static fn () => $recovery->complete(self::EMAIL, self::PASSWORD),
        ] as $door) {
            try {
                $door();
                self::fail('The recovery service bypassed the reservation');
            } catch (ValidationException $failure) {
                self::assertSame(AccountMergeAccess::REFUSED_MESSAGE, $failure->getMessage());
            }
            self::assertSame($before, Database::sql('SELECT * FROM `hilos_user_verification`')->rows());
        }
    }

    /** Profile mutations refuse before consuming proof; showing the existing codes still proves and reads. */
    public function testCodesShowRemainsAReadWhileEnrollmentAndRenewalRefuseBeforeProof(): void
    {
        $now = TimeHelper::nowMs();
        Database::sql('INSERT INTO `hilos_second_factor` (`user_id`, `label`, `secret`, `confirmed_at`, `created_at`) VALUES (?, ?, ?, ?, ?)',
            [self::USER_ID, 'Existing app', 'JBSWY3DPEHPK3PXP', $now, $now]);
        foreach (['abcdefghjk', 'mnpqrstuvw'] as $code) {
            Database::sql('INSERT INTO `hilos_second_factor_backup_code` (`user_id`, `code`, `created_at`) VALUES (?, ?, ?)',
                [self::USER_ID, $code, $now]);
        }
        $this->confirmStepUp(StepUpOperationKey::ADD_AUTHENTICATOR_APP);
        $this->reserve();
        $beforeCodes = Database::sql('SELECT * FROM `hilos_second_factor_backup_code` ORDER BY `id`')->rows();
        $beforeFactors = Database::sql('SELECT * FROM `hilos_second_factor` ORDER BY `id`')->rows();
        foreach ([
            new ProfileSecondFactorEnrollStartActionDTO('abcdefghjk', true),
            new ProfileSecondFactorCodesRenewActionDTO('abcdefghjk', true),
        ] as $action) {
            $this->assertRefused(AccountMergeAccess::REFUSED_MESSAGE, $action->getAction(), $action);
            self::assertSame($beforeCodes, Database::sql('SELECT * FROM `hilos_second_factor_backup_code` ORDER BY `id`')->rows());
            self::assertSame($beforeFactors, Database::sql('SELECT * FROM `hilos_second_factor` ORDER BY `id`')->rows());
            self::assertSame([], self::takeSignals(), 'The mutating start must refuse before it asks for proof');
        }

        $show = new ProfileSecondFactorCodesShowActionDTO('abcdefghjk', true);
        $this->library->beginActionDispatch('merge-codes-show');
        try {
            $this->submit($show->getAction(), $show);
            self::assertTrue($this->library->actionReplyDeferred());
        } finally {
            $this->library->endActionDispatch();
        }
        $asks = self::named(self::takeSignals(), HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE);
        self::assertCount(1, $asks);
        $this->deliverToPerson($asks[0]);
        $answers = self::named(self::takeSignals(), HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE);
        self::assertCount(1, $answers);
        self::assertInstanceOf(AgentSignalData::class, $answers[0]->data);
        self::assertInstanceOf(UserSecondFactorProveDoneSignalData::class, $answers[0]->data->data);
        self::assertNull($answers[0]->data->data->error);
        $this->library->onSignalAgent($answers[0]->data, 'test', HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE);
        $acks = array_values(array_filter(self::takeSignals(), static fn (SignalDTO $signal): bool =>
            $signal->data instanceof WebSocketSignalData && $signal->data->data instanceof PageActionSuccessSignalData));
        self::assertCount(1, $acks);
        self::assertSame(self::ACCEPT_KEY, $acks[0]->data->targetAcceptKey);
        self::assertSame('merge-codes-show', $acks[0]->data->data->requestId);
        self::assertSame($show->getAction(), $acks[0]->data->data->action);
        self::assertSame([
            SecondFactorProfileReplyDTO::codes => [
                [SecondFactorProfileReplyDTO::code => 'abcde-fghjk', SecondFactorProfileReplyDTO::used => true],
                [SecondFactorProfileReplyDTO::code => 'mnpqr-stuvw', SecondFactorProfileReplyDTO::used => false],
            ],
        ], $acks[0]->data->data->reply);
        $afterCodes = Database::sql('SELECT * FROM `hilos_second_factor_backup_code` ORDER BY `id`')->rows();
        self::assertNotNull($afterCodes[0]['used_at']);
        $beforeCodes[0]['used_at'] = $afterCodes[0]['used_at'];
        self::assertSame($beforeCodes, $afterCodes);
        self::assertSame($beforeFactors, Database::sql('SELECT * FROM `hilos_second_factor` ORDER BY `id`')->rows());
    }

    /** @return AccountOperation Merge holding the existing profile account's protection */
    private function reserve(): AccountOperation
    {
        $operation = ExecutionContext::run(new ExecutionFrame(agentId: self::HOLDER_ID), static fn (): AccountOperation =>
            AccountMergeOpening::open(self::USER_ID, self::OTHER_USER_ID, new AccountMergeChoice(null, null, null, null)));
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id, AccountMergeStage::Preparing->value, AccountMergeDuty::PrepareSurvivor->value,
        );
        self::assertNotNull($assignment);
        $this->asPerson(self::USER_ID, static fn (AbstractUserAgent $agent) => $agent->prepareForMerge($assignment->id));
        self::takeSignals();

        return $operation;
    }

    /** @param AccountOperation $operation Reserved merge whose real carrier records its give fact */
    private function give(AccountOperation $operation): void
    {
        $assignment = ExecutionContext::run(
            new ExecutionFrame(agentId: self::HOLDER_ID),
            static function () use ($operation): AccountOperationAssignment {
                self::assertTrue($operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Go->value));

                return Hilos::$db->accountOperationAssignments->actions->record(
                    $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Give->value,
                    new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)self::OTHER_USER_ID),
                    self::OTHER_USER_ID, AccountOperationAssignmentPayload::fromJson('{}'),
                );
            },
        );
        $this->asPerson(self::OTHER_USER_ID, static fn () => AccountOperationParticipantStep::run(
            $assignment->id, static fn (): AccountOperationResultPayload => AccountOperationResultPayload::fromJson('{}'),
        ));
        self::takeSignals();
    }

    /** @return list<SignalDTO> Frames queued by one side of the exchange */
    private static function takeSignals(): array
    {
        $signals = [];
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $signals[] = $queued;
        }

        return $signals;
    }

    /**
     * @param list<SignalDTO> $signals Frames of one handler
     * @param string $name Signal being observed
     * @return list<SignalDTO> Frames with that name
     */
    private static function named(array $signals, string $name): array
    {
        return array_values(array_filter($signals, static fn (SignalDTO $signal): bool => $signal->signalName->getName() === $name));
    }

    /** @param bool $down Drop children before their parents */
    private static function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: migration stub names carry the direction only on down
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }
}
