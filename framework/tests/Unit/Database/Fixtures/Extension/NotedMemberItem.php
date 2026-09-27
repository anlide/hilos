<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Fixtures\Extension;

use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\VerifierCircleMember;
use Hilos\HilosException;

/**
 * The view item of the test chain: the project's `note` beside the framework's fields.
 *
 * @property-read ?string $note
 */
final class NotedMemberItem extends VerifierCircleMember
{
    /**
     * @param string $name Property name (note, or any of the framework's)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            NotedMemberObject::note => $this->_object->note,
            default => parent::__get($name),
        };
    }
}
