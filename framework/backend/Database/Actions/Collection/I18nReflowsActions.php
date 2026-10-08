<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Entity\Item\I18nReflow as EntityI18nReflow;
use Hilos\Database\Object\Collection\I18nReflows as ObjectI18nReflows;
use Hilos\Database\View\Collection\I18nReflows as DbCollectionI18nReflows;
use Hilos\Database\View\Item\I18nReflow;
use Hilos\HilosException;

/**
 * Write operations for the record of the catalog reflow (HIL-1472).
 *
 * The write is a collection one even when the row turns out to exist: the one row is created
 * by the first reflow of an installation and overwritten by every later one.
 *
 * @extends DbActions<I18nReflow, ObjectI18nReflows>
 * @property-read DbCollectionI18nReflows $collection
 * @property-read ObjectI18nReflows $objectCollection
 */
class I18nReflowsActions extends DbActions
{
    private const string FINGERPRINT_PATTERN = '/^[0-9a-f]{64}$/';

    /**
     * Records the fingerprint of the catalog just taken in, creating the one row or overwriting it.
     *
     * Opens no transaction: the reflow calls it as its last step inside its own.
     *
     * @param string $fingerprint Lowercase 64-character SHA-256 digest of the built-in catalog
     * @throws ValidationException When the fingerprint is not 64 lowercase hexadecimal characters
     * @throws HilosException When ownership, the database or the collection refuses the write
     */
    public function record(string $fingerprint): void
    {
        if (preg_match(self::FINGERPRINT_PATTERN, $fingerprint) !== 1) {
            throw new ValidationException('Catalog fingerprint must have 64 lowercase hexadecimal characters');
        }

        $reflow = $this->objectCollection[EntityI18nReflow::ROW_ID];
        if ($reflow === null) {
            $this->ensureCanCreate();
            $objectClass = $this->objectCollection::OBJECT_CLASS;
            $reflow = $objectClass::create();
            $reflow->id = EntityI18nReflow::ROW_ID;
            $reflow->fingerprint = $fingerprint;
            $reflow->sync();
            $this->addObjectToCollection($reflow);
            return;
        }

        $this->ensureCanWrite(TruthSourceOperation::Update);
        if ($reflow->fingerprint === $fingerprint) {
            return;
        }
        $reflow->fingerprint = $fingerprint;
        $reflow->sync();
    }
}
