<?php

declare(strict_types=1);

namespace Hilos\Sms\Exception;

use Hilos\Sms\Template\SmsTemplateRegistry;

/**
 * SmsTemplateNotInCatalogException - a named SMS template key is absent from the catalog (HIL-285).
 *
 * Thrown by {@see SmsTemplateRegistry::render()} when a caller names a
 * template key the active catalog does not declare. It signals a caller/config error (an
 * unknown key), not a transport failure; the raw-send intake catches it and drops the send
 * with a domain-only log (masked number and key, never the params).
 */
final class SmsTemplateNotInCatalogException extends SmsException
{
}
