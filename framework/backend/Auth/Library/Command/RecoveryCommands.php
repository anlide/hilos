<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\DTO\CompletePasswordResetActionDTO;
use Hilos\Auth\Library\DTO\ConfirmPasswordResetActionDTO;
use Hilos\Auth\Library\DTO\RequestPasswordResetActionDTO;
use Hilos\Auth\PasswordPolicy;
use Hilos\Auth\Recovery\PasswordRecoveryService;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Random\RandomException;

/**
 * Getting an account back when its password is gone (HIL-416, HIL-622).
 *
 * THREE submits and the split between them is the design: ask for a code, prove the
 * code, save the password. The middle one deliberately does not spend the code and does
 * not set anything - the password is not on the wire yet - it hands out a grant, and the
 * last one is the only step that writes a secret.
 *
 * The grant is written on the SESSION rather than on the socket that earned it, which is
 * what makes every tab of the browser move to the password step together and no other
 * device move at all.
 */
final class RecoveryCommands extends AbstractLibraryCommands
{
    /**
     * Issues a password-reset code, or says there is no password to reset.
     *
     * The second anti-enumeration stub the identifier-first epic removed (HIL-414): an
     * address with no password used to be answered with the same silent success as a real
     * one, so somebody whose account was built by a link, a provider or a phone waited for
     * a letter that was never sent. It refuses out loud now, and the constant-time dummy
     * hash that hid the difference went with it. Nothing is disclosed by that which the
     * lookup in front of the form does not already answer.
     *
     * It answers the send gate's verdict (HIL-421): the moment the button comes back, or a
     * refusal when too many codes already went to this address. The surface moves nowhere
     * either way - the person is on the screen the code belongs to.
     *
     * The connection is parked as a recovery waiter before returning, and that is also
     * what makes a second device cheap: the cooldown answers it with a countdown off the
     * code that is already in the inbox rather than mailing another, and the same call
     * puts it where the converge broadcast can reach it. Nothing is parked for a send the
     * cap refused - no code went out for that session to wait on.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param RequestPasswordResetActionDTO $dto Parsed request payload (email)
     * @return AuthFlowOutcome Moments the resend gate opens and the code dies, or the cap refusal
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When no account at the address has a password
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws InvalidArgumentException When the hand-off frame cannot be named or queued
     * @throws HilosException When identity lookup, code issuing, or the runtime write fails
     */
    public function requestPasswordReset(string $acceptKey, RequestPasswordResetActionDTO $dto): AuthFlowOutcome
    {
        $acting = $this->acting($acceptKey);

        $email = strtolower($dto->email);
        $outcome = new PasswordRecoveryService()->requestCode($email);
        if ($outcome === null) {
            throw new ValidationException(AuthMessages::NO_PASSWORD_TO_RESET);
        }

        if ($outcome->capReached) {
            return AuthFlowOutcome::refuse(AuthFlowOutcome::CODE_SEND_CAP_REACHED, AuthMessages::SEND_CAP);
        }

        // Two halves of one park since HIL-685: this library may add the row and may not
        // edit one, so the frame is what re-points a tab that asked for a second code on
        // another address. The answer below is still the library's - it never stood on
        // the row, which exists for the OTHER tabs of this session.
        Hilos::$rt->hilosRecoveryWaiters->actions->park($acting->acceptKey, $email, $acting->sessionToken);
        $this->library->announceRecoveryWaitMoved($acting, $email);

        return AuthFlowOutcome::sent(
            $outcome->resendAt(),
            new VerificationService()->activeExpiresAt(VerificationType::PASSWORD_RESET, $email),
        );
    }

    /**
     * Accepts a password-reset code and opens the password step for that session.
     *
     * It proves the code WITHOUT spending it, so the grant it hands out has something
     * behind it when the second submit arrives ({@see completePasswordReset()}).
     *
     * Three answers, and the middle one is why the code screen is not a dead end: a
     * recovery whose code is no longer live rolls the surface back to the address field
     * with a reason of its own, while a wrong code is an inline error that leaves the
     * person exactly where they are to try again. The order matters - a challenge that is
     * already gone is answered before a code is judged against it, so a stale screen is
     * never told it made a typo.
     *
     * The grant is written for THIS address only - a session with a second tab parked on
     * another address must not have that one opened by a code proven here - and it is
     * written by the session holder rather than here (HIL-685): it edits parked rows,
     * which this library may add and remove but not amend, and the holder is already
     * walking those very rows to converge the session's other tabs. Making sure the
     * initiator has a row at all, which a browser that reconnected between the code and
     * this submit would have lost, moved with it.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ConfirmPasswordResetActionDTO $dto Parsed confirm payload (email, code)
     * @return ?AuthFlowOutcome The rollback to the address field, or null when the session holder answers
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When the submitted code does not match the live one
     * @throws InvalidArgumentException When the hand-off frame cannot be named or queued
     * @throws HilosException When verification or the runtime write fails
     */
    public function confirmPasswordReset(string $acceptKey, ConfirmPasswordResetActionDTO $dto): ?AuthFlowOutcome
    {
        $acting = $this->acting($acceptKey);

        $email = strtolower($dto->email);
        $recovery = new PasswordRecoveryService();

        if (!$recovery->hasLiveCode($email)) {
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_RESET_CODE_EXPIRED,
                AuthFlowStep::IDENTIFIER,
                AuthFlowIntent::RECOVERY,
                AuthMessages::RESET_CODE_EXPIRED,
            );
        }

        if (!$recovery->acceptCode($email, $dto->code)) {
            throw new ValidationException(AuthMessages::INVALID_CODE);
        }

        $this->library->announceRecoveryGranted(
            $acting,
            $email,
            AuthFlowOutcome::moveTo(AuthFlowStep::SET_PASSWORD, AuthFlowIntent::RECOVERY),
        );

        return null;
    }

    /**
     * Saves the new password of an accepted recovery and returns the account whole.
     *
     * The address comes off the grant and never off the payload - a password screen that
     * could name an account would be a way to reset somebody else's - then the code is
     * spent and the secret is written. Everything after that is the session holder's:
     * signing this browser in, telling the tabs that were waiting on the address, and
     * logging the account's OTHER sessions out.
     *
     * Two ways it does not go through, both answered by a move rather than an error: no
     * grant on this session - the code expired, or the browser came back to a screen whose
     * recovery is long over - and a grant that lost the race, which is what the single-use
     * code makes of a second device saving second. The winner has already changed the
     * password by then, so the loser is sent to sign in with it.
     *
     * The force-logout is the point of resetting a password at all: it is done when access
     * has leaked, so the reset takes the account back rather than adding one more live
     * session to it.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param CompletePasswordResetActionDTO $dto Parsed complete payload (password)
     * @return ?AuthFlowOutcome The rollback the losing session gets, or null when the session holder answers
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When the new password is too short
     * @throws InvalidArgumentException When the hand-off frame cannot be named or queued
     * @throws HilosException When the secret write or the runtime read fails
     */
    public function completePasswordReset(string $acceptKey, CompletePasswordResetActionDTO $dto): ?AuthFlowOutcome
    {
        $acting = $this->acting($acceptKey);

        $email = $this->grantedRecoveryIdentifier($acting->sessionToken);
        if ($email === null) {
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_RESET_CODE_EXPIRED,
                AuthFlowStep::IDENTIFIER,
                AuthFlowIntent::RECOVERY,
                AuthMessages::RESET_CODE_EXPIRED,
            );
        }

        if (strlen($dto->password) < PasswordPolicy::MIN_LENGTH) {
            throw new ValidationException('Password must be at least ' . PasswordPolicy::MIN_LENGTH . ' characters');
        }

        $userId = new PasswordRecoveryService()->complete($email, $dto->password);
        if ($userId === null) {
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_PASSWORD_ALREADY_CHANGED,
                AuthFlowStep::IDENTIFIER,
                AuthFlowIntent::LOGIN,
                AuthMessages::PASSWORD_ALREADY_CHANGED,
            );
        }

        // The ack goes on the sockets BEFORE the sign-in, and the sign-in rotates the token
        // the force-logout must keep - both of which are the holder's to order (HIL-422,
        // HIL-582). It is told what happened, not what to do in which order.
        $this->library->announcePasswordChanged(
            $acting,
            $userId,
            $email,
            AuthFlowOutcome::moveTo(AuthFlowStep::DONE, AuthFlowIntent::RECOVERY),
        );

        return null;
    }

    /**
     * The address a session may currently set a password for, if any.
     *
     * The whole of what the password screen is allowed to act on. A session holds a grant
     * only after one of its tabs proved the code, and the address is read off that row
     * rather than off the submit, so the payload carries a password and nothing that could
     * point it at another account.
     *
     * @param string $sessionToken Session token asking to save a password
     * @return ?string Address whose recovery this session may finish, or null when it holds no grant
     * @throws HilosException When the runtime read fails
     */
    private function grantedRecoveryIdentifier(string $sessionToken): ?string
    {
        foreach (Hilos::$rt->hilosRecoveryWaiters->forSessionToken($sessionToken) as $waiter) {
            if ($waiter->codeAccepted) {
                return $waiter->identifier;
            }
        }

        return null;
    }
}
