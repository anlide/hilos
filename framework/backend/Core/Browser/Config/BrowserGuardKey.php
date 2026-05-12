<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

/**
 * Guard keys for page-level browser subscription checks.
 */
final class BrowserGuardKey
{
    public const string TYPE = 'type';
    public const string SOURCE = 'source';
    public const string KEY = 'key';
    public const string ERROR = 'error';
    public const string PERMISSION = 'permission';
    public const string SUBJECT = 'subject';
}
