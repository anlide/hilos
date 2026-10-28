<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

/** Announces recovery with both detection and resolution moments. */
final class StatusOutageResolvedMailTemplate extends AbstractStatusOutageMailTemplate
{
    protected const string SUBJECT = 'Resolved: %2$s is answering again';
    protected const bool RESOLVED = true;
}
