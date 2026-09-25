<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Server-side gate asked before every action of a protected operation (HIL-495).
 */
final class StepUpGate
{
    public const string VERDICT_PASS = 'pass';
    public const string VERDICT_ASK = 'ask';
    public const string VERDICT_IMPERSONATED = 'impersonated';

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
        $session = Hilos::$db->sessions->findByToken($sessionToken);

        if ($session?->impersonatorUserId !== null) {
            return self::VERDICT_IMPERSONATED;
        }

        if (!StepUpSettings::isEnabled($operation)) {
            return self::VERDICT_PASS;
        }

        if (Hilos::$db->stepUps->isConfirmed(ProtectedModeRuntime::hashSessionToken($sessionToken), $userId, $operation)) {
            return self::VERDICT_PASS;
        }

        $target = new StepUpMethodResolver()->resolve($userId);
        if ($declaredOperation->opensWithAddressCode && ($target?->method === StepUpMethod::EMAIL_CODE
            || $target?->method === StepUpMethod::SMS_CODE)) {
            return self::VERDICT_PASS;
        }

        return self::VERDICT_ASK;
    }

    /**
     * Refuses an impersonated session or one without a live confirmation.
     *
     * @param string $sessionToken Browser session token
     * @param int $userId Acting person
     * @param string $operation Declared operation key
     * @throws ValidationException When impersonation is active or confirmation is absent or expired
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
