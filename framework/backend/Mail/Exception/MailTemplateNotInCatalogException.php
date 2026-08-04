<?php

declare(strict_types=1);

namespace Hilos\Mail\Exception;

use Hilos\Mail\Template\MailTemplateRegistry;

/**
 * A mail template key was requested that no catalog declares (HIL-197).
 *
 * Raised by {@see MailTemplateRegistry::render()} when the key is
 * absent from the active catalog — a programming error at the call site (a template
 * key the project never registered), not a transport failure.
 */
final class MailTemplateNotInCatalogException extends MailException
{
}
