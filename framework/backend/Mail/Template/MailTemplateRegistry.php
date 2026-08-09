<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\LLM\Routing\LlmProfileCatalogStub;
use Hilos\LLM\Routing\LlmRouter;
use Hilos\Mail\EmailContent;
use Hilos\Mail\Exception\MailTemplateNotInCatalogException;
use Hilos\Mail\Exception\MailTemplateParamMissingException;

/**
 * Resolves a mail template key to its class and renders it (HIL-197).
 *
 * The policy layer over a template catalog, mirroring how {@see LlmRouter}
 * sits over {@see LlmProfileCatalogStub}: it looks a key up in the
 * catalog, instantiates the declared {@see MailTemplate}, and renders it. Callers name
 * a template by key and never touch a template class directly. The catalog class is
 * injected so a project swaps in its own {@see CatalogProviderInterface}.
 */
class MailTemplateRegistry
{
    /** @var class-string<CatalogProviderInterface> Template catalog provider class */
    private string $catalogClass;

    /**
     * @param class-string<CatalogProviderInterface> $catalogClass Template catalog provider class
     */
    public function __construct(string $catalogClass = MailTemplateCatalogStub::class)
    {
        $this->catalogClass = $catalogClass;
    }

    /**
     * Renders a cataloged template key into email content.
     *
     * @param string $key Template key, e.g. MailTemplateCatalogConstants::AUTH_REGISTER_CONFIRM
     * @param array<string, mixed> $params Template params (see the template's PARAM_* keys)
     * @param ?string $locale Target locale, or null for the project default (reserved for i18n)
     * @return EmailContent Rendered subject and bodies
     * @throws MailTemplateNotInCatalogException When the key is not declared in the catalog
     * @throws MailTemplateParamMissingException When the template needs a param the caller did not pass
     */
    public function render(string $key, array $params, ?string $locale = null): EmailContent
    {
        $catalog = ($this->catalogClass)::getCatalog();
        if (!array_key_exists($key, $catalog)) {
            throw new MailTemplateNotInCatalogException("Mail template '{$key}' is not in catalog");
        }

        /** @var class-string<MailTemplate> $templateClass */
        $templateClass = $catalog[$key][MailTemplateCatalogConstants::TEMPLATE_CLASS];

        return new $templateClass()->render($params, $locale);
    }
}
