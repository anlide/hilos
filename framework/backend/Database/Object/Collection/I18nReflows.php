<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\I18nReflows as EntityI18nReflows;
use Hilos\Database\Object\Item\I18nReflow as ObjectI18nReflow;
use Hilos\Database\Object\Objects;

/**
 * The one-row record of the catalog reflow, loaded whole on first read.
 *
 * @extends Objects<ObjectI18nReflow>
 * @method ObjectI18nReflow|null offsetGet(mixed $offset)
 */
class I18nReflows extends Objects
{
    public const string OBJECT_CLASS = ObjectI18nReflow::class;
    public const string ENTITY_COLLECTION_CLASS = EntityI18nReflows::class;
    public const string COLLECTION_KEY = HilosDbContext::i18nReflows;
}
