<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\View\Item\UserVerification;

/**
 * UserVerifications Db collection.
 *
 * Read-facing representation of the framework-owned hilos_user_verification
 * table. The issue/verify orchestration runs in
 * {@see \Hilos\Auth\Verification\VerificationService} against the object-layer
 * primitives ({@see ObjectUserVerifications}); no read API is exposed here
 * because the verification mechanism never surfaces to the frontend.
 *
 * @extends DbCollection<UserVerification, ObjectUserVerifications>
 */
final class UserVerifications extends DbCollection
{
    public const string DB_ITEM_CLASS = UserVerification::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUserVerifications::class;
}
