<?php

declare(strict_types=1);

namespace Hilos\Legal\Export;

/** Refusals and outcome notices of the export of acceptance records. */
final class LegalAcceptancesExportMessages
{
    public const string INVALID_FILTER = 'Invalid export filter';
    public const string READY = 'Your export of legal acceptances is ready.';
    public const string FAILED = 'We could not prepare the export of legal acceptances.';

    /** Who speaks on the toast, drawn above its sentence. */
    public const string TOAST_SOURCE = 'Legal';

    /** Where a click on the toast takes the administrator: the page the export is ordered and taken from. */
    public const string TOAST_DESTINATION = '/hilos/legal/acceptances';
}
