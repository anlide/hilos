<?php

declare(strict_types=1);

namespace Hilos\Legal\Exception;

use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalSignificance;

/**
 * A revision adopting a newer set version declares a significance below that set's (HIL-497).
 *
 * The framework alone knows what a set version changed, so a project may raise the significance
 * of the revision that adopts it, never lower it.
 */
final class SignificanceLoweredException extends LegalException
{
    /**
     * @param LegalDocument $document Document of the revision
     * @param string $revisionId Revision lowering the significance
     * @param int $setVersion Set version the revision adopts
     * @param LegalSignificance $setSignificance Significance that set version declares
     */
    public function __construct(
        LegalDocument $document,
        string $revisionId,
        int $setVersion,
        LegalSignificance $setSignificance,
    ) {
        parent::__construct(
            "Revision {$revisionId} of {$document->value} adopts standard set version {$setVersion} "
            . "but declares a significance below its {$setSignificance->value} one",
        );
    }
}
