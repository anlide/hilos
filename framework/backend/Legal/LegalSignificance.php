<?php

declare(strict_types=1);

namespace Hilos\Legal;

/**
 * LegalSignificance - how much a standard set version or a document revision changed.
 *
 * The framework declares it on a set version, being the only party that knows what it changed;
 * a project declares it on a revision and may raise it above the adopted set's, never lower it.
 */
enum LegalSignificance: string
{
    /** The change alters what a person agreed to. */
    case SUBSTANTIAL = 'substantial';

    /** The change alters the wording, not what a person agreed to. */
    case EDITORIAL = 'editorial';

    /**
     * Tells whether this significance ranks below another one.
     *
     * @param self $other Significance to compare with
     * @return bool True when this one is editorial and the other substantial
     */
    public function isBelow(self $other): bool
    {
        return $this === self::EDITORIAL && $other === self::SUBSTANTIAL;
    }
}
