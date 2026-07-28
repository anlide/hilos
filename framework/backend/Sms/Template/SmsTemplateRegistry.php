<?php

declare(strict_types=1);

namespace Hilos\Sms\Template;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Sms\Exception\SmsTemplateNotInCatalogException;

/**
 * Resolves an SMS template key to its class and renders it (HIL-285).
 *
 * The SMS counterpart of {@see \Hilos\Mail\Template\MailTemplateRegistry}: it looks a key up
 * in the catalog, instantiates the declared {@see SmsTemplate}, and renders it to one text
 * line. Callers name a template by key and never touch a template class directly. The catalog
 * class is injected so a project swaps in its own {@see CatalogProviderInterface}.
 */
class SmsTemplateRegistry
{
    /** @var class-string<CatalogProviderInterface> Template catalog provider class */
    private string $catalogClass;

    /**
     * @param class-string<CatalogProviderInterface> $catalogClass Template catalog provider class
     */
    public function __construct(string $catalogClass = SmsTemplateCatalogStub::class)
    {
        $this->catalogClass = $catalogClass;
    }

    /**
     * Renders a cataloged template key into a single message line.
     *
     * @param string $key Template key, e.g. SmsTemplateCatalogConstants::AUTH_SMS_LOGIN
     * @param array<string, mixed> $params Template params (see the template's PARAM_* keys)
     * @param ?string $locale Target locale, or null for the project default (reserved for i18n)
     * @return string Rendered message text
     * @throws SmsTemplateNotInCatalogException When the key is not declared in the catalog
     */
    public function render(string $key, array $params, ?string $locale = null): string
    {
        $catalog = ($this->catalogClass)::getCatalog();
        if (!array_key_exists($key, $catalog)) {
            throw new SmsTemplateNotInCatalogException("SMS template '{$key}' is not in catalog");
        }

        /** @var class-string<SmsTemplate> $templateClass */
        $templateClass = $catalog[$key][SmsTemplateCatalogConstants::TEMPLATE_CLASS];

        return (new $templateClass())->render($params, $locale);
    }
}
