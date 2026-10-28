<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

/** Carries one committed public team update. */
final class StatusOutageUpdateMailTemplate extends AbstractStatusOutageMailTemplate
{
    protected const string SUBJECT = 'Update: %2$s is unavailable';
}
