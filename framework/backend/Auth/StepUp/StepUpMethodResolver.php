<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp;

use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Auth\Verification\CodeDeliveryAvailability;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;

/**
 * Chooses the strongest available proof for operation-level step-up (HIL-495).
 *
 * The choice belongs to the application, not to a menu. It is derived from proofs the
 * account owns, independent of the method the current session used to sign in.
 */
final class StepUpMethodResolver
{
    /**
     * @param int $userId Person whose available proofs are inspected
     * @return ?StepUpTarget Selected method and destination, or null when the account has no usable proof
     * @throws DatabaseException When an account-proof lookup fails
     * @throws InvalidArgumentException When a collection query or loaded object is invalid
     * @throws LogicException When collection metadata is incomplete
     */
    public function resolve(int $userId): ?StepUpTarget
    {
        if (Hilos::$db->secondFactors->confirmedOf($userId) !== []) {
            return new StepUpTarget(StepUpMethod::SECOND_FACTOR);
        }

        if (Hilos::$db->identities->findPasswordByUser($userId) !== null) {
            return new StepUpTarget(StepUpMethod::PASSWORD);
        }

        $address = $this->resolveAddress($userId);
        if ($address !== null) {
            return $address;
        }

        if (Hilos::$db->passkeyCredentials->listByUser($userId) !== []) {
            return new StepUpTarget(StepUpMethod::PASSKEY);
        }

        return null;
    }

    /**
     * Chooses the address a one-time code for this person goes to.
     *
     * The confirmed email when this installation can send letters, otherwise the confirmed
     * phone when it can send texts. The step-up asks it in its own place of the order, and an
     * operation that confirms itself with a code of its own - deleting the account (HIL-302) -
     * asks it directly, so "where does the code go" is decided in one place.
     *
     * @param int $userId Person whose addresses are inspected
     * @return ?StepUpTarget EMAIL_CODE or SMS_CODE with the full address, or null when no code can reach the person
     * @throws DatabaseException When an identity lookup fails
     * @throws InvalidArgumentException When a collection query or loaded object is invalid
     * @throws LogicException When collection metadata is incomplete
     */
    public function resolveAddress(int $userId): ?StepUpTarget
    {
        $email = Hilos::$db->identities->findVerifiedEmailByUser($userId);
        if ($email !== null && new CodeDeliveryAvailability()->canDeliverTo(IdentifierDetection::KIND_EMAIL)) {
            return new StepUpTarget(StepUpMethod::EMAIL_CODE, $email);
        }

        $phone = Hilos::$db->identities->findVerifiedSmsByUser($userId);
        if ($phone !== null && new CodeDeliveryAvailability()->canDeliverTo(IdentifierDetection::KIND_PHONE)) {
            return new StepUpTarget(StepUpMethod::SMS_CODE, $phone);
        }

        return null;
    }
}
