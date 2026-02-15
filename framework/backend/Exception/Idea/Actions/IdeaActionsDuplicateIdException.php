<?php

namespace Hilos\Exception\Idea\Actions;

use Hilos\Exception\Database\Actions\DuplicateIdException;

/**
 * Exception: Object with this ID already exists in collection
 *
 * @deprecated Use Hilos\Exception\Database\Actions\DuplicateIdException or Hilos\Exception\Hilos\Database\Actions\DuplicateIdException instead.
 */
class IdeaActionsDuplicateIdException extends DuplicateIdException
{
}
