<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

/** Maintenance changed with its detached public announcement. */
final class StatusWorkChangedMailTemplate extends AbstractStatusWorkMailTemplate
{
    protected const string SUBJECT = 'Maintenance changed: %s';
    protected const bool CHANGED = true;
}
