<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Hilos;

/**
 * The code-side directory of operations a project protects with step-up (HIL-495).
 *
 * The framework declares its three account operations. A project points
 * {@see Hilos::STEP_UP_OPERATION_DIRECTORY} at a subclass and appends its own entries.
 * The order is the order of the administration table.
 */
abstract class StepUpOperationDirectory
{
    /**
     * @return array<string, StepUpOperation> Operations keyed by their stable key
     * @throws InvalidArgumentException When a framework operation key is malformed
     */
    protected static function operations(): array
    {
        return [
            StepUpOperationKey::CHANGE_PASSWORD => new StepUpOperation(
                StepUpOperationKey::CHANGE_PASSWORD,
                'Change password',
                'change your password',
                true,
            ),
            StepUpOperationKey::CHANGE_EMAIL => new StepUpOperation(
                StepUpOperationKey::CHANGE_EMAIL,
                'Change email',
                'change your email',
                true,
            ),
            StepUpOperationKey::DELETE_ACCOUNT => new StepUpOperation(
                StepUpOperationKey::DELETE_ACCOUNT,
                'Delete account',
                'delete your account',
                true,
            ),
        ];
    }

    /**
     * Returns every declared operation keyed by its own key, in administration order.
     *
     * @return array<string, StepUpOperation> Operations keyed by operation key
     * @throws InvalidArgumentException When a declared operation key is malformed
     */
    public static function all(): array
    {
        $operations = [];
        foreach (static::operations() as $operation) {
            $operations[$operation->key] = $operation;
        }

        return $operations;
    }

    /**
     * @return list<string> Declared operation keys in administration order
     * @throws InvalidArgumentException When a declared operation key is malformed
     */
    public static function keys(): array
    {
        return array_keys(static::all());
    }

    /**
     * @param string $key Candidate operation key
     * @return bool Whether the project declares the operation
     * @throws InvalidArgumentException When a declared operation key is malformed
     */
    public static function has(string $key): bool
    {
        return isset(static::all()[$key]);
    }

    /**
     * @param string $key Declared operation key
     * @return StepUpOperation Declared operation
     * @throws InvalidArgumentException When the project declares no such operation
     */
    public static function get(string $key): StepUpOperation
    {
        return static::all()[$key] ?? throw new InvalidArgumentException("Unknown step-up operation: {$key}");
    }

    /**
     * Whether a key belongs to the framework's own operation set.
     *
     * @param string $key Candidate operation key
     * @return bool Whether the base directory declares the operation
     * @throws InvalidArgumentException When a framework operation key is malformed
     */
    public static function isFramework(string $key): bool
    {
        return isset(self::operations()[$key]);
    }
}
