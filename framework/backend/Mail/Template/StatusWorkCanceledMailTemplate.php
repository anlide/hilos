<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

/** Maintenance canceled with its detached public announcement. */
final class StatusWorkCanceledMailTemplate extends AbstractStatusWorkMailTemplate
{
    protected const string SUBJECT = 'Maintenance canceled: %s';
}
