<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp;

use Hilos\Auth\Impersonation\ImpersonationSettings;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Server-side gate asked before every action of a protected operation (HIL-495).
 *
 * Inside a takeover an operation of the person's own account is closed - unless it touches the
 * sign-in ({@see StepUpOperation::$accountAccess}) and the administrator allowed that
 * ({@see ImpersonationSettings::allowsAccountAccess()}). Then it is the ADMINISTRATOR of this
 * browser who confirms it, by the method their own account can prove, never the person whose
 * account it is (HIL-1170, docs/agents/architecture/step-up.md): the threat the step guards against
 * is somebody else at the administrator's browser. The operation's own first step proves the
 * person's address or app, not the administrator, so inside a takeover it suppresses nothing.
 */
final class StepUpGate
{
    public const string VERDICT_PASS = 'pass';
    public const string VERDICT_ASK = 'ask';
    public const string VERDICT_IMPERSONATED = 'impersonated';

    /**
     * Whether an administrator works in someone else's account through this session.
     *
     * The verdict asks it first; an account command that stands outside the gate on purpose -
     * calling off one's own account deletion needs no fresh proof (HIL-302) - asks it alone,
     * because what a person does to their own account is never done with someone else's hands.
     *
     * @param string $sessionToken Browser session token
     * @return bool True when the session row names an impersonator
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When the loaded object type does not match the collection
     * @throws DatabaseException When the token lookup or lazy session load fails
     */
    public static function isImpersonated(string $sessionToken): bool
    {
        return Hilos::$db->sessions->findByToken($sessionToken)?->impersonatorUserId !== null;
    }

    /**
     * @param string $sessionToken Browser session token
     * @param int $userId Acting person
     * @param string $operation Declared operation key
     * @return string One of the VERDICT_* constants
     * @throws InvalidArgumentException When the operation is unknown or a collection query is invalid
     * @throws LogicException When collection metadata is incomplete
     * @throws DatabaseException When the session, setting, proof or confirmation cannot be read
     * @throws SettingException When the step-up setting catalog or value is invalid
     */
    public function verdict(string $sessionToken, int $userId, string $operation): string
    {
        $directory = Hilos::stepUpOperationDirectoryClass();
        $declaredOperation = $directory::get($operation);

        $impersonatorUserId = Hilos::$db->sessions->findByToken($sessionToken)?->impersonatorUserId;
        if ($impersonatorUserId !== null) {
            if (!self::opensInsideTakeover($declaredOperation)) {
                return self::VERDICT_IMPERSONATED;
            }
            $userId = $impersonatorUserId;
        }

        if (!StepUpSettings::isEnabled($operation)) {
            return self::VERDICT_PASS;
        }

        if (Hilos::$db->stepUps->isConfirmed(ProtectedModeRuntime::hashSessionToken($sessionToken), $userId, $operation)) {
            return self::VERDICT_PASS;
        }

        $target = new StepUpMethodResolver()->resolve($userId);
        if ($target === null && $declaredOperation->passesWithNothingToConfirm) {
            return self::VERDICT_PASS;
        }
        if ($impersonatorUserId !== null) {
            return self::VERDICT_ASK;
        }
        if ($declaredOperation->opensWithAddressCode && ($target?->method === StepUpMethod::EMAIL_CODE
            || $target?->method === StepUpMethod::SMS_CODE)) {
            return self::VERDICT_PASS;
        }
        // The operation's own step asks the app code (HIL-1138).
        if ($declaredOperation->opensWithSecondFactorProof && $target?->method === StepUpMethod::SECOND_FACTOR) {
            return self::VERDICT_PASS;
        }

        return self::VERDICT_ASK;
    }

    /**
     * Who confirms an operation on this session: the person, or the administrator of a takeover allowed to touch the sign-in (HIL-1170).
     *
     * The step's own commands ask it, so that what they send, check and record - the code, the
     * password, the device key, the confirmation row - is the administrator's inside such a takeover.
     *
     * @param string $sessionToken Browser session token
     * @param int $userId Person the session acts as
     * @param string $operation Declared operation key
     * @return int The person, or the administrator behind the takeover
     * @throws InvalidArgumentException When the operation is unknown or a collection query is invalid
     * @throws LogicException When collection metadata is incomplete
     * @throws DatabaseException When the session or the setting cannot be read
     * @throws SettingException When the impersonation setting catalog or value is invalid
     */
    public static function confirmer(string $sessionToken, int $userId, string $operation): int
    {
        $impersonatorUserId = Hilos::$db->sessions->findByToken($sessionToken)?->impersonatorUserId;
        if ($impersonatorUserId === null || !self::opensInsideTakeover(Hilos::stepUpOperationDirectoryClass()::get($operation))) {
            return $userId;
        }

        return $impersonatorUserId;
    }

    /**
     * @param StepUpOperation $operation Declared operation
     * @return bool Whether a takeover may run it: it touches the sign-in and the administrator allowed that
     * @throws DatabaseException When the setting cannot be read
     * @throws SettingException When the impersonation setting catalog or value is invalid
     */
    private static function opensInsideTakeover(StepUpOperation $operation): bool
    {
        return $operation->accountAccess && ImpersonationSettings::allowsAccountAccess();
    }

    /**
     * Refuses an impersonated session or one without a live confirmation.
     *
     * @param string $sessionToken Browser session token
     * @param int $userId Acting person
     * @param string $operation Declared operation key
     * @throws ValidationException When a takeover may not run the operation or confirmation is absent or expired
     * @throws InvalidArgumentException When the operation is unknown or a collection query is invalid
     * @throws LogicException When collection metadata is incomplete
     * @throws DatabaseException When the session, setting, proof or confirmation cannot be read
     * @throws SettingException When the step-up setting catalog or value is invalid
     */
    public function require(string $sessionToken, int $userId, string $operation): void
    {
        $verdict = $this->verdict($sessionToken, $userId, $operation);
        if ($verdict === self::VERDICT_IMPERSONATED) {
            throw new ValidationException(StepUpMessages::IMPERSONATED);
        }
        if ($verdict === self::VERDICT_ASK) {
            throw new ValidationException(StepUpMessages::EXPIRED);
        }
    }
}
