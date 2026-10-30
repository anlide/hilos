<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\View\Item\Identity;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Metadata writes for one persisted sign-in method.
 *
 * @extends DbActions<Identity, ObjectIdentity>
 * @property-read ObjectIdentity $object
 */
class IdentityActions extends DbActions
{
    /**
     * Records an actual password change; rehashing alone does not call this action.
     *
     * @throws ItemNotFoundForUpdateException When the identity has no persisted id
     * @throws ValidationException When the identity is not a password method
     * @throws HilosException On database, truth-source or sync failure
     */
    public function recordPasswordChange(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Update);
        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('The sign-in method no longer exists.');
        }
        if ($this->object->type !== IdentityType::PASSWORD) {
            throw new ValidationException('A password change requires a password sign-in method.');
        }

        $this->object->passwordChangedAt = TimeHelper::nowMs();
        $this->object->sync();
    }

    /**
     * Keeps the last usable provider name; absent or blank input never erases it.
     *
     * @param ?string $providerName Personal name supplied by the OAuth provider
     * @throws ItemNotFoundForUpdateException When the identity has no persisted id
     * @throws ValidationException When the identity is not an OAuth method
     * @throws HilosException On database, truth-source or sync failure
     */
    public function rememberProviderName(?string $providerName): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Update);
        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('The sign-in method no longer exists.');
        }
        if ($this->object->type !== IdentityType::OAUTH) {
            throw new ValidationException('A provider name requires an OAuth sign-in method.');
        }
        if ($providerName === null) {
            return;
        }

        $providerName = trim($providerName);
        if ($providerName === '' || $providerName === $this->object->providerName) {
            return;
        }

        $this->object->providerName = $providerName;
        $this->object->sync();
    }
}
