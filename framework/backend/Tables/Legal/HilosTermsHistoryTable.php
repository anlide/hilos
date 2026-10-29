<?php

declare(strict_types=1);

namespace Hilos\Tables\Legal;

use Hilos\Legal\LegalDocument;

/** Public Terms revisions, independent of client filters. */
final class HilosTermsHistoryTable extends AbstractHilosPublicLegalHistoryTable
{
    public const string TABLE = 'hilosTermsHistory';

    protected const LegalDocument DOCUMENT = LegalDocument::TERMS;
}
