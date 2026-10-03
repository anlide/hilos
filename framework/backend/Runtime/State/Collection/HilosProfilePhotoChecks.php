<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Runtime\State\Item\HilosProfilePhotoCheck;
use OutOfBoundsException;

/** @extends RtStates<HilosProfilePhotoCheck> */
final class HilosProfilePhotoChecks extends RtStates
{
    public const string STATE_CLASS = HilosProfilePhotoCheck::class;

    /**
     * @param ?string $key Connection accept key or null for an absent optional key
     * @return ?HilosProfilePhotoCheck Pending check or null
     */
    public function get(?string $key): ?HilosProfilePhotoCheck
    {
        /** @var ?HilosProfilePhotoCheck $state */
        $state = parent::get($key);

        return $state;
    }

    /**
     * @param mixed $offset Connection accept key
     * @return HilosProfilePhotoCheck Required pending check
     * @throws OutOfBoundsException When no check is stored under the key
     */
    public function offsetGet(mixed $offset): HilosProfilePhotoCheck
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Profile photo check not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Profile photo check not found: {$offset}");
    }
}
