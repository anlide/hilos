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

        $email = Hilos::$db->identities->findVerifiedEmailByUser($userId);
        if ($email !== null && new CodeDeliveryAvailability()->canDeliverTo(IdentifierDetection::KIND_EMAIL)) {
            return new StepUpTarget(StepUpMethod::EMAIL_CODE, $email);
        }

        $phone = Hilos::$db->identities->findVerifiedSmsByUser($userId);
        if ($phone !== null && new CodeDeliveryAvailability()->canDeliverTo(IdentifierDetection::KIND_PHONE)) {
            return new StepUpTarget(StepUpMethod::SMS_CODE, $phone);
        }

        if (Hilos::$db->passkeyCredentials->listByUser($userId) !== []) {
            return new StepUpTarget(StepUpMethod::PASSKEY);
        }

        return null;
    }
}
