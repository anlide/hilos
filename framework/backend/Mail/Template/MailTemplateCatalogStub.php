<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

use Hilos\Core\Catalog\CatalogProviderInterface;

/**
 * Framework mail template catalog.
 *
 * Ships the built-in `auth.*` verification templates and `notification.generic`,
 * each mapping a template key to the {@see MailTemplate} class that renders it. A
 * project overrides this stub the same way it overrides the settings/LLM catalogs —
 * by returning `array_replace(MailTemplateCatalogStub::getCatalog(), [...])` from its
 * own {@see CatalogProviderInterface}, to add a `notification.<type>` key or swap the
 * copy of a framework key for its own template class.
 */
final class MailTemplateCatalogStub implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Templates keyed by template key
     */
    public static function getCatalog(): array
    {
        return [
            MailTemplateCatalogConstants::AUTH_REGISTER_CONFIRM => [
                MailTemplateCatalogConstants::TEMPLATE_CLASS => RegisterConfirmMailTemplate::class,
            ],
            MailTemplateCatalogConstants::AUTH_PASSWORD_RESET => [
                MailTemplateCatalogConstants::TEMPLATE_CLASS => PasswordResetMailTemplate::class,
            ],
            MailTemplateCatalogConstants::AUTH_EMAIL_CHANGE => [
                MailTemplateCatalogConstants::TEMPLATE_CLASS => EmailChangeMailTemplate::class,
            ],
            MailTemplateCatalogConstants::AUTH_MAGIC_LINK => [
                MailTemplateCatalogConstants::TEMPLATE_CLASS => MagicLinkMailTemplate::class,
            ],
            MailTemplateCatalogConstants::AUTH_EMAIL_ADD => [
                MailTemplateCatalogConstants::TEMPLATE_CLASS => EmailAddMailTemplate::class,
            ],
            MailTemplateCatalogConstants::NOTIFICATION_GENERIC => [
                MailTemplateCatalogConstants::TEMPLATE_CLASS => GenericNotificationMailTemplate::class,
            ],
            MailTemplateCatalogConstants::PROTECTED_MODE_STUCK => [
                MailTemplateCatalogConstants::TEMPLATE_CLASS => ProtectedModeStuckMailTemplate::class,
            ],
            MailTemplateCatalogConstants::PROTECTED_MODE_CLEARED => [
                MailTemplateCatalogConstants::TEMPLATE_CLASS => ProtectedModeClearedMailTemplate::class,
            ],
        ];
    }
}
