<?php

declare(strict_types=1);

namespace Hilos\Auth\Throttle;

/** Opts an action payload into the shared email throttle budget. */
interface ThrottleEmailIdentityInterface
{
    /**
     * @return string Validated, normalized email address to count against
     */
    public function throttleEmail(): string;
}
