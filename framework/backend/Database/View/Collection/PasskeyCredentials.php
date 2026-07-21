<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\PasskeyCredentials as ObjectPasskeyCredentials;
use Hilos\Database\View\Item\PasskeyCredential;

/**
 * PasskeyCredentials Db collection.
 *
 * Read-facing representation of the framework-owned hilos_passkey_credential
 * table. The register/login ceremonies run in the demo action handlers against
 * the object-layer primitives ({@see ObjectPasskeyCredentials}); the manage
 * surface (list/remove) that consumes this read collection is HIL-404.
 *
 * @extends DbCollection<PasskeyCredential, ObjectPasskeyCredentials>
 */
final class PasskeyCredentials extends DbCollection
{
    public const string DB_ITEM_CLASS = PasskeyCredential::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectPasskeyCredentials::class;
}
