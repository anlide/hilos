<?php

declare(strict_types=1);

namespace Hilos\Sms\Template;

use Hilos\Core\Catalog\CatalogProviderInterface;

/**
 * Framework SMS template catalog (HIL-285).
 *
 * Ships the built-in `auth.*` verification templates and `notification.generic`, each
 * mapping a template key to the {@see SmsTemplate} class that renders it. A project overrides
 * this stub the same way it overrides the mail/settings/LLM catalogs - by returning
 * `array_replace(SmsTemplateCatalogStub::getCatalog(), [...])` from its own
 * {@see CatalogProviderInterface}, to add a `notification.<type>` key or swap the copy of a
 * framework key for its own template class.
 */
final class SmsTemplateCatalogStub implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Templates keyed by template key
     */
    public static function getCatalog(): array
    {
        return [
            SmsTemplateCatalogConstants::AUTH_SMS_LOGIN => [
                SmsTemplateCatalogConstants::TEMPLATE_CLASS => SmsVerificationCodeTemplate::class,
            ],
            SmsTemplateCatalogConstants::AUTH_SMS_ADD => [
                SmsTemplateCatalogConstants::TEMPLATE_CLASS => SmsVerificationCodeTemplate::class,
            ],
            SmsTemplateCatalogConstants::NOTIFICATION_GENERIC => [
                SmsTemplateCatalogConstants::TEMPLATE_CLASS => GenericNotificationSmsTemplate::class,
            ],
        ];
    }
}
