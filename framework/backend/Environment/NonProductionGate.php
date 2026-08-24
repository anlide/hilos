<?php

declare(strict_types=1);

namespace Hilos\Environment;

use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Constants\AppEnv;
use Hilos\Constants\EnvConstants;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Hilos;
use Throwable;

/**
 * The node's one verdict on whether it is a production-like installation.
 *
 * One verdict rather than one per door: the CLI base ({@see TestOnlyCommand}), the command
 * socket and the OAuth registry ({@see OAuthProviderRegistry}) all ask the same question,
 * and separate copies are separate chances for a node to be judged differently depending
 * on which door the caller came through. The class lives in the environment namespace and
 * is named after the question it answers, because a verdict named after its first caller
 * invites the second caller to write its own.
 */
final class NonProductionGate
{
    /**
     * Whether this node's environment is a known, non-production one.
     *
     * Fail-closed, and for a reason worth stating: an environment that cannot be read is
     * not evidence of a test node. A catalog or type error, an unset variable, a value
     * nobody recognizes - all three answer no. The two mistakes do not cost the same. A
     * refusal on a stand costs a puzzled error line; an admission on a production node
     * hands whoever reached the port a freeze, a database reset, real mail to a real
     * person, or a sign-in that checked nothing.
     *
     * @return bool True when APP_ENV resolves to a known, non-production-like environment
     */
    public static function admitted(): bool
    {
        try {
            $appEnv = AppEnv::fromString(Hilos::$env?->string(EnvConstants::APP_ENV));
        } catch (Throwable) {
            return false;
        }

        return $appEnv !== null && !$appEnv->isProductionLike();
    }
}
