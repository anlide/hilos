<?php

declare(strict_types=1);

namespace Hilos\Tables\Legal;

use Hilos\Legal\LegalDocument;

/** Public Privacy revisions, independent of client filters. */
final class HilosPrivacyHistoryTable extends AbstractHilosPublicLegalHistoryTable
{
    public const string TABLE = 'hilosPrivacyHistory';

    protected const LegalDocument DOCUMENT = LegalDocument::PRIVACY;
}
