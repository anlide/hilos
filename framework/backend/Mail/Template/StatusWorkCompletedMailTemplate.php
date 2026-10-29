<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

/** Completed with its detached public announcement. */
final class StatusWorkCompletedMailTemplate extends AbstractStatusWorkMailTemplate
{
    protected const string SUBJECT = 'Completed: %s';
    protected const bool COMPLETED = true;
}
