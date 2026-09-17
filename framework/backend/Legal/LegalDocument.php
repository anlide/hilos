<?php

declare(strict_types=1);

namespace Hilos\Legal;

/**
 * LegalDocument - a legal document every Hilos installation may publish.
 *
 * Each document has a standard set and a version line of its own, and a project declares its
 * revisions per document: Terms and Privacy move independently. The values are stable strings,
 * since they land in URLs and in the acceptance record.
 */
enum LegalDocument: string
{
    /** Terms of use: the service and the content people put into it. */
    case TERMS = 'terms';

    /** Privacy policy: what happens to personal data. */
    case PRIVACY = 'privacy';
}
