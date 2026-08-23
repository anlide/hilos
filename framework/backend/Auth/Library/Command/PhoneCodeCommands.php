<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Code\AuthCodeAgent;
use Hilos\Auth\Code\DTO\AuthCodeSendSignalData;
use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\DTO\ConfirmPhoneCodeActionDTO;
use Hilos\Auth\Library\DTO\RequestPhoneCodeActionDTO;
use Hilos\Auth\PhoneNumber;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * Signing in with a number and a code sent to it (HIL-280, HIL-622).
 *
 * TWO submits, and the second one is a login and a registration at once: the code proves
 * the number, and what that means is decided by the state the number is in - an `sms`
 * identity makes it a sign-in, no identity makes it the ending of a registration. The
 * magic link decides the same question the same way about an address, which is why the
 * two groups read alike and still stay apart: what a phone costs to reach, and who can be
 * asked to reach it, has nothing to do with a letter.
 *
 * The send itself is not here and cannot be: asking a messenger whether it can reach a
 * number is a round trip, and this process answers every sign-in of the deployment. The
 * request is handed to {@see AuthCodeAgent} and this group answers only "accepted".
 */
final class PhoneCodeCommands extends AbstractLibraryCommands
{
    /**
     * Hands a phone one-time-code request to the code agent, answering only "accepted".
     *
     * The additive phone-login entry (HIL-280), turned asynchronous for every channel
     * (HIL-492). What stays here is exactly what costs nothing: the number has to be a
     * number, the channel has to be one this project registered, and it has to serve
     * phone identifiers at all. Those are the answers a crafted payload deserves
     * immediately, and none of them touches the network.
     *
     * Everything that can block moves to {@see AuthCodeAgent} - asking a messenger
     * whether it can reach the number is a round-trip, and no process that answers
     * commands may wait on one. So the outcome does NOT ride this ack: the auto-sent
     * `action_success` means "accepted, working", and the code screen opens later on the
     * agent's result signal, which is also what names the channel the code really went
     * over.
     *
     * A well-formed request is handed over whether or not the number has an `sms`
     * identity - the account is find-or-created on confirm, so accepting
     * unconditionally reveals nothing about who has one. The send gate (HIL-421)
     * answers inside the agent, on the same (type, identifier) key as before.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param RequestPhoneCodeActionDTO $dto Parsed request payload (phone, channel)
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When the phone number is malformed or the channel cannot serve it
     * @throws InvalidArgumentException When the code-request signal cannot be named or queued
     * @throws HilosException When the project's channel registry cannot be resolved
     */
    public function requestPhoneCode(string $acceptKey, RequestPhoneCodeActionDTO $dto): void
    {
        $acting = $this->acting($acceptKey);

        $phone = PhoneNumber::normalize($dto->phone);
        if ($phone === null) {
            throw new ValidationException(AuthMessages::INVALID_PHONE);
        }

        $channel = Hilos::codeChannelRegistryClass()::get($dto->channel);
        if ($channel === null
            || !in_array(IdentifierDetection::KIND_PHONE, $channel->identifierKinds(), true)
            || !$channel->supportsType(VerificationType::SMS_LOGIN)) {
            throw new ValidationException(AuthMessages::UNKNOWN_CHANNEL);
        }

        // The accept key is taken from the live connection and never from the client: it
        // is the only address the outcome has, since the person asking has no account to
        // fan out to (the shape the OAuth callback established, HIL-281). The session
        // rides along for the memory the agent writes when a code really goes out
        // (HIL-486): that one outlives the socket, which is the point of it.
        $this->library->sendToAgent(
            HilosSignalConstants::HILOS_AUTH_CODE_SEND,
            new AuthCodeSendSignalData(
                $acting->acceptKey,
                $acting->sessionToken,
                $phone,
                $channel->name(),
                VerificationType::SMS_LOGIN,
            ),
        );
    }

    /**
     * Verifies a phone login code and signs the number in, registering it if new.
     *
     * Single login+register flow (HIL-280): a missing/expired/wrong code — and a
     * malformed phone — fail with the same generic message (no enumeration). On a
     * valid code the code is single-use consumed inside the service, and what happens
     * next is decided by the state the NUMBER is in, exactly as the magic link decides
     * it by the state of the address (HIL-417): an `sms` identity resolves its user and
     * this is a sign-in; no identity means the code was proving a registration, and a
     * registration needs the hold that was put on the number when the code went out
     * (HIL-486).
     *
     * A missing hold OF THIS BROWSER is answered as such rather than as a bad code
     * (HIL-608): the number was free when the person asked and this session is not
     * holding it now, so either their wait ran out or somebody else registered it —
     * and "invalid code" would read as a typo they did not make. Somebody else's hold
     * is not this session's to land, which is the same rule the address obeys.
     *
     * Both endings belong to the session holder, so neither answers from here: a
     * sign-in leaves in a grant frame and a landing in the registration frame that
     * {@see AbstractLibraryCommands::landRegistration()} sends. The converge that
     * reaches every OTHER session on this number rides the second one - the browsers
     * racing it are told the number is taken by the process that can see them.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ConfirmPhoneCodeActionDTO $dto Parsed confirm payload (phone, code)
     * @return ?AuthFlowOutcome The rollback to the identifier step, or null when the session holder answers
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When the phone or code is invalid
     * @throws EmptyValueException When the display name the new account is created with is empty
     * @throws InvalidFormatException When the proven number is not a valid identifier
     * @throws InvalidArgumentException When the hand-off frame cannot be named or queued
     * @throws HilosException When verification, the account, identity, or reservation write fails
     */
    public function confirmPhoneCode(string $acceptKey, ConfirmPhoneCodeActionDTO $dto): ?AuthFlowOutcome
    {
        $acting = $this->acting($acceptKey);

        $phone = PhoneNumber::normalize($dto->phone);
        if ($phone === null
            || !new VerificationService()->verifyCode(VerificationType::SMS_LOGIN, $phone, $dto->code)) {
            throw new ValidationException(AuthMessages::INVALID_CODE);
        }

        $identity = Hilos::$db->identities->findByIdentity(IdentityType::SMS, $phone);
        if ($identity !== null && $identity->userId !== null) {
            $this->library->grantSession(
                $acting,
                $identity->userId,
                null,
                AuthFlowOutcome::moveTo(AuthFlowStep::DONE, AuthFlowIntent::LOGIN),
            );

            return null;
        }

        $held = new RegistrationReservationService()->findActiveForSession($acting->sessionToken);
        if ($held?->identifier !== $phone) {
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_RESERVATION_EXPIRED,
                AuthFlowStep::IDENTIFIER,
                AuthFlowIntent::REGISTER,
                AuthMessages::RESERVATION_EXPIRED,
            );
        }

        return $this->landRegistration($acting, $phone, $phone);
    }
}
