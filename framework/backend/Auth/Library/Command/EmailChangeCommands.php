<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Code\DTO\CodeSendReplyDTO;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\DTO\ProfileEmailChangeCurrentConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeNewConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeNewRequestActionDTO;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Database;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\HilosMailer;
use Hilos\Mail\Template\EmailChangedMailTemplate;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use Hilos\Runtime\State\Item\HilosProfileFlow;
use Hilos\Utils\Logger;
use Random\RandomException;

/**
 * Changing the signed-in person's email address, in four steps (HIL-299, HIL-1137, HIL-1182).
 *
 * The four submits of one surface: a code to the address the account holds now, its check,
 * a code to the new address, and the move. What a step reached is remembered by the session
 * rather than by the tab: every step that lands is reported to the session holder
 * ({@see AbstractUsersLibraryAgent::announceProfileFlowStep()}), which writes it on the session's
 * record and moves the window in every tab of it. "The code of the current address matched" is
 * part of that record - the tab no longer carries the code - and the code itself stays unspent
 * until the address actually moves.
 *
 * Every step opens with the operation's confirmation ({@see StepUpOperationKey::CHANGE_EMAIL},
 * HIL-495), read through the same gate the library's own confirmation commands keep, and
 * only then reads the account. That is why the group is handed the step-up group rather than
 * asking it through the library: the gate is a collaborator of these commands, not a seam of
 * the project.
 *
 * It came off the chat demo whole (HIL-1137): the operation was declared by the framework,
 * and a project declaring the sign-in feature had no way to run it.
 */
final class EmailChangeCommands extends AbstractLibraryCommands
{
    /**
     * @param AbstractUsersLibraryAgent $library Users library executing the change
     * @param StepUpCommands $stepUp Operation-level confirmation gate every step opens with
     */
    public function __construct(
        AbstractUsersLibraryAgent $library,
        private readonly StepUpCommands $stepUp,
    ) {
        parent::__construct($library);
    }

    /**
     * Step 1: mails a code to the address the account holds now.
     *
     * Proving the current mailbox comes first because the flow runs inside a signed-in
     * session, and a session left open is exactly where somebody who is not the owner could
     * start it. The address is read from the account, never from the client. The send gate's
     * cooldown answers with the earlier code's lifetime and the next send moment, while the
     * window cap is refused out loud.
     *
     * The step lands on the session's record with the moment the live code dies - the new one,
     * or the one the cooldown left in play. A cooldown with no live code behind it leaves the
     * record as it was; the action reply still names the step's send pause.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @return CodeSendReplyDTO Send outcome and server moments
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the confirmation is missing, the account has no verified email, or the send cap is reached
     * @throws EmptyValueException When the current address is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws InvalidArgumentException When the step frame cannot be named or queued
     * @throws HilosException When a confirmation, verification or identity query fails
     */
    public function requestCurrentCode(string $acceptKey): CodeSendReplyDTO
    {
        $acting = $this->actingUser($acceptKey);
        $this->stepUp->require($acceptKey, StepUpOperationKey::CHANGE_EMAIL);
        $current = $this->requireCurrentEmail($acting->userId);

        $reply = $this->sendProfileCode($acting, StepUpOperationKey::CHANGE_EMAIL, VerificationType::EMAIL_CHANGE_CURRENT, $current);
        if ($reply->expiresAt === null) {
            return $reply;
        }

        $this->library->announceProfileFlowStep(
            $acting,
            StepUpOperationKey::CHANGE_EMAIL,
            HilosProfileFlow::STEP_CURRENT_SENT,
            $current,
            null,
            $reply->expiresAt,
            $reply,
        );
        return $reply;
    }

    /**
     * Step 2: checks the current address's code WITHOUT spending it.
     *
     * The match is the proof, and the session's record keeps it: steps 3 and 4 stand on the record
     * and on the code staying alive, and the code is spent only when the address moves. The record
     * copies the moment the matched code dies, which is how a later step tells it from a newer one.
     * A wrong code still costs an attempt, as it does everywhere, and a wrong and an expired one are
     * answered alike.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileEmailChangeCurrentConfirmActionDTO $dto Code the current address received
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the confirmation is missing, the account has no verified email, the code does not match,
     *     or it died the moment it matched
     * @throws InvalidArgumentException When the step frame cannot be named or queued
     * @throws HilosException When a confirmation, verification or identity query fails
     */
    public function confirmCurrentCode(string $acceptKey, ProfileEmailChangeCurrentConfirmActionDTO $dto): void
    {
        $acting = $this->actingUser($acceptKey);
        $this->stepUp->require($acceptKey, StepUpOperationKey::CHANGE_EMAIL);
        $current = $this->requireCurrentEmail($acting->userId);

        $verifications = new VerificationService();
        if (!$verifications->matchCode(VerificationType::EMAIL_CHANGE_CURRENT, $current, $dto->code)) {
            throw new ValidationException(AuthMessages::INVALID_CODE);
        }

        $this->library->announceProfileFlowStep(
            $acting,
            StepUpOperationKey::CHANGE_EMAIL,
            HilosProfileFlow::STEP_CURRENT_PROVEN,
            $current,
            null,
            $verifications->activeExpiresAt(VerificationType::EMAIL_CHANGE_CURRENT, $current)
                ?? throw new ValidationException(StepUpMessages::EXPIRED),
        );
    }

    /**
     * Step 3: mails a code to the new address.
     *
     * The new address is judged before anything else, and none of those refusals spends a
     * code: a malformed address, the account's own, and one another account holds - which is
     * never mailed at all (HIL-406: a stranger's address is not written to). The proof of the
     * current address is checked next on the session's record, without spending it, and its
     * absence asks the person to start over. Only then does the new address get the
     * email-change code letter. The record moves on with the new address beside it, still
     * standing on the current address's code: that is the proof step 4 needs.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileEmailChangeNewRequestActionDTO $dto New address
     * @return CodeSendReplyDTO Send outcome and server moments
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the confirmation is missing, the address is refused, the proof is gone,
     *     or the send cap is reached
     * @throws EmptyValueException When the new address is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws InvalidArgumentException When the step frame cannot be named or queued
     * @throws HilosException When a confirmation, verification or identity query fails
     */
    public function requestNewCode(string $acceptKey, ProfileEmailChangeNewRequestActionDTO $dto): CodeSendReplyDTO
    {
        $acting = $this->actingUser($acceptKey);
        $this->stepUp->require($acceptKey, StepUpOperationKey::CHANGE_EMAIL);
        $current = $this->requireCurrentEmail($acting->userId);
        $email = $this->acceptNewEmail($acting->userId, $current, $dto->email);
        $flow = $this->requireProfileFlow(
            $acting,
            StepUpOperationKey::CHANGE_EMAIL,
            [HilosProfileFlow::STEP_CURRENT_PROVEN, HilosProfileFlow::STEP_NEW_SENT],
            VerificationType::EMAIL_CHANGE_CURRENT,
            $current,
        );

        $reply = $this->sendProfileCode($acting, StepUpOperationKey::CHANGE_EMAIL, VerificationType::EMAIL_CHANGE, $email);

        $this->library->announceProfileFlowStep(
            $acting,
            StepUpOperationKey::CHANGE_EMAIL,
            HilosProfileFlow::STEP_NEW_SENT,
            $current,
            $email,
            $flow->expiresAt,
            $reply,
        );
        return $reply;
    }

    /**
     * Step 4: proves the new address and moves the account onto it.
     *
     * The order is the contract. The proof of the current address is checked on the session's
     * record, which also names the new address - the tab sends only the code that address
     * received. The address checks of step 3 run again - the address may have gone to somebody
     * else in between - spending nothing. The new address's code is spent next, so a typo in it
     * leaves the proof alive to try again. The proof is spent after it, and losing that race to
     * another tab of the same account is the start-over answer. Then one transaction moves every
     * password and sign-in-link row of the old address, and a unique-key clash there rolls it back.
     *
     * After the commit a notice goes to BOTH addresses, straight to each rather than through
     * the notification system, which resolves an address at delivery and would reach only the
     * new one. The change has happened by then, so a notice that cannot be queued is logged
     * rather than turned into a refusal the surface would show for a change it did make. The
     * flow is over last, and the session's record goes with it.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileEmailChangeNewConfirmActionDTO $dto The code the new address received
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the confirmation is missing, the address is refused, a code does not match,
     *     or the proof is gone
     * @throws InvalidArgumentException When the step frame cannot be named or queued
     * @throws HilosException When a confirmation, verification, identity, or transaction query fails
     */
    public function confirmNewCode(string $acceptKey, ProfileEmailChangeNewConfirmActionDTO $dto): void
    {
        $acting = $this->actingUser($acceptKey);
        $userId = $acting->userId;
        $this->stepUp->require($acceptKey, StepUpOperationKey::CHANGE_EMAIL);
        $current = $this->requireCurrentEmail($userId);
        $flow = $this->requireProfileFlow(
            $acting,
            StepUpOperationKey::CHANGE_EMAIL,
            [HilosProfileFlow::STEP_NEW_SENT],
            VerificationType::EMAIL_CHANGE_CURRENT,
            $current,
        );
        $email = $this->acceptNewEmail($userId, $current, $flow->target ?? throw new ValidationException(StepUpMessages::EXPIRED));

        $verifications = new VerificationService();
        if ($verifications->verify(VerificationType::EMAIL_CHANGE, $email, $dto->code) !== $userId) {
            throw new ValidationException(AuthMessages::INVALID_CODE);
        }

        if (!$verifications->consumeActive(VerificationType::EMAIL_CHANGE_CURRENT, $current)) {
            throw new ValidationException(StepUpMessages::EXPIRED);
        }

        Database::transactionStart();
        try {
            Hilos::$db->identities->changeEmail($userId, $current, $email);
            Database::transactionCommit();
        } catch (DuplicateValueException | DuplicateEntryException) {
            $this->rollBack();
            throw new ValidationException(AuthMessages::EMAIL_IN_USE);
        } catch (HilosException $failure) {
            $this->rollBack();
            throw $failure;
        }

        foreach ([$current, $email] as $address) {
            try {
                Hilos::$mail?->send(new MailSendSignalData(
                    to: $address,
                    shardKey: HilosMailer::shardKeyForAddress($address),
                    templateKey: MailTemplateCatalogConstants::ACCOUNT_EMAIL_CHANGED,
                    params: [EmailChangedMailTemplate::PARAM_WAS => $current, EmailChangedMailTemplate::PARAM_NOW => $email],
                ));
            } catch (HilosException $failure) {
                Logger::logAgentError(
                    $this->library->getId(),
                    "Email change notice for user {$userId} could not be queued: {$failure->getMessage()}",
                );
            }
        }

        $this->library->announceProfileFlowStep($acting, StepUpOperationKey::CHANGE_EMAIL, null);
    }

    /**
     * Reads the address an email change starts from, or refuses the change.
     *
     * The same first verified email the profile draws in its Email row; an account without
     * one has no current mailbox to prove, and adding an address is a different flow.
     *
     * @param int $userId Acting person's account
     * @return string Lowercased current address
     * @throws ValidationException When the account has no verified email
     * @throws HilosException When the identity query fails
     */
    private function requireCurrentEmail(int $userId): string
    {
        return Hilos::$db->identities->findVerifiedEmailByUser($userId)
            ?? throw new ValidationException(AuthMessages::CONFIRM_EMAIL_FIRST);
    }

    /**
     * Normalizes the new address of an email change and refuses one it cannot move to.
     *
     * Nothing here spends a code: none of these answers could have been changed by one.
     *
     * @param int $userId Acting person's account
     * @param string $current Lowercased current address
     * @param string $submitted Submitted new address (trimmed)
     * @return string Lowercased new address
     * @throws ValidationException When the address is malformed, already the account's, or another account's
     * @throws HilosException When the identity query fails
     */
    private function acceptNewEmail(int $userId, string $current, string $submitted): string
    {
        $email = strtolower($submitted);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException(AuthMessages::INVALID_EMAIL);
        }

        $ownerId = Hilos::$db->identities->findAccountIdByEmail($email);
        if ($email === $current || $ownerId === $userId) {
            throw new ValidationException(AuthMessages::ALREADY_YOUR_ADDRESS);
        }
        if ($ownerId !== null) {
            throw new ValidationException(AuthMessages::EMAIL_IN_USE);
        }

        return $email;
    }

    /**
     * Rolls back a failed email change without letting the cleanup replace the failure.
     *
     * The connection under the transaction belongs to the worker and outlives the action, so
     * a transaction left open would take in every later write that worker makes.
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
