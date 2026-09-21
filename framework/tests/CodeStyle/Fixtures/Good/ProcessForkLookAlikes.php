<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

/**
 * Look-alikes PROCESS-FORK has to stay silent on: the same names quoted in a string
 * or written in a comment, where they are text rather than calls; a method of an
 * object or a private helper wearing the name; and the legitimate siblings
 * pcntl_waitpid() and pcntl_signal_dispatch().
 */
final class ProcessForkLookAlikes
{
    /**
     * @param object $runner Somebody else's object, whose method wears this name
     * @return array<int, mixed> Spellings that only look like a forking call
     */
    public function lookAlikes(object $runner): array
    {
        // pcntl_fork($flags) written in a comment is a mention, not a call
        return [
            'pcntl_fork()',
            $runner->pcntl_fork(),
            self::pcntl_fork(),
        ];
    }

    /**
     * @return void
     */
    public function siblings(): void
    {
        $status = 0;
        pcntl_waitpid(-1, $status, WNOHANG);
        pcntl_signal_dispatch();
    }

    /**
     * @return int Stand-in value for the private helper
     */
    private static function pcntl_fork(): int
    {
        return 0;
    }
}
