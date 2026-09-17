<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Hilos;

/**
 * LegalCatalogProviderInterface - How a project declares the revisions of its legal documents.
 *
 * A project points {@see Hilos::legalCatalogClass()} at an implementation and declares there
 * every revision it ever published, each naming the standard set version it adopts and the
 * deviations it carries. Revisions are never removed: a person holds one, and removing it turns
 * "show me the text I agreed to" into a lie. Publishing a revision is a deploy.
 *
 * Deliberately not `Hilos\Core\Catalog\CatalogProviderInterface`, which the env, settings, LLM
 * and backup catalogs implement: that one hands over an array of shapes, and a revision - six
 * fields over a nested list of four-field deviations - is unreadable and unverifiable as one.
 * The declaration is typed objects instead.
 *
 * @see LegalCatalogStub
 * @see LegalCatalogResolver
 */
interface LegalCatalogProviderInterface
{
    /**
     * Returns the project's revisions, keyed by document value, each list in publication order.
     *
     * A project omits the key of a document it declares nothing for.
     *
     * @return array<string, list<LegalRevision>> Revisions per `LegalDocument` value
     */
    public static function revisions(): array;
}
