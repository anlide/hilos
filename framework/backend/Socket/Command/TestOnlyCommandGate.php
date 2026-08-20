<?php

declare(strict_types=1);

namespace Hilos\Socket\Command;

use Hilos\Constants\AppEnv;
use Hilos\Constants\EnvConstants;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Hilos;
use Throwable;

/**
 * The environment verdict behind every test-only refusal, CLI-side and socket-side alike.
 *
 * One verdict rather than three: before this class the CLI base ({@see TestOnlyCommand})
 * and two agents each carried their own copy, and three copies of one rule are three
 * chances for a node to be judged differently depending on which door a command came
 * through.
 */
final class TestOnlyCommandGate
{
    /**
     * Whether this node's environment admits a test-only command at all.
     *
     * Fail-closed, and for a reason worth stating: an environment that cannot be read is
     * not evidence of a test node. A catalog or type error, an unset variable, a value
     * nobody recognizes - all three answer no. The two mistakes do not cost the same. A
     * refusal on a stand costs a puzzled error line; an admission on a production node
     * hands whoever reached the port a freeze, a database reset, or real mail to a real
     * person.
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
