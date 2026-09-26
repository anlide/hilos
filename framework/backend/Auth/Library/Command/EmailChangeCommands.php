<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\DTO\ProfileEmailChangeCurrentConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeNewConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeNewRequestActionDTO;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
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
use Hilos\Utils\Logger;
use Random\RandomException;

/**
 * Changing the signed-in person's email address, in four steps (HIL-299, HIL-1137).
 *
 * The four submits of one surface: a code to the address the account holds now, its check,
 * a code to the new address, and the move. The server keeps nothing between them - the
 * unspent code of the current address IS the proof the surface carries from step to step,
 * and it is spent only when the address actually moves.
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
     * cooldown is a silent success - the code already waiting in the mailbox is the one to
     * type - while the window cap is refused out loud, because the surface has a place for it.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the confirmation is missing, the account has no verified email, or the send cap is reached
     * @throws EmptyValueException When the current address is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws HilosException When a confirmation, verification or identity query fails
     */
    public function requestCurrentCode(string $acceptKey): void
    {
        $userId = $this->actingUser($acceptKey)->userId;
        $this->stepUp->require($acceptKey, StepUpOperationKey::CHANGE_EMAIL);
        $current = $this->requireCurrentEmail($userId);

        if (new VerificationService()->issue(VerificationType::EMAIL_CHANGE_CURRENT, $current, $userId)->capReached) {
            throw new ValidationException(AuthMessages::SEND_CAP);
        }
    }

    /**
     * Step 2: checks the current address's code WITHOUT spending it.
     *
     * The unspent code is itself the proof: the surface carries it into steps 3 and 4, and it
     * is spent only when the address moves. A wrong code still costs an attempt, as it does
     * everywhere, and a wrong and an expired one are answered alike.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileEmailChangeCurrentConfirmActionDTO $dto Code the current address received
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the confirmation is missing, the account has no verified email, or the code does not match
     * @throws HilosException When a confirmation, verification or identity query fails
     */
    public function confirmCurrentCode(string $acceptKey, ProfileEmailChangeCurrentConfirmActionDTO $dto): void
    {
        $userId = $this->actingUser($acceptKey)->userId;
        $this->stepUp->require($acceptKey, StepUpOperationKey::CHANGE_EMAIL);
        $current = $this->requireCurrentEmail($userId);

        if (!new VerificationService()->matchCode(VerificationType::EMAIL_CHANGE_CURRENT, $current, $dto->code)) {
            throw new ValidationException(AuthMessages::INVALID_CODE);
        }
    }

    /**
     * Step 3: mails a code to the new address.
     *
     * The new address is judged before anything else, and none of those refusals spends a
     * code: a malformed address, the account's own, and one another account holds - which is
     * never mailed at all (HIL-406: a stranger's address is not written to). The proof of the
     * current address is checked next without spending it, and its absence asks the person to
     * start over. Only then does the new address get the email-change code letter.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileEmailChangeNewRequestActionDTO $dto New address and the current address's code
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the confirmation is missing, the address is refused, the proof is gone,
     *     or the send cap is reached
     * @throws EmptyValueException When the new address is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws HilosException When a confirmation, verification or identity query fails
     */
    public function requestNewCode(string $acceptKey, ProfileEmailChangeNewRequestActionDTO $dto): void
    {
        $userId = $this->actingUser($acceptKey)->userId;
        $this->stepUp->require($acceptKey, StepUpOperationKey::CHANGE_EMAIL);
        $current = $this->requireCurrentEmail($userId);
        $email = $this->acceptNewEmail($userId, $current, $dto->email);
        $this->requireCurrentEmailProof($current, $dto->currentCode);

        if (new VerificationService()->issue(VerificationType::EMAIL_CHANGE, $email, $userId)->capReached) {
            throw new ValidationException(AuthMessages::SEND_CAP);
        }
    }

    /**
     * Step 4: proves the new address and moves the account onto it.
     *
     * The order is the contract. The address checks of step 3 run again - the address may have
     * gone to somebody else in between - and the proof of the current address is checked, both
     * spending nothing. The new address's code is spent next, so a typo in it leaves the proof
     * alive to try again. The proof is spent after it, and losing that race to another tab of
     * the same account is the start-over answer. Then one transaction moves every password and
     * sign-in-link row of the old address, and a unique-key clash there rolls it back.
     *
     * After the commit a notice goes to BOTH addresses, straight to each rather than through
     * the notification system, which resolves an address at delivery and would reach only the
     * new one. The change has happened by then, so a notice that cannot be queued is logged
     * rather than turned into a refusal the surface would show for a change it did make.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileEmailChangeNewConfirmActionDTO $dto New address, the code it received, and the current address's code
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the confirmation is missing, the address is refused, a code does not match,
     *     or the proof is gone
     * @throws HilosException When a confirmation, verification, identity, or transaction query fails
     */
    public function confirmNewCode(string $acceptKey, ProfileEmailChangeNewConfirmActionDTO $dto): void
    {
        $userId = $this->actingUser($acceptKey)->userId;
        $this->stepUp->require($acceptKey, StepUpOperationKey::CHANGE_EMAIL);
        $current = $this->requireCurrentEmail($userId);
        $email = $this->acceptNewEmail($userId, $current, $dto->email);
        $this->requireCurrentEmailProof($current, $dto->currentCode);

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
     * Checks the proof of the current address a later step carries, without spending it.
     *
     * Its absence is answered with the confirmation's own start-over sentence: the proof dies
     * three ways - its time ran out, another tab spent it, or the account's address already
     * moved - and none of them is a typo the person can fix on the spot.
     *
     * @param string $current Lowercased current address
     * @param string $code Code of the current address proven on step 2
     * @throws ValidationException When the proof is no longer alive or does not match
     * @throws HilosException When a verification query fails
     */
    private function requireCurrentEmailProof(string $current, string $code): void
    {
        $verifications = new VerificationService();
        if (
            !$verifications->hasActive(VerificationType::EMAIL_CHANGE_CURRENT, $current)
            || !$verifications->matchCode(VerificationType::EMAIL_CHANGE_CURRENT, $current, $code)
        ) {
            throw new ValidationException(StepUpMessages::EXPIRED);
        }
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
